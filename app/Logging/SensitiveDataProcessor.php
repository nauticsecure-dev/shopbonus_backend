<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks credentials, payment details and customer contact data before a log
 * record is written.
 *
 * Context/extra keys matching a sensitive name are replaced wholesale; free
 * text in the message and in string values has e-mail addresses, bearer
 * tokens and IBAN-like strings masked.
 */
class SensitiveDataProcessor implements ProcessorInterface
{
    public const MASK = '[REDACTED]';

    /**
     * Key fragments matched anywhere in the normalized key (lower-case, with
     * "_", "-" and spaces removed).
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'apikey',
        'accesskey', 'privatekey', 'credential', 'cookie', 'signature',
        'cardnumber', 'iban', 'bankaccount', 'email', 'phone', 'mobile',
        'telephone', 'address', 'postcode', 'zipcode', 'dateofbirth',
        'birthdate',
    ];

    /** Short keys only masked on an exact (normalized) match, e.g. "pin" but not "shipping". */
    private const SENSITIVE_EXACT_KEYS = ['otp', 'pin', 'cvc', 'cvv', 'bic', 'bsn', 'ip', 'code'];

    /** @var array<string, string> pattern => replacement */
    private const TEXT_PATTERNS = [
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[REDACTED_EMAIL]',
        '/\bBearer\s+[A-Za-z0-9._~+\/=|\-]+/i' => 'Bearer [REDACTED]',
        '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]){11,30}\b/' => '[REDACTED_IBAN]',
    ];

    private const MAX_DEPTH = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactText($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;

                continue;
            }

            $data[$key] = $this->redactValue($value, $depth);
        }

        return $data;
    }

    public function redactText(string $text): string
    {
        return (string) preg_replace(array_keys(self::TEXT_PATTERNS), array_values(self::TEXT_PATTERNS), $text);
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return is_array($value) || is_object($value) ? self::MASK : $value;
        }

        return match (true) {
            is_string($value) => $this->redactText($value),
            is_array($value) => $this->redactArray($value, $depth + 1),
            default => $value,
        };
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = str_replace(['_', '-', ' '], '', strtolower($key));

        if (in_array($normalized, self::SENSITIVE_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
