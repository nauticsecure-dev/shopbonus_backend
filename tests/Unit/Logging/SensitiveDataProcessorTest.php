<?php

namespace Tests\Unit\Logging;

use App\Logging\SensitiveDataProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class SensitiveDataProcessorTest extends TestCase
{
    private function process(string $message, array $context = []): LogRecord
    {
        $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, $message, $context);

        return (new SensitiveDataProcessor)($record);
    }

    public function test_masks_sensitive_context_keys_recursively(): void
    {
        $record = $this->process('Order received', [
            'password' => 'hunter2',
            'customer' => [
                'email' => 'jan@example.nl',
                'phone_number' => '+31612345678',
                'shipping_address' => 'Damrak 1',
                'name_initial' => 'J',
            ],
            'provider' => ['api_key' => 'sk_live_x', 'webhook-signature' => 'abc'],
            'voucher' => ['code' => 'SB-ABCD-1234', 'offer_id' => 7],
            'order_id' => 'ext-1001',
        ]);

        $this->assertSame('[REDACTED]', $record->context['password']);
        $this->assertSame('[REDACTED]', $record->context['customer']['email']);
        $this->assertSame('[REDACTED]', $record->context['customer']['phone_number']);
        $this->assertSame('[REDACTED]', $record->context['customer']['shipping_address']);
        $this->assertSame('[REDACTED]', $record->context['provider']['api_key']);
        $this->assertSame('[REDACTED]', $record->context['provider']['webhook-signature']);
        $this->assertSame('[REDACTED]', $record->context['voucher']['code']);
        $this->assertSame('J', $record->context['customer']['name_initial']);
        $this->assertSame(7, $record->context['voucher']['offer_id']);
        $this->assertSame('ext-1001', $record->context['order_id']);
    }

    public function test_short_keys_only_match_exactly(): void
    {
        $record = $this->process('x', ['pin' => '1234', 'shipping' => 'standard', 'otp' => '998877', 'hotpath' => 'y']);

        $this->assertSame('[REDACTED]', $record->context['pin']);
        $this->assertSame('[REDACTED]', $record->context['otp']);
        $this->assertSame('standard', $record->context['shipping']);
        $this->assertSame('y', $record->context['hotpath']);
    }

    public function test_masks_emails_tokens_and_ibans_in_free_text(): void
    {
        $record = $this->process(
            'Login failed for jan@example.nl using Bearer 12|abcDEF.ghi payout NL91ABNA0417164300',
            ['note' => 'contact piet@shop.nl'],
        );

        $this->assertStringNotContainsString('jan@example.nl', $record->message);
        $this->assertStringNotContainsString('abcDEF', $record->message);
        $this->assertStringNotContainsString('NL91ABNA0417164300', $record->message);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $record->message);
        $this->assertSame('contact [REDACTED_EMAIL]', $record->context['note']);
    }
}
