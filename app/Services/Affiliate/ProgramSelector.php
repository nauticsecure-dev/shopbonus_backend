<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateProgram;
use App\Models\Webshop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Picks the affiliate program used for a webshop (Trello #19 "Network
 * selection"): highest expected commission only when the candidates are
 * genuinely comparable; otherwise the configured fallback, with the
 * limitation recorded. Every decision is stored.
 */
class ProgramSelector
{
    /**
     * @return array{program: AffiliateProgram|null, method: string, limitation: string|null}
     */
    public function select(Webshop $webshop): array
    {
        $candidates = AffiliateProgram::with('connection')->where('webshop_id', $webshop->id)->where('active', true)->where('cashback_allowed', true)
            ->whereHas('connection', fn ($q) => $q->whereIn('status', ['connected', 'limited']))->orderBy('id')->get();
        $basket = (int) config('shopbonus.affiliate.reference_basket_minor', 5000);

        $rows = $candidates->map(fn (AffiliateProgram $p) => [
            'program' => $p->public_id, 'network' => $p->connection->network, 'commission_type' => $p->commission_type, 'commission_value' => $p->commission_value,
            'currency' => $p->currency, 'category_restricted' => $p->category_restricted,
            'expected_commission_minor' => $p->commission_type === 'percentage' ? intdiv($basket * $p->commission_value, 10_000) : $p->commission_value,
        ])->all();

        [$program, $method, $limitation] = match (true) {
            $candidates->isEmpty() => [null, 'none', 'no_eligible_program'],
            $candidates->count() === 1 => [$candidates->first(), 'single', null],
            $candidates->contains('category_restricted', true) => [$this->fallback($candidates), 'fallback', 'restricted_rates_not_comparable'],
            $candidates->pluck('currency')->unique()->count() > 1 => [$this->fallback($candidates), 'fallback', 'currencies_differ'],
            default => [$candidates->sortByDesc(fn (AffiliateProgram $p) => [$p->commission_type === 'percentage' ? intdiv($basket * $p->commission_value, 10_000) : $p->commission_value, -$p->id])->first(), 'best_comparable', null],
        };

        DB::table('affiliate_program_selections')->insert([
            'webshop_id' => $webshop->id, 'program_id' => $program?->id, 'method' => $method, 'candidates' => json_encode($rows),
            'limitation' => $limitation, 'rule_version' => (string) config('shopbonus.affiliate.selection_rule_version'), 'created_at' => now(),
        ]);

        return ['program' => $program, 'method' => $method, 'limitation' => $limitation];
    }

    public function current(Webshop $webshop): ?AffiliateProgram
    {
        $latest = DB::table('affiliate_program_selections')->where('webshop_id', $webshop->id)->orderByDesc('id')->first();
        $program = $latest?->program_id === null ? null : AffiliateProgram::with('connection')->find($latest->program_id);

        // Re-select when there is no decision yet or the chosen program is no longer usable.
        if ($latest === null || ($program !== null && (! $program->active || ! $program->cashback_allowed || $program->webshop_id !== $webshop->id
            || ! in_array($program->connection->status, ['connected', 'limited'], true)))) {
            return $this->select($webshop)['program'];
        }

        return $program;
    }

    /** @param Collection<int, AffiliateProgram> $candidates */
    private function fallback($candidates): AffiliateProgram
    {
        return $candidates->first(fn (AffiliateProgram $p) => $p->connection->network === config('shopbonus.affiliate.fallback_network')) ?? $candidates->first();
    }
}
