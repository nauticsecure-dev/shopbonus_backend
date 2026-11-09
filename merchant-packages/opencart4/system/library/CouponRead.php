<?php

require_once __DIR__.'/OrderPayload.php';

/** Private live OpenCart 4.1 coupon reads. Never used by a checkout event or public banner. */
final class ShopbonusOpenCartCouponRead
{
    public static function authorize(string $secret, string $code, string $timestamp, string $nonce, string $signature, int $now): bool
    {
        return strlen($secret) >= 32 && strlen($code) > 0 && strlen($code) <= 20
            && preg_match('/\A[0-9]{10}\z/D', $timestamp) && abs($now - (int) $timestamp) <= 60
            && preg_match('/\A[a-f0-9]{64}\z/D', $nonce) && preg_match('/\A[a-f0-9]{64}\z/D', $signature)
            && hash_equals(hash_hmac('sha256', 'shopbonus.opencart.coupon.v1.request|'.$timestamp.'|'.$nonce.'|'.$code, $secret), $signature);
    }

    /** Uses the same coupon/history/restriction tables as the native marketing/coupon model. */
    public static function coupon(object $db, string $prefix, string $code, string $timezone): ?array
    {
        if (! preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Invalid module context.');
        }
        $rows = $db->query('SELECT coupon_id,code,type,discount,total,logged,shipping,uses_total,uses_customer,status,date_start,date_end FROM `'.$prefix."coupon` WHERE code='".$db->escape($code)."' LIMIT 2")->rows;
        if (count($rows) > 1) {
            throw new RuntimeException('Ambiguous coupon.');
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $id = (int) $row['coupon_id'];
        $history = $db->query('SELECT COUNT(*) AS uses_count FROM `'.$prefix.'coupon_history` WHERE coupon_id='.$id)->row;
        $products = $db->query('SELECT product_id FROM `'.$prefix.'coupon_product` WHERE coupon_id='.$id.' LIMIT 1')->rows;
        $categories = $db->query('SELECT category_id FROM `'.$prefix.'coupon_category` WHERE coupon_id='.$id.' LIMIT 1')->rows;
        $coupon = ['coupon_id' => $id, 'code' => (string) $row['code'], 'type' => (string) $row['type'], 'discount' => (string) $row['discount'], 'total' => (string) $row['total'],
            'product_ids' => array_map(fn ($r) => (int) $r['product_id'], $products), 'category_ids' => array_map(fn ($r) => (int) $r['category_id'], $categories),
            'usage_count' => (int) $history['uses_count']];
        foreach (['logged', 'shipping', 'uses_total', 'uses_customer', 'status'] as $field) {
            $coupon[$field] = (int) $row[$field];
        }
        foreach (['date_start' => 'starts_at', 'date_end' => 'expires_at'] as $field => $target) {
            $value = (string) $row[$field];
            if ($value === '0000-00-00') {
                $coupon[$target] = null;

                continue;
            }
            $utc = ShopbonusOpenCartOrder::date($value.' 00:00:00', $timezone);
            if ($utc === null) {
                throw new RuntimeException('Invalid native coupon date.');
            }
            $coupon[$target] = (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone($timezone))->format(DATE_ATOM);
        }

        return $coupon;
    }
}
