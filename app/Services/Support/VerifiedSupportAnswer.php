<?php

namespace App\Services\Support;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * A model may only select opaque evidence IDs, never supply prose, values or links.
 * Reauthorize and re-read sources, compare exact snapshots, then render server templates.
 * This is a verification boundary, not intent classification or a general chat engine.
 */
final class VerifiedSupportAnswer
{
    public function __construct(private readonly SupportTools $tools, private readonly AuditLogger $audit) {}

    /**
     * Required tools are determined by Laravel's research plan, never by a model.
     *
     * @param  array<string, mixed>  $selection
     * @param  list<ToolEvidence>  $evidence
     * @param  list<string>  $requiredTools
     * @return array<string, mixed>
     */
    public function verify(User $actor, array $selection, array $evidence, array $requiredTools): array
    {
        $ids = $selection['evidence_ids'] ?? null;
        if (array_keys($selection) !== ['evidence_ids'] || ! is_array($ids) || ! array_is_list($ids)
            || count($ids) < 1 || count($ids) > 5 || count($evidence) > 5 || $requiredTools === []
            || count($requiredTools) > 5 || array_diff($requiredTools, array_keys(SupportTools::ARGUMENTS)) !== []) {
            $this->invalid($actor);
        }
        $indexed = [];
        $scopes = [];
        $sources = [];
        foreach ($evidence as $item) {
            // Reject even unselected conflicting snapshots: a model cannot hide a conflict.
            if ($item->actorId !== $actor->id || isset($indexed[$item->id])) {
                $this->invalid($actor);
            }
            $scopes[json_encode([$item->workspace, $item->locale], JSON_THROW_ON_ERROR)] = true;
            $source = json_encode([$item->workspace, $item->locale, $item->tool, $item->arguments], JSON_THROW_ON_ERROR);
            if (isset($sources[$source]) && ! $sources[$source]->matches($item)) {
                $this->reject($actor, 'support_evidence_conflict', 'Support evidence conflicts.');
            }
            $sources[$source] = $item;
            $indexed[$item->id] = $item;
        }
        if (count($scopes) !== 1) {
            $this->invalid($actor);
        }
        $selected = [];
        foreach ($ids as $id) {
            if (! is_string($id) || ! isset($indexed[$id]) || isset($selected[$id])) {
                $this->invalid($actor);
            }
            $item = $indexed[$id];
            $now = CarbonImmutable::now();
            if ($item->retrievedAt->greaterThan($now) || $item->retrievedAt->lessThan($now->subSeconds(30))) {
                $this->reject($actor, 'support_evidence_expired', 'Support evidence has expired.');
            }
            $fresh = $this->tools->retrieve($actor, $item->tool, $item->arguments, $item->workspace, $item->locale);
            if (! $item->matches($fresh)) {
                $this->reject($actor, 'support_evidence_changed', 'Support evidence has changed.');
            }
            $selected[$id] = $fresh;
        }
        if (array_diff($requiredTools, array_map(fn (ToolEvidence $item) => $item->tool, array_values($selected))) !== []) {
            $this->audit->recordSafely('support.answer_incomplete', outcome: AuditOutcome::Failure, category: AuditCategory::Security, actorUserId: $actor->id);

            return ['status' => 'NEEDS_CLARIFICATION', 'answer' => null, 'evidence_ids' => [], 'checked_at' => CarbonImmutable::now()->toIso8601String(), 'action' => null];
        }
        // One conversation locale; caller supplies that locale through the trusted context.
        $parts = array_map(fn (ToolEvidence $item) => $this->render($item), array_values($selected));
        $partial = count(array_filter($selected, fn (ToolEvidence $item) => $item->tool === 'get_offer_cost' && ! ($item->facts['cost_available'] ?? false))) > 0;
        $checks = SupportAnswerChecks::report(['AUTH', 'SOURCE', 'FRESHNESS', 'COMPLETENESS', 'CONSISTENCY', 'CLAIMS', 'POLICY', 'ACTION']);

        $this->audit->recordSafely('support.answer_verified', category: AuditCategory::Security, actorUserId: $actor->id,
            metadata: ['status' => $partial ? 'PARTIALLY_VERIFIED' : 'VERIFIED', 'checks' => $checks, 'tools' => array_values(array_unique(array_map(fn (ToolEvidence $e) => $e->tool, array_values($selected))))]);

        return ['status' => $partial ? 'PARTIALLY_VERIFIED' : 'VERIFIED', 'answer' => implode("\n", $parts),
            'evidence_ids' => array_keys($selected), 'checked_at' => CarbonImmutable::now()->toIso8601String(),
            'checks' => $checks,
            // Actions need an authorized conversation/intent contract; do not accept model URLs.
            'action' => null];
    }

    private function render(ToolEvidence $e): string
    {
        $f = $e->facts;
        $nl = $e->locale === 'nl';
        $minor = $nl ? ' kleinste munteenheden' : ' minor units';
        $amount = fn (int $value) => in_array($f['unit'] ?? '', ['points', 'POINTS'], true) ? $value.($nl ? ' punten' : ' points')
            : 'EUR '.intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
        $capabilities = is_array($f['capabilities'] ?? null)
            ? json_encode($f['capabilities'], JSON_THROW_ON_ERROR)
            : (string) ($f['capabilities'] ?? '');
        $errors = $f['last_error_codes'] ?? (($f['last_error_code'] ?? null) === null ? [] : [$f['last_error_code']]);
        $errorText = $errors === [] || $errors === null
            ? ($nl ? 'geen geregistreerd' : 'none recorded')
            : (is_array($errors) ? implode(',', $errors) : (string) $errors);

        return match ($e->tool) {
            'get_my_order_reward_status' => ($nl ? 'Geregistreerde betaalstatus: ' : 'Recorded payment status: ').($f['payment_status'] ?? ($nl ? 'onbekend' : 'unknown')).'; '
                .($f['source_type'] === 'wallet_pending_credit' ? ($nl ? 'walletbeloning: ' : 'wallet reward: ') : ($nl ? 'voucherbeloning: ' : 'voucher reward: ')).$f['reward_status'].'; '
                .($nl ? 'vaste beloningswaarde: ' : 'fixed reward value: ').($f['reward_minor'] === null ? ($nl ? 'onbekend' : 'unknown') : $f['reward_minor'].$minor.' '.$f['currency']).'. '
                .($nl ? 'Er is geen vrijgavedatum bevestigd.' : 'No release date has been confirmed.'),
            'get_my_vouchers' => ($nl ? 'Uw vouchers: ' : 'Your vouchers: ').$f['issued'].($nl ? ' uitgegeven; ' : ' issued; ').$f['redeemed'].($nl ? ' ingewisseld; ' : ' redeemed; ').$f['expired'].($nl ? ' verlopen; ' : ' expired; ').$f['cancelled'].($nl ? ' geannuleerd.' : ' cancelled.'),
            'get_voucher_quote' => ($nl ? 'Actuele prijsopgave in ' : 'Current quote in ').$f['currency'].($nl ? ': prijs ' : ': price ').$f['price_minor'].$minor.'; wallet '.$f['wallet_usable_minor'].$minor.'; Mollie '.$f['mollie_due_minor'].$minor.($nl ? '; voorraad ' : '; stock ').($f['stock_available'] ? ($nl ? 'beschikbaar' : 'available') : ($nl ? 'niet beschikbaar' : 'unavailable')).($nl ? '; te koop ' : '; purchasable ').($f['purchasable'] ? ($nl ? 'ja' : 'yes') : ($nl ? 'nee' : 'no')).'. '.($nl ? 'Er is niets betaald of uitgegeven.' : 'No payment or voucher issuance has occurred.'),
            'get_campaign_performance' => ($nl ? 'Campagne van ' : 'Campaign from ').$f['from'].($nl ? ' tot ' : ' to ').$f['to'].': '.$f['impressions'].($nl ? ' zichtbare vertoningen; ' : ' viewable impressions; ').$f['clicks'].($nl ? ' klikken; ' : ' clicks; ').$f['attributed_paid_orders'].($nl ? ' toegeschreven betaalde orders. Gestarte bestellingen zijn niet beschikbaar; attributie bewijst geen oorzakelijke omzetstijging.' : ' attributed paid orders. Tracked order starts are unavailable; attribution does not establish causal uplift.'),
            'get_integration_health' => ($nl ? 'Integratie ' : 'Integration ').$f['platform'].': '.$f['status'].'; '.($nl ? 'laatste ontvangen event: ' : 'last received event: ').($f['last_sync_at'] ?? ($nl ? 'onbekend' : 'unknown')).($nl ? '; fout ' : '; error ').$errorText.($nl ? '. Mogelijkheden: ' : '. Capabilities: ').$capabilities.'.',
            'get_my_wallet' => $this->renderWallet($f, $nl, $amount),
            'get_workspace_wallet' => $nl
                ? 'Beschikbare webshopfinanciering: '.$amount((int) $f['available_minor']).'; gereserveerd: '.$amount((int) $f['reserved_minor']).'.'
                : 'Available merchant funding: '.$amount((int) $f['available_minor']).'; reserved: '.$amount((int) $f['reserved_minor']).'.',
            'get_my_voucher' => ($nl ? 'Geregistreerde voucherstatus: ' : 'Recorded voucher status: ').$f['status'].'. '
                .($f['redeemed_at'] === null ? ($nl ? 'Geen bevestigde inwisseling geregistreerd.' : 'No confirmed redemption is recorded.')
                    : ($nl ? 'Inwisseling geregistreerd op ' : 'Redemption recorded at ').$f['redeemed_at'].'.')
                .($f['expires_at'] === null ? '' : ($nl ? ' Vervaldatum: ' : ' Expires at: ').$f['expires_at'].'.'),
            'get_offer_cost' => ! ($f['cost_available'] ?? false)
                ? ($nl ? 'De actuele directe beloningskosten konden niet worden vastgesteld.' : 'The current direct reward cost is unavailable.')
                : ($nl ? 'Kostenvoorbeeld per voucher in ' : 'Cost preview per voucher in ').$f['currency'].': '
                    .($nl ? 'beloning ' : 'reward ').$f['consumer_reward_minor'].$minor.'; '
                    .($nl ? 'platformkosten ' : 'platform fee ').$f['shopbonus_fee_minor'].$minor.'; '
                    .($nl ? 'totaal ' : 'total ').$f['merchant_total_economic_cost_minor'].$minor.'; '
                    .($nl ? 'tarief ' : 'rate ').$f['shopbonus_fee_rate'].'; '
                    .($nl ? 'basis ' : 'basis ').$f['basis'].'. '
                    .($nl ? 'Alleen een voorbeeld; er is niets afgeschreven.' : 'Preview only; nothing has been charged.'),
            'get_approved_knowledge' => ($nl ? 'Gepubliceerde FAQ ('.$f['locale'].'): ' : 'Published FAQ ('.$f['locale'].'): ').$f['content'],
            default => throw new \LogicException('Unsupported verified support tool.'),
        };
    }

    /** @param  array<string, mixed>  $f
     * @param  callable(int): string  $amount
     */
    private function renderWallet(array $f, bool $nl, callable $amount): string
    {
        $reserved = (int) ($f['reserved_minor'] ?? $f['reserved'] ?? 0);
        $pending = (int) ($f['pending_minor'] ?? $f['pending'] ?? 0);
        $available = (int) ($f['available_minor'] ?? $f['available'] ?? 0);
        $spent = (int) ($f['spent_minor'] ?? $f['spent'] ?? 0);
        // Keep exact unit ledger wording for evidence tests; add a reserved-reward line when NL locale holds funds.
        $ledger = $nl
            ? 'Beschikbaar ShopBonus-tegoed: '.$amount($available).'; gereserveerd: '.$amount($reserved).'; in behandeling: '.$amount($pending).'; besteed: '.$amount($spent).'. Geen geldopname of overdracht.'
            : 'Available ShopBonus credit: '.$amount($available).'; reserved: '.$amount($reserved).'; pending: '.$amount($pending).'; spent: '.$amount($spent).'. Cannot be withdrawn or transferred.';
        if ($nl && ($reserved > 0 || $pending > 0)) {
            $held = $reserved > 0 ? $reserved : $pending;

            return 'Je beloning van '.$amount($held).' is gereserveerd. Zodra deze beschikbaar is, verschijnt het bedrag in je ShopBonus-tegoed. '.$ledger;
        }

        return $ledger;
    }

    private function invalid(User $actor): never
    {
        $this->reject($actor, 'support_answer_invalid', 'The support answer could not be verified.', 422);
    }

    private function reject(User $actor, string $code, string $message, int $status = 409): never
    {
        $this->audit->recordSafely('support.answer_rejected', outcome: AuditOutcome::Denied, category: AuditCategory::Security,
            actorUserId: $actor->id, metadata: ['reason' => $code], httpStatus: $status);
        throw new ApiException($message, $status, $code);
    }
}
