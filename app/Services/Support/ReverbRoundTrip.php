<?php

namespace App\Services\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use RuntimeException;

/** Bounded synthetic private-channel subscriber, using the deployed broadcaster. */
class ReverbRoundTrip
{
    public function check(): void
    {
        $config = config('broadcasting.connections.reverb');
        $options = $config['options'] ?? [];
        $host = $options['host'] ?? '';
        $port = (int) ($options['port'] ?? 443);
        if (! $host || ! ($config['key'] ?? null) || ! ($config['secret'] ?? null) || ! ($config['app_id'] ?? null)
            || ! preg_match('/\A[a-zA-Z0-9.\-\[\]:]+\z/D', $host) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Reverb configuration unavailable.');
        }
        $tls = ($options['scheme'] ?? 'https') === 'https';
        $deadline = microtime(true) + 5;
        $socket = @stream_socket_client(($tls ? 'tls' : 'tcp').'://'.$host.':'.$port, $errno, $error, 1,
            STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if ($socket === false) {
            throw new RuntimeException('Reverb connection failed.');
        }
        try {
            stream_set_timeout($socket, 1);
            $negotiator = new ClientNegotiator(new HttpFactory);
            $prefix = '/'.trim((string) ($options['path'] ?? ''), '/');
            $request = $negotiator->generateRequest(new Uri(($tls ? 'https' : 'http').'://'.$host.':'.$port.rtrim($prefix, '/').'/app/'.rawurlencode($config['key']).'?protocol=7&client=shopbonus-probe&version=1'))
                ->withHeader('Origin', (string) (config('support_ai.reverb_probe_origin') ?: config('app.url')));
            $this->write($socket, Message::toString($request));
            $headers = '';
            while (! str_contains($headers, "\r\n\r\n")) {
                $headers .= $this->read($socket, $deadline, 1);
                if (strlen($headers) > 16384) {
                    throw new RuntimeException('Invalid upgrade response.');
                }
            }
            if (! $negotiator->validateResponse($request, Message::parseResponse($headers))) {
                throw new RuntimeException('WebSocket upgrade rejected.');
            }
            $messages = [];
            $buffer = new MessageBuffer(new CloseFrameChecker, function ($message) use (&$messages) {
                $messages[] = json_decode((string) $message, true, 32, JSON_THROW_ON_ERROR);
            }, function ($frame) use ($socket) {
                if ($frame->getOpcode() === Frame::OP_PING) {
                    $pong = new Frame($frame->getPayload(), true, Frame::OP_PONG);
                    $pong->maskPayload();
                    $this->write($socket, $pong->getContents());
                } elseif ($frame->getOpcode() === Frame::OP_CLOSE) {
                    throw new RuntimeException('WebSocket closed.');
                }
            }, false, maxMessagePayloadSize: 65536, maxFramePayloadSize: 65536);
            $next = function (string $event) use (&$messages, $buffer, $socket, $deadline): array {
                while (true) {
                    while ($message = array_shift($messages)) {
                        if (($message['event'] ?? '') === 'pusher:error') {
                            throw new RuntimeException('Reverb protocol rejected.');
                        }
                        if (($message['event'] ?? '') === $event) {
                            return $message;
                        }
                    }
                    $buffer->onData($this->read($socket, $deadline));
                }
            };
            $connection = json_decode($next('pusher:connection_established')['data'], true, 32, JSON_THROW_ON_ERROR);
            $nonce = bin2hex(random_bytes(16));
            $channel = 'private-support-connectivity-'.$nonce;
            $auth = $config['key'].':'.hash_hmac('sha256', $connection['socket_id'].':'.$channel, $config['secret']);
            $frame = new Frame(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth]], JSON_THROW_ON_ERROR));
            $frame->maskPayload();
            $this->write($socket, $frame->getContents());
            $subscribed = $next('pusher_internal:subscription_succeeded');
            if (($subscribed['channel'] ?? '') !== $channel) {
                throw new RuntimeException('Unexpected subscription.');
            }
            // Resolve a fresh bounded connection, without mutating the application's broadcaster.
            $config['client_options'] = ['timeout' => 1, 'connect_timeout' => 1];
            $broadcaster = new PusherBroadcaster(Broadcast::pusher($config));
            $broadcaster->broadcast([$channel], 'support.connectivity.probe', ['nonce' => $nonce]);
            $received = $next('support.connectivity.probe');
            $payload = is_string($received['data'] ?? null) ? json_decode($received['data'], true, 32, JSON_THROW_ON_ERROR) : $received['data'];
            if (($received['channel'] ?? '') !== $channel || ($payload['nonce'] ?? '') !== $nonce) {
                throw new RuntimeException('Round trip did not match.');
            }
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function read($socket, float $deadline, int $length = 8192): string
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Reverb probe deadline exceeded.');
        }
        $remaining = min(1, $remaining);
        stream_set_timeout($socket, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
        $data = fread($socket, $length);
        if ($data === false || $data === '' || feof($socket)) {
            throw new RuntimeException('Reverb probe read failed.');
        }

        return $data;
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Reverb probe write failed.');
            }
            $data = substr($data, $written);
        }
    }
}
