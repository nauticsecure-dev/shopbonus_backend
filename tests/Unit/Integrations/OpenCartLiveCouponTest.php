<?php

namespace Tests\Unit\Integrations;

use PDO;
use PHPUnit\Framework\TestCase;

class OpenCartLiveCouponTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3).'/merchant-packages/opencart4/system/library/CouponRead.php';
    }

    public function test_authentication_binds_time_nonce_code_and_current_secret(): void
    {
        $secret = str_repeat('s', 48);
        $nonce = str_repeat('a', 64);
        $time = '1794070800';
        $signature = hash_hmac('sha256', 'shopbonus.opencart.coupon.v1.request|'.$time.'|'.$nonce.'|SYNTHETIC-10', $secret);
        $this->assertTrue(\ShopbonusOpenCartCouponRead::authorize($secret, 'SYNTHETIC-10', $time, $nonce, $signature, (int) $time));
        foreach ([[$secret, 'other', $time, $nonce, $signature, (int) $time], [$secret, 'SYNTHETIC-10', $time, $nonce, $signature, (int) $time + 61],
            [str_repeat('r', 48), 'SYNTHETIC-10', $time, $nonce, $signature, (int) $time], [$secret, 'SYNTHETIC-10', $time, str_repeat('b', 64), $signature, (int) $time]] as $args) {
            $this->assertFalse(\ShopbonusOpenCartCouponRead::authorize(...$args));
        }
    }

    public function test_native_tables_supply_actual_usage_restrictions_and_timezone_dates(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE oc_coupon (coupon_id INTEGER, code TEXT, type TEXT, discount TEXT, total TEXT, logged INTEGER, shipping INTEGER, uses_total INTEGER, uses_customer INTEGER, status INTEGER, date_start TEXT, date_end TEXT)');
        $pdo->exec('CREATE TABLE oc_coupon_history (coupon_id INTEGER)');
        $pdo->exec('CREATE TABLE oc_coupon_product (coupon_id INTEGER, product_id INTEGER)');
        $pdo->exec('CREATE TABLE oc_coupon_category (coupon_id INTEGER, category_id INTEGER)');
        $pdo->exec("INSERT INTO oc_coupon VALUES (77,'SYNTHETIC-10','P','10.0000','100.0000',0,0,1,0,1,'2026-10-01','2026-12-31')");
        $pdo->exec('INSERT INTO oc_coupon_history VALUES (77)');
        $pdo->exec('INSERT INTO oc_coupon_product VALUES (77,42)');
        $db = new class($pdo)
        {
            public function __construct(private PDO $pdo) {}

            public function escape(string $value): string
            {
                return substr($this->pdo->quote($value), 1, -1);
            }

            public function query(string $sql): object
            {
                $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

                return (object) ['rows' => $rows, 'row' => $rows[0] ?? []];
            }
        };
        $coupon = \ShopbonusOpenCartCouponRead::coupon($db, 'oc_', 'SYNTHETIC-10', 'Europe/Amsterdam');
        $this->assertSame(1, $coupon['usage_count']);
        $this->assertSame([42], $coupon['product_ids']);
        $this->assertSame([], $coupon['category_ids']);
        $this->assertSame('10.0000', $coupon['discount']);
        $this->assertSame('2026-10-01T00:00:00+02:00', $coupon['starts_at']);
        $this->assertSame('2026-12-31T00:00:00+01:00', $coupon['expires_at']);
        $this->assertNull(\ShopbonusOpenCartCouponRead::coupon($db, 'oc_', "x' OR '1'='1", 'Europe/Amsterdam'));
        $pdo->exec('INSERT INTO oc_coupon SELECT * FROM oc_coupon');
        $this->expectException(\RuntimeException::class);
        \ShopbonusOpenCartCouponRead::coupon($db, 'oc_', 'SYNTHETIC-10', 'Europe/Amsterdam');
    }
}
