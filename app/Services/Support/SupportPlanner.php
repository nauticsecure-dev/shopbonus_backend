<?php

namespace App\Services\Support;

/** Conservative bilingual intent research; model/user cannot choose identity, scope or required tools. */
final class SupportPlanner
{
    public const INTENTS = ['wallet', 'reward', 'release_date', 'vouchers', 'quote', 'funding', 'offer_cost', 'campaign', 'integration', 'knowledge', 'escalate', 'unknown'];

    /** @return array<string, mixed> */
    public function plan(string $question, bool $workspace, ?string $reference = null): array
    {
        $q = mb_strtolower($question);
        $reference ??= preg_match('/\b[0-9a-hjkmnp-tv-z]{26}\b/i', $question, $m) ? strtolower($m[0]) : null;
        // Do not let a balance answer imply a refund, guaranteed date, or completed action.
        $intent = match (true) {
            preg_match('/refund|terugbetal|complaint|klacht|privacy|verwijder|gdpr|avg\b|human|medewerker|contact support|support ticket|menselijk/u', $q) === 1 => 'escalate',
            preg_match('/when|wanneer|release date|vrijgavedatum|tomorrow|morgen/u', $q) === 1 && preg_match('/reward|beloning|credit|tegoed|pending|gereserveerd/u', $q) === 1 => 'release_date',
            preg_match('/reward|beloning|pending|where.*(?:€|euro)|waar.*(?:€|euro)/u', $q) === 1 => 'reward',
            preg_match('/quote|buy.*voucher|purchase|prijsopgave|voucher.*kopen|mollie|shortfall|bijbetalen/u', $q) === 1 => 'quote',
            preg_match('/campaign|campagne|impression|click|klik|sales|order starts|verkoop/u', $q) === 1 => 'campaign',
            preg_match('/integrat|sync|connection|koppeling|verbinding/u', $q) === 1 => 'integration',
            preg_match('/fee|cost|kosten|30%|offer cost|aanbieding.*kost|kost.*aanbieding/u', $q) === 1 => 'offer_cost',
            preg_match('/merchant funding|funding|financier|webshop.*(?:wallet|saldo)/u', $q) === 1 => 'funding',
            preg_match('/voucher|coupon|kortingscode/u', $q) === 1 => 'vouchers',
            preg_match('/wallet|balance|saldo|points|punten|tegoed|credit/u', $q) === 1 => $workspace ? 'funding' : 'wallet',
            preg_match('/faq|knowledge|help article|helptekst|kennis|\bhow\b|\bhoe\b/u', $q) === 1 => 'knowledge',
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
        $missing = in_array(null, $arg, true) || ($intent === 'reward' && $reference === null);

        return ['intent' => $intent, 'tool' => $tool, 'arguments' => $arg, 'reference' => $reference,
            'unit' => $unit, 'missing_reference' => $missing, 'wrong_scope' => $wrongScope];
    }
}
