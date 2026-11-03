<?php

/** Shared by the standalone merchant packages; no Laravel or merchant secrets in archives. */
final class ShopbonusDelivery
{
    public static function origin(string $value): ?string
    {
        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || ! preg_match('/\A[a-z0-9.-]+\.[a-z]{2,}\z/D', $parts['host'] ?? '') || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return 'https://'.$parts['host'];
    }

    public static function endpoint(string $url, string $platform): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && self::origin('https://'.($parts['host'] ?? '')) !== null
            && ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['port']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && preg_match('~\A/api/v1/webhooks/'.preg_quote($platform, '~').'/[A-Za-z0-9]{40}\z~D', $parts['path'] ?? '') === 1;
    }

    public static function headers(string $body, string $secret, string $eventId, int $time): array
    {
        return ['Content-Type: application/json', 'X-Shopbonus-Topic: order.updated', 'X-Shopbonus-Event-Id: '.$eventId,
            'X-Shopbonus-Signature: t='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret)];
    }

    public static function send(string $url, string $platform, string $secret, string $eventId, array $payload): bool
    {
        if (! self::endpoint($url, $platform) || strlen($secret) < 32 || ! preg_match('/\A[a-f0-9]{32}\z/D', $eventId) || ! extension_loaded('curl')) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || (str_contains($ip, ':') && ! preg_match('/\A[23]/', $ip))
                || (! str_contains($ip, ':') && (ip2long($ip) & 0xFFC00000) === ip2long('100.64.0.0'))) {
                return false;
            }
            $addresses[] = $ip;
        }
        if (! $addresses) {
            return false;
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => self::headers($body, $secret, $eventId, time()),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_RESOLVE => [$host.':443:'.$ip],
            CURLOPT_WRITEFUNCTION => static fn ($handle, $data) => strlen($data)]);
        $ok = curl_exec($curl) !== false && in_array(curl_getinfo($curl, CURLINFO_HTTP_CODE), [200, 202], true);
        curl_close($curl);

        return $ok;
    }
}
