<?php

namespace App\Services\Support;

/** Conservative bilingual intent research; model/user cannot choose identity, scope or required tools. */
final class SupportPlanner
{
    /** Internal tool intents (immutable for regressions / reviews). */
    public const INTENTS = ['wallet', 'reward', 'release_date', 'vouchers', 'quote', 'funding', 'offer_cost', 'campaign', 'integration', 'knowledge', 'escalate', 'unknown'];

    /**
     * Product-facing categories mapped from internal intents.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'wallet' => 'WALLET_BALANCE',
        'reward' => 'MISSING_REWARD',
        'release_date' => 'REWARD_STATUS',
        'vouchers' => 'VOUCHER_AVAILABILITY',
        'quote' => 'VOUCHER_REDEMPTION',
        'funding' => 'WORKSPACE_WALLET',
        'offer_cost' => 'MERCHANT_OFFER_COST',
        'campaign' => 'CAMPAIGN_PERFORMANCE',
        'integration' => 'INTEGRATION_PROBLEM',
        'knowledge' => 'ACCOUNT_SUPPORT',
        'escalate' => 'HUMAN_SUPPORT',
        'unknown' => 'OUT_OF_SCOPE',
    ];

    /**
     * A public ULID, or else a webshop order reference as customers write it:
     * "FS-20482", "#1042", "bestelling 1042", "order nr. AB12345". Order
     * references keep their case; ownership is still checked by the tool.
     */
    public static function reference(string $text): ?string
    {
        if (preg_match('/\b[0-9a-hjkmnp-tv-z]{26}\b/i', $text, $m) === 1) {
            return strtolower($m[0]);
        }
        $patterns = [
            '/\b(?:order|bestelling|ordernummer|bestelnummer|order\s*number|referentie|reference)\s*(?:nr\.?|nummer|number|no\.?)?\s*[:#]?\s*#?([A-Za-z0-9][A-Za-z0-9-]{2,39})\b/iu',
            '/#([A-Za-z0-9][A-Za-z0-9-]{2,39})\b/u',
            '/\b([A-Z]{1,6}-\d{3,20})\b/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1 && preg_match('/\d/', $m[1]) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function plan(string $question, bool $workspace, ?string $reference = null): array
    {
        $q = mb_strtolower($question);
        $reference ??= self::reference($question);
        $wantsHuman = preg_match('/\b(human|medewerker|menselijk|iemand\s+spreken|contact\s+support|support\s+ticket|praat\s+met)\b/u', $q) === 1;
        $complaint = preg_match('/complaint|klacht|belachelijk|schandalig|niet\s+klopt|geld\s+is\s+weg/u', $q) === 1;
        // Do not let a balance answer imply a refund, guaranteed date, or completed action.
        $intent = match (true) {
            preg_match('/refund|terugbetal|privacy|verwijder|gdpr|avg\b/u', $q) === 1 || $wantsHuman => 'escalate',
            preg_match('/when|wanneer|release date|vrijgavedatum|tomorrow|morgen/u', $q) === 1 && preg_match('/reward|beloning|credit|tegoed|pending|gereserveerd|tientje/u', $q) === 1 => 'release_date',
            preg_match('/reward|beloning|pending|tientje|waar\s+blijft|where.*(?:€|euro|reward|beloning)|waar.*(?:€|euro|beloning|tientje)/u', $q) === 1 => 'reward',
            preg_match('/quote|buy.*voucher|purchase|prijsopgave|voucher.*kopen|mollie|shortfall|bijbetalen|\binwisselen\b|\bredeem\b(?!ed)/u', $q) === 1 => 'quote',
            preg_match('/campaign|campagne|impression|click|klik|sales|order starts|verkoop|prestat/u', $q) === 1 => 'campaign',
            preg_match('/integrat|sync|connection|koppeling|verbinding|shopify|woocommerce|magento/u', $q) === 1 => 'integration',
            preg_match('/fee|cost|kosten|30%|offer cost|aanbieding.*kost|kost.*aanbieding/u', $q) === 1 => 'offer_cost',
            preg_match('/merchant funding|funding|financier|webshop.*(?:wallet|saldo)/u', $q) === 1 => 'funding',
            preg_match('/voucher|coupon|kortingscode|vervald/u', $q) === 1 => 'vouchers',
            preg_match('/wallet|balance|saldo|points|punten|tegoed|credit|hoeveel\s+(?:heb|hebben)|geld\s+is\s+weg|niet\s+klopt/u', $q) === 1 => $workspace ? 'funding' : 'wallet',
            preg_match('/faq|knowledge|help article|helptekst|kennis|\bhow\b|\bhoe\b|account/u', $q) === 1 => 'knowledge',
            $complaint => 'escalate',
            default => 'unknown',
        };
        $unit = preg_match('/points|punten/u', $q) === 1 ? 'points' : 'eur_credit';
        [$tool, $arg] = match ($intent) {
            'wallet' => ['get_my_wallet', ['unit' => $unit]],
            'reward' => ['get_my_wallet', ['unit' => $unit]],
            'vouchers' => $reference === null ? ['get_my_vouchers', []] : ['get_my_voucher', ['voucher_id' => $reference]],
            'quote' => ['get_voucher_quote', ['offer_id' => $reference]],
            'funding' => ['get_workspace_wallet', []],
            'offer_cost' => ['get_offer_cost', ['offer_id' => $reference]],
            'campaign' => ['get_campaign_performance', ['campaign_id' => $reference]],
            'integration' => ['get_integration_health', ['connection_id' => $reference]],
            'knowledge' => ['get_approved_knowledge', ['document_id' => $reference]],
            default => [null, []],
        };
        $wrongScope = ($workspace && in_array($intent, ['wallet', 'reward', 'vouchers', 'quote'], true))
            || (! $workspace && in_array($intent, ['funding', 'offer_cost', 'campaign', 'integration'], true));
        // Reward overview uses wallet evidence; order-level retry only when a reference is present.
        $missing = in_array(null, $arg, true);

        $category = self::CATEGORIES[$intent];
        if ($intent === 'vouchers' && preg_match('/vervald|expir|verlopen/u', $q) === 1) {
            $category = 'VOUCHER_EXPIRY';
        }
        if ($intent === 'reward' && preg_match('/status|stand\s+van|beschikbaar/u', $q) === 1) {
            $category = 'REWARD_STATUS';
        }
        if ($intent === 'escalate' && preg_match('/klacht|complaint|belachelijk/u', $q) === 1) {
            $category = 'COMPLAINT';
        }
        if ($intent === 'knowledge' && preg_match('/account|profiel|inlog/u', $q) === 1) {
            $category = 'ACCOUNT_SUPPORT';
        }

        return ['intent' => $intent, 'category' => $category, 'tool' => $tool, 'arguments' => $arg, 'reference' => $reference,
            'unit' => $unit, 'missing_reference' => $missing, 'wrong_scope' => $wrongScope,
            'sentiment' => $complaint ? 'frustrated' : 'neutral'];
    }
}
