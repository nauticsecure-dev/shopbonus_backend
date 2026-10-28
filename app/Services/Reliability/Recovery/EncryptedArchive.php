<?php

namespace App\Services\Reliability\Recovery;

use RuntimeException;

/** Versioned authenticated secretstream; final tag and EOF are mandatory. */
class EncryptedArchive
{
    private const MAGIC = "SHOPBONUS-BACKUP-1\n";

    public function encrypt(string $input, string $output, #[\SensitiveParameter] string $key): void
    {
        $in = fopen($input, 'rb');
        $out = fopen($output, 'xb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open backup streams.');
        }
        chmod($output, 0600);
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->write($out, self::MAGIC.$header);
            while (! feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot read archive.');
                }
                if ($chunk !== '') {
                    $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk);
                    $this->write($out, pack('N', strlen($encrypted)).$encrypted);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->write($out, pack('N', strlen($final)).$final);
            sodium_memzero($state);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decrypt(string $input, string $output, #[\SensitiveParameter] string $key, int $maxBytes): void
    {
        $in = fopen($input, 'rb');
        $out = fopen($output, 'xb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open backup streams.');
        }
        chmod($output, 0600);
        try {
            if ($this->read($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Invalid backup format.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($this->read($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            $total = 0;
            do {
                $length = unpack('Nlength', $this->read($in, 4))['length'];
                if ($length < 17 || $length > 65553) {
                    throw new RuntimeException('Invalid encrypted frame length.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->read($in, $length));
                if ($result === false) {
                    throw new RuntimeException('Backup authentication failed.');
                }
                [$plain, $tag] = $result;
                $total += strlen($plain);
                if ($total > $maxBytes) {
                    throw new RuntimeException('Backup exceeds restore size limit.');
                }
                $this->write($out, $plain);
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (fread($in, 1) !== '') {
                throw new RuntimeException('Trailing backup data.');
            }
            sodium_memzero($state);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** @param resource $stream */
    public function read($stream, int $size): string
    {
        $result = '';
        while (strlen($result) < $size) {
            $part = fread($stream, $size - strlen($result));
            if ($part === false || $part === '') {
                throw new RuntimeException('Truncated backup.');
            }
            $result .= $part;
        }

        return $result;
    }

    /** @param resource $stream */
    public function write($stream, #[\SensitiveParameter] string $data): void
    {
        while ($data !== '') {
            $written = fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write backup.');
            }
            $data = substr($data, $written);
        }
    }
}
