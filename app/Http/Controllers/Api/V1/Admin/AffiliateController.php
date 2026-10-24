<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Affiliate\AffiliateNetworkRegistry;
use App\Enums\AuditCategory;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AffiliateClick;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliateReconciliationEvent;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\Webshop;
use App\Services\Affiliate\AffiliateProcessor;
use App\Services\Affiliate\AffiliateSync;
use App\Services\Affiliate\ProgramSelector;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Super Admin affiliate controls (Trello #19 "Super Admin controls"):
 * network connections, program matching, selection decisions, conversion
 * review and reward rules. Stored balances are never edited here.
 */
class AffiliateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly AffiliateNetworkRegistry $registry) {}

    // ---------------------------------------------------------------- connections

    public function connections(): JsonResponse
    {
        return response()->json(['data' => AffiliateConnection::orderBy('id')->get()->map(fn ($c) => $this->connection($c))->all()]);
    }

    public function storeConnection(Request $request): JsonResponse
    {
        $data = $request->validate(['network' => ['required', Rule::in(array_keys(AffiliateNetworkRegistry::NETWORKS))], 'name' => ['required', 'string', 'max:120'], 'credentials' => ['required', 'array']]);
        $adapter = $this->registry->for($data['network']);
        $credentials = validator($data['credentials'], $adapter->credentialRules())->validate();

        $token = Str::random(40);
        $connection = AffiliateConnection::create(['network' => $data['network'], 'name' => $data['name'], 'status' => 'error', 'credentials' => $credentials,
            'postback_token_hash' => AffiliateConnection::hashToken($token)]);
        $result = $adapter->testConnection($connection);
        $connection->forceFill(['status' => $result['status'], 'last_error' => $result['error']])->save();

        $this->audit->record('affiliate.connection_created', category: AuditCategory::Integration, resource: $connection, actorUserId: $request->user()->id,
            metadata: ['network' => $connection->network, 'status' => $connection->status]);

        return response()->json(['data' => $this->connection($connection) + [
            'postback_url' => route('api.v1.affiliate.postback', ['token' => $token]),
            'postback_url_notice' => 'Shown once. Postbacks can only record conversions as tracked; rewards need the network API or an import.',
        ]], 201);
    }

    public function testConnection(Request $request, string $connection): JsonResponse
    {
        $c = $this->findConnection($connection);
        $result = $this->registry->for($c->network)->testConnection($c);
        $c->forceFill(['status' => $c->status === 'disabled' ? 'disabled' : $result['status'], 'last_error' => $result['error']])->save();

        return response()->json(['data' => $this->connection($c)]);
    }

    public function disableConnection(Request $request, string $connection): JsonResponse
    {
        $c = $this->findConnection($connection);
        $c->forceFill(['status' => 'disabled'])->save();
        $this->audit->record('affiliate.connection_disabled', category: AuditCategory::Integration, resource: $c, actorUserId: $request->user()->id);

        return response()->json(['data' => $this->connection($c)]);
    }

    public function sync(Request $request, string $connection, AffiliateSync $sync): JsonResponse
    {
        $c = $this->findConnection($connection);
        if ($c->status === 'disabled') {
            throw ApiException::conflict('This connection is disabled.', 'connection_disabled');
        }

        return response()->json(['data' => $sync->sync($c) + ['connection' => $this->connection($c->refresh())]]);
    }

    public function import(Request $request, string $connection, AffiliateSync $sync): JsonResponse
    {
        $data = $request->validate(['programs' => ['sometimes', 'array', 'max:2000'], 'programs.*' => ['array'], 'transactions' => ['sometimes', 'array', 'max:5000'], 'transactions.*' => ['array']]);
        $c = $this->findConnection($connection);
        if ($c->status === 'disabled') {
            throw ApiException::conflict('This connection is disabled.', 'connection_disabled');
        }

        return response()->json(['data' => $sync->import($c, array_values($data['programs'] ?? []), array_values($data['transactions'] ?? []), $request->user()->id)]);
    }

    // ---------------------------------------------------------------- programs and selection

    public function programs(Request $request): JsonResponse
    {
        $filters = $request->validate(['unmatched' => ['sometimes', 'boolean'], 'webshop' => ['sometimes', 'string', 'max:40']]);
        $query = AffiliateProgram::with('connection')->orderBy('id');
        if (! empty($filters['unmatched'])) {
            $query->whereNull('webshop_id');
        }
        if (isset($filters['webshop'])) {
            $query->where('webshop_id', Webshop::where('public_id', $filters['webshop'])->value('id') ?? 0);
        }

        return response()->json(['data' => $query->limit(500)->get()->map(fn ($p) => $this->program($p))->all()]);
    }

    public function updateProgram(Request $request, string $program, ProgramSelector $selector): JsonResponse
    {
        $data = $request->validate(['webshop_id' => ['sometimes', 'nullable', 'string', 'max:40'], 'cashback_allowed' => ['sometimes', 'boolean']]);
        $p = AffiliateProgram::with('connection')->where('public_id', $program)->first() ?? throw new ApiException('Program not found.', 404, 'not_found');

        if (array_key_exists('webshop_id', $data)) {
            $p->webshop_id = $data['webshop_id'] === null ? null : (Webshop::where('public_id', $data['webshop_id'])->value('id') ?? throw new ApiException('Webshop not found.', 404, 'not_found'));
        }
        if (isset($data['cashback_allowed'])) {
            $p->cashback_allowed = (bool) $data['cashback_allowed'];
        }
        $p->save();
        $this->audit->record('affiliate.program_updated', category: AuditCategory::Integration, resource: $p, actorUserId: $request->user()->id, metadata: ['fields' => array_keys($data)]);
        if ($p->webshop_id !== null) {
            $selector->select(Webshop::findOrFail($p->webshop_id));
        }

        return response()->json(['data' => $this->program($p)]);
    }

    public function selections(string $webshop): JsonResponse
    {
        $w = Webshop::where('public_id', $webshop)->first() ?? throw new ApiException('Webshop not found.', 404, 'not_found');

        return response()->json(['data' => DB::table('affiliate_program_selections')->where('webshop_id', $w->id)->orderByDesc('id')->limit(20)->get()
            ->map(fn ($row) => $this->selection($row))->all()]);
    }

    public function select(Request $request, string $webshop, ProgramSelector $selector): JsonResponse
    {
        $w = Webshop::where('public_id', $webshop)->first() ?? throw new ApiException('Webshop not found.', 404, 'not_found');
        $selector->select($w);
        $this->audit->record('affiliate.selection_run', category: AuditCategory::Integration, resource: $w, webshopId: $w->id, actorUserId: $request->user()->id);

        return response()->json(['data' => $this->selection(DB::table('affiliate_program_selections')->where('webshop_id', $w->id)->orderByDesc('id')->first())]);
    }

    // ---------------------------------------------------------------- transactions

    public function transactions(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(['tracked', 'pending', 'approved', 'rejected', 'reversed', 'unmatched', 'disputed'])]]);
        $page = AffiliateTransaction::when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))->orderByDesc('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn ($t) => $this->transaction($t))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'totals' => AffiliateTransaction::selectRaw('status, COUNT(*) c, COALESCE(SUM(commission_minor),0) commission')->groupBy('status')->get()
                ->mapWithKeys(fn ($r) => [$r->getAttribute('status') => ['count' => (int) $r->getAttribute('c'), 'commission_minor' => (int) $r->getAttribute('commission')]])->all()]);
    }

    public function showTransaction(string $transaction): JsonResponse
    {
        $t = $this->findTransaction($transaction);

        return response()->json(['data' => $this->transaction($t) + ['events' => AffiliateReconciliationEvent::where('transaction_id', $t->id)->orderBy('id')->get()
            ->map(fn ($e) => ['source' => $e->source, 'from' => $e->from_status, 'to' => $e->to_status, 'network_status' => $e->network_status, 'note' => $e->note, 'at' => $e->created_at?->toIso8601String()])->all()]]);
    }

    public function resolve(Request $request, string $transaction, AffiliateProcessor $processor): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['link_click', 'accept', 'reject'])], 'click_reference' => ['required_if:action,link_click', 'string', 'max:32'], 'note' => ['required', 'string', 'max:255']]);
        $t = $this->findTransaction($transaction);

        if (! in_array($t->status, ['unmatched', 'disputed', 'tracked'], true)) {
            throw ApiException::conflict('Only unmatched, disputed or tracked conversions need review.', 'not_in_review', ['status' => $t->status]);
        }
        if ($data['action'] === 'link_click') {
            $click = AffiliateClick::where('reference', $data['click_reference'])->where('webshop_id', $t->webshop_id)->whereNotNull('user_id')->first()
                ?? throw new ApiException('No attributable click with that reference for this webshop.', 422, 'click_not_attributable');
            $t->forceFill(['click_id' => $click->id, 'user_id' => $click->user_id])->save();
        }

        $from = $t->status;
        $t->forceFill(['status' => $data['action'] === 'reject' ? 'rejected' : $t->network_status, 'review_reason' => 'resolved:'.$data['action']])->save();
        AffiliateReconciliationEvent::create(['transaction_id' => $t->id, 'source' => 'admin', 'from_status' => $from, 'to_status' => $t->status,
            'network_status' => $t->network_status, 'note' => mb_substr($data['note'], 0, 255), 'actor_user_id' => $request->user()->id]);
        if ($data['action'] !== 'reject') {
            $t = $processor->settle($t, 'admin', $request->user()->id);
        }
        $this->audit->record('affiliate.transaction_resolved', category: AuditCategory::Financial, resource: $t, actorUserId: $request->user()->id, metadata: ['action' => $data['action']]);

        return response()->json(['data' => $this->transaction($t->refresh())]);
    }

    // ---------------------------------------------------------------- reward rules

    public function rules(): JsonResponse
    {
        return response()->json(['data' => AffiliateRewardRule::orderByDesc('version')->get()->map(fn ($r) => $this->rule($r))->all(),
            'current' => AffiliateRewardRule::current()?->version]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit' => ['required', Rule::enum(WalletUnit::class)], 'share_bps' => ['required', 'integer', 'min:1', 'max:10000'],
            'min_commission_minor' => ['sometimes', 'integer', 'min:0'], 'max_reward_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'review_above_minor' => ['sometimes', 'nullable', 'integer', 'min:1'], 'starts_at' => ['sometimes', 'nullable', 'date'], 'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ]);
        $rule = DB::transaction(function () use ($data, $request) {
            $version = (int) AffiliateRewardRule::lockForUpdate()->max('version') + 1;

            return AffiliateRewardRule::create($data + ['version' => $version, 'active' => true, 'created_by' => $request->user()->id, 'min_commission_minor' => $data['min_commission_minor'] ?? 0]);
        });
        $this->audit->record('affiliate.reward_rule_created', category: AuditCategory::Financial, actorUserId: $request->user()->id, metadata: $this->rule($rule));

        return response()->json(['data' => $this->rule($rule)], 201);
    }

    public function deactivateRule(Request $request, string $version): JsonResponse
    {
        $rule = (ctype_digit($version) ? AffiliateRewardRule::where('version', (int) $version)->first() : null) ?? throw new ApiException('Rule not found.', 404, 'not_found');
        $rule->forceFill(['active' => false])->save();
        $this->audit->record('affiliate.reward_rule_deactivated', category: AuditCategory::Financial, actorUserId: $request->user()->id, metadata: ['version' => (int) $version]);

        return response()->json(['data' => $this->rule($rule)]);
    }

    // ---------------------------------------------------------------- presenters

    private function findConnection(string $id): AffiliateConnection
    {
        return AffiliateConnection::where('public_id', $id)->first() ?? throw new ApiException('Connection not found.', 404, 'not_found');
    }

    private function findTransaction(string $id): AffiliateTransaction
    {
        return AffiliateTransaction::where('public_id', $id)->first() ?? throw new ApiException('Transaction not found.', 404, 'not_found');
    }

    /** @return array<string, mixed> */
    private function connection(AffiliateConnection $c): array
    {
        return ['id' => $c->public_id, 'network' => $c->network, 'name' => $c->name, 'status' => $c->status, 'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            'last_error' => $c->last_error, 'verified' => false, 'verification_note' => 'Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES).'];
    }

    /** @return array<string, mixed> */
    private function program(AffiliateProgram $p): array
    {
        return ['id' => $p->public_id, 'network' => $p->connection->network, 'external_id' => $p->external_id, 'name' => $p->name, 'domain' => $p->domain,
            'webshop_id' => $p->webshop_id === null ? null : Webshop::whereKey($p->webshop_id)->value('public_id'), 'active' => $p->active,
            'commission' => ['type' => $p->commission_type, 'value' => $p->commission_value, 'currency' => $p->currency, 'category_restricted' => $p->category_restricted],
            'cashback_allowed' => $p->cashback_allowed];
    }

    /** @return array<string, mixed> */
    private function selection(?object $row): array
    {
        return $row === null ? [] : ['program_id' => $row->program_id === null ? null : AffiliateProgram::whereKey($row->program_id)->value('public_id'), 'method' => $row->method,
            'limitation' => $row->limitation, 'candidates' => json_decode((string) $row->candidates, true), 'rule_version' => $row->rule_version, 'at' => (string) $row->created_at];
    }

    /** @return array<string, mixed> */
    private function transaction(AffiliateTransaction $t): array
    {
        return ['id' => $t->public_id, 'network' => $t->network, 'external_id' => $t->external_id, 'status' => $t->status, 'network_status' => $t->network_status,
            'review_reason' => $t->review_reason, 'webshop_id' => $t->webshop_id === null ? null : Webshop::whereKey($t->webshop_id)->value('public_id'),
            'consumer_attributed' => $t->user_id !== null, 'order_amount_minor' => $t->order_amount_minor, 'commission_minor' => $t->commission_minor, 'currency' => $t->currency,
            'reward' => ['status' => $t->reward_status, 'amount' => $t->reward_amount, 'unit' => $t->reward_unit, 'rule_version' => $t->reward_rule_version],
            'duplicate_of' => $t->duplicate_of_id === null ? null : AffiliateTransaction::whereKey($t->duplicate_of_id)->value('public_id'),
            'transaction_at' => $t->transaction_at->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function rule(AffiliateRewardRule $r): array
    {
        return ['version' => $r->version, 'unit' => $r->unit->value, 'share_bps' => $r->share_bps, 'min_commission_minor' => $r->min_commission_minor,
            'max_reward_minor' => $r->max_reward_minor, 'review_above_minor' => $r->review_above_minor, 'starts_at' => $r->starts_at?->toIso8601String(),
            'ends_at' => $r->ends_at?->toIso8601String(), 'active' => $r->active];
    }
}
