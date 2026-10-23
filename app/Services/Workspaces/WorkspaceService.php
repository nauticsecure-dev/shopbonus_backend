<?php

namespace App\Services\Workspaces;

use App\Enums\AuditCategory;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Creating, selecting and renaming workspaces. Ownership comes only from
 * creating the webshop here (or a later audited transfer); it is never
 * derived from signup intent or client-sent fields.
 */
class WorkspaceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, platform?: string|null}  $data
     */
    public function createDraft(User $user, array $data): Webshop
    {
        $limit = (int) config('shopbonus.workspaces.max_unapproved_per_user');
        $unapproved = Webshop::where('created_by', $user->id)
            ->whereNotIn('status', [WebshopStatus::Approved, WebshopStatus::Rejected])
            ->count();

        if ($unapproved >= $limit) {
            throw ApiException::conflict(
                "You can have at most {$limit} webshops awaiting approval.",
                'workspace_draft_limit_reached',
            );
        }

        return DB::transaction(function () use ($user, $data) {
            $webshop = new Webshop(['name' => $data['name'], 'platform' => $data['platform'] ?? null]);
            $webshop->created_by = $user->id;
            $webshop->status = WebshopStatus::Draft;
            $webshop->save();

            $membership = new WebshopMembership([
                'role' => MembershipRole::Owner,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);
            $membership->user()->associate($user);
            $webshop->memberships()->save($membership);

            // First workspace becomes the selected one.
            if ($user->current_webshop_id === null) {
                $user->forceFill(['current_webshop_id' => $webshop->id])->save();
            }

            $this->audit->record(
                action: 'workspace.created',
                category: AuditCategory::Workspace,
                resource: $webshop,
                webshopId: $webshop->id,
                metadata: ['platform' => $webshop->platform],
            );

            return $webshop->setRelation('memberships', collect([$membership]));
        });
    }

    public function select(User $user, Webshop $webshop): void
    {
        $previous = $user->current_webshop_id;
        $user->forceFill(['current_webshop_id' => $webshop->id])->save();

        $this->audit->recordSafely(
            action: 'workspace.switched',
            category: AuditCategory::Workspace,
            resource: $webshop,
            webshopId: $webshop->id,
            metadata: ['changed' => $previous !== $webshop->id],
        );
    }

    public function rename(Webshop $webshop, string $name): Webshop
    {
        $old = $webshop->name;
        $webshop->update(['name' => $name]);

        $this->audit->record(
            action: 'workspace.renamed',
            category: AuditCategory::Workspace,
            resource: $webshop,
            webshopId: $webshop->id,
            metadata: ['from' => $old, 'to' => $name],
        );

        return $webshop;
    }
}
