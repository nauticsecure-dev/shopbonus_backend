<?php

/**
 * Builds ShopBonus catalog snapshot pages (schema shopbonus.catalog.v1) from the default store's
 * OpenCart 4.1 tables (system/helper/db_schema.php): product, product_description, product_to_store,
 * coupon, coupon_product, coupon_category. Keyset pages of at most 100 rows; nothing is exposed on the shop.
 */
final class ShopbonusOpenCartCatalog
{
    public const PAGE = 100;

    public const MAX_PAGES = 500;

    /** Base (default) currency and default storefront language id, from the store-0 settings. */
    public static function context(mysqli $db, string $prefix): ?array
    {
        $settings = [];
        foreach ($db->query('SELECT `key`,`value` FROM `'.$prefix."setting` WHERE store_id=0 AND code='config' AND `key` IN ('config_currency','config_language')") as $row) {
            $settings[$row['key']] = $row['value'];
        }
        $currency = (string) ($settings['config_currency'] ?? '');
        $code = (string) ($settings['config_language'] ?? '');
        if (! preg_match('/\A[A-Z]{3}\z/D', $currency) || ! preg_match('/\A[a-z]{2}(?:-[a-z]{2})?\z/D', $code)) {
            return null;
        }
        $language = $db->query('SELECT language_id FROM `'.$prefix."language` WHERE code='".$db->real_escape_string($code)."'")->fetch_assoc();

        return $language ? ['currency' => $currency, 'language_id' => (int) $language['language_id']] : null;
    }

    /** One page of products after $after; rows without a name in the default language are skipped. */
    public static function products(mysqli $db, string $prefix, int $languageId, int $after, string $timezone): array
    {
        $rows = [];
        $result = $db->query('SELECT p.product_id,p.model,p.sku,p.price,p.quantity,p.status,p.date_modified,pd.name,pd.description FROM `'.$prefix.'product` p'
            .' JOIN `'.$prefix.'product_to_store` ps ON ps.product_id=p.product_id AND ps.store_id=0'
            .' LEFT JOIN `'.$prefix.'product_description` pd ON pd.product_id=p.product_id AND pd.language_id='.$languageId
            .' WHERE p.product_id>'.$after.' ORDER BY p.product_id LIMIT '.(self::PAGE + 1));
        foreach ($result as $row) {
            $rows[] = $row;
        }
        $items = [];
        foreach (array_slice($rows, 0, self::PAGE) as $row) {
            if (trim((string) $row['name']) === '') {
                continue;
            }
            $items[] = ['product_id' => (int) $row['product_id'], 'model' => (string) $row['model'], 'sku' => (string) $row['sku'], 'name' => (string) $row['name'],
                'description' => mb_substr(html_entity_decode((string) $row['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 4000),
                'price' => (string) $row['price'], 'quantity' => (int) $row['quantity'], 'status' => (int) $row['status'],
                'date_modified' => self::iso((string) $row['date_modified'], $timezone)];
        }

        return ['items' => $items, 'cursor' => $rows === [] ? null : (int) $rows[min(count($rows), self::PAGE) - 1]['product_id'], 'more' => count($rows) > self::PAGE];
    }

    /** One page of coupons after $after, with their product and category restrictions. */
    public static function coupons(mysqli $db, string $prefix, int $after, string $timezone): array
    {
        $rows = [];
        foreach ($db->query('SELECT coupon_id,name,code,type,discount,logged,shipping,total,date_end,uses_total,uses_customer,status FROM `'.$prefix.'coupon`'
            .' WHERE coupon_id>'.$after.' ORDER BY coupon_id LIMIT '.(self::PAGE + 1)) as $row) {
            $rows[] = $row;
        }
        $page = array_slice($rows, 0, self::PAGE);
        $ids = array_map(fn ($row) => (int) $row['coupon_id'], $page);
        $products = $categories = [];
        if ($ids !== []) {
            foreach ($db->query('SELECT coupon_id,product_id FROM `'.$prefix.'coupon_product` WHERE coupon_id IN ('.implode(',', $ids).')') as $r) {
                $products[(int) $r['coupon_id']][] = (int) $r['product_id'];
            }
            foreach ($db->query('SELECT coupon_id,category_id FROM `'.$prefix.'coupon_category` WHERE coupon_id IN ('.implode(',', $ids).')') as $r) {
                $categories[(int) $r['coupon_id']][] = (int) $r['category_id'];
            }
        }
        $items = [];
        foreach ($page as $row) {
            $id = (int) $row['coupon_id'];
            $end = (string) $row['date_end'];
            $items[] = ['coupon_id' => $id, 'name' => (string) $row['name'], 'code' => (string) $row['code'], 'type' => (string) $row['type'],
                'discount' => (string) $row['discount'], 'logged' => (int) $row['logged'], 'shipping' => (int) $row['shipping'], 'total' => (string) $row['total'],
                'uses_total' => (int) $row['uses_total'], 'uses_customer' => (int) $row['uses_customer'], 'status' => (int) $row['status'],
                // OpenCart stops honouring a coupon at midnight starting date_end (catalog/model/marketing/coupon.php).
                'expires_at' => preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $end) && $end !== '0000-00-00' ? self::iso($end.' 00:00:00', $timezone) : null,
                'product_ids' => $products[$id] ?? [], 'category_ids' => $categories[$id] ?? []];
        }

        return ['items' => $items, 'cursor' => $page === [] ? null : (int) end($page)['coupon_id'], 'more' => count($rows) > self::PAGE];
    }

    private static function iso(string $value, string $timezone): ?string
    {
        $utc = ShopbonusOpenCartOrder::date($value, $timezone);

        return $utc === null ? null : (new DateTimeImmutable($utc))->format(DATE_ATOM);
    }
}
