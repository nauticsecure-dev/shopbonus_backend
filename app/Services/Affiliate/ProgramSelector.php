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
    public function select(Webshop $webshop, bool $onlyIfChanged = false): array
    {
        $candidates = AffiliateProgram::with('connection')->where('webshop_id', $webshop->id)->where('active', true)->where('cashback_allowed', true)
            ->whereHas('connection', fn ($q) => $q->whereIn('status', ['connected', 'limited']))->orderBy('id')->get();

        $pending = null;
        $latest = $onlyIfChanged ? DB::table('affiliate_program_selections')->where('webshop_id', $webshop->id)->latest('id')->first() : null;

        return $this->recordSelection($webshop->id, $candidates, $pending, $latest);
    }

    /** Resolve a directory page with bounded queries, including first-time selections.
     * @param  list<int>  $webshopIds
     * @return array<int, AffiliateProgram|null>
     */
    public function currentMany(array $webshopIds): array
    {
        if ($webshopIds === []) {
            return [];
        }
        $latest = DB::table('affiliate_program_selections')->whereIn('id',
            DB::table('affiliate_program_selections')->selectRaw('MAX(id)')->whereIn('webshop_id', $webshopIds)->groupBy('webshop_id')
        )->get()->keyBy('webshop_id');
        $programs = AffiliateProgram::with('connection')->whereIn('webshop_id', $webshopIds)->orderBy('id')->get();
        $byShop = $programs->groupBy('webshop_id');
        $result = [];
        $pending = [];
        foreach ($webshopIds as $id) {
            $decision = $latest->get($id);
            $candidates = ($byShop->get($id) ?? collect())->filter(fn (AffiliateProgram $p) => $this->eligible($p))->values();
            $result[$id] = $this->recordSelection($id, $candidates, $pending, $decision)['program'];
        }
        if ($pending !== []) {
            DB::table('affiliate_program_selections')->insert($pending);
        }

        return $result;
    }

    private function eligible(AffiliateProgram $program): bool
    {
        return $program->active && $program->cashback_allowed && in_array($program->connection->status, ['connected', 'limited'], true);
    }

    /**
     * @param  Collection<int, AffiliateProgram>  $candidates
     * @param  list<array<string, mixed>>|null  $pending
     * @return array{program: AffiliateProgram|null, method: string, limitation: string|null}
     */
    private function recordSelection(int $webshopId, Collection $candidates, ?array &$pending = null, ?object $latest = null): array
    {
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

        if ($latest !== null && (string) $latest->program_id === (string) $program?->id
            && $latest->method === $method && $latest->limitation === $limitation
            && $latest->rule_version === (string) config('shopbonus.affiliate.selection_rule_version')
            && json_decode($latest->candidates, true) == $rows) {
            return ['program' => $program, 'method' => $method, 'limitation' => $limitation];
        }

        $row = [
            'webshop_id' => $webshopId, 'program_id' => $program?->id, 'method' => $method, 'candidates' => json_encode($rows),
            'limitation' => $limitation, 'rule_version' => (string) config('shopbonus.affiliate.selection_rule_version'), 'created_at' => now(),
        ];
        if ($pending === null) {
            DB::table('affiliate_program_selections')->insert($row);
        } else {
            $pending[] = $row;
        }

        return ['program' => $program, 'method' => $method, 'limitation' => $limitation];
    }

    public function current(Webshop $webshop): ?AffiliateProgram
    {
        // Recompute from current eligible candidates; store a new decision only
        // when its inputs or outcome changed. A previous empty choice is not final.
        return $this->select($webshop, onlyIfChanged: true)['program'];
    }

    /** @param Collection<int, AffiliateProgram> $candidates */
    private function fallback($candidates): AffiliateProgram
    {
        return $candidates->first(fn (AffiliateProgram $p) => $p->connection->network === config('shopbonus.affiliate.fallback_network')) ?? $candidates->first();
    }
}
