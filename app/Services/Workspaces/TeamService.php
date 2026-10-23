<?php

namespace App\Services\Workspaces;

use App\Enums\AuditCategory;
use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopInvitation;
use App\Models\WebshopMembership;
use App\Notifications\WorkspaceInvitationMail;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Team management (Trello #16). Authority rules, applied server-side:
 *
 * - The owner role is never granted here: not by invitation, not by role
 *   change. Ownership transfer is a separate step-up workflow (D-021).
 * - An actor can grant a role only up to their own rank, and only
 *   permissions they hold themselves.
 * - An actor can change, suspend or remove only members they outrank;
 *   owners are never targets (except leaving, below).
 * - The final active owner can never be removed or suspended.
 */
class TeamService
{
    public const INVITATION_VALID_DAYS = 7;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const MAX_RESENDS = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    // ---------------------------------------------------------------- invitations

    /**
     * @param  list<string>  $permissions
     */
    public function invite(WebshopMembership $actor, string $email, MembershipRole $role, array $permissions): WebshopInvitation
    {
        $webshop = $actor->webshop;
        $email = User::normalizeEmail($email);
        $grants = $this->assertCanGrant($actor, $role, $permissions);

        return DB::transaction(function () use ($actor, $webshop, $email, $role, $grants) {
            Webshop::whereKey($webshop->id)->lockForUpdate()->first();

            $existingUser = User::where('email', $email)->first();
            if ($existingUser !== null && $webshop->memberships()->where('user_id', $existingUser->id)->active()->exists()) {
                throw ApiException::conflict('This person is already a member of this workspace.', 'already_member');
            }

            $pending = WebshopInvitation::where('webshop_id', $webshop->id)->where('email', $email)
                ->where('status', InvitationStatus::Pending)->where('expires_at', '>', now())->exists();
            if ($pending) {
                throw ApiException::conflict('An invitation for this email is already pending. Resend it instead.', 'invitation_pending');
            }

            $token = $this->newToken();
            $invitation = WebshopInvitation::create([
                'webshop_id' => $webshop->id,
                'email' => $email,
                'role' => $role,
                'permissions' => $grants === [] ? null : $grants,
                'token_hash' => $this->hash($token),
                'status' => InvitationStatus::Pending,
                'invited_by' => $actor->user_id,
                'expires_at' => now()->addDays(self::INVITATION_VALID_DAYS),
                'last_sent_at' => now(),
                'resend_count' => 0,
            ])->setRelation('webshop', $webshop);

            $this->send($invitation, $token, $actor->user);

            $this->audit->record(
                action: 'team.invitation_created',
                category: AuditCategory::Workspace,
                resource: $invitation,
                webshopId: $webshop->id,
                metadata: ['role' => $role->value, 'grants' => $grants],
            );

            return $invitation;
        });
    }

    public function resend(WebshopMembership $actor, WebshopInvitation $invitation): WebshopInvitation
    {
        if ($invitation->status !== InvitationStatus::Pending) {
            throw ApiException::conflict('Only pending invitations can be resent.', 'invitation_not_pending');
        }

        if ($invitation->resend_count >= self::MAX_RESENDS) {
            throw new ApiException('This invitation was resent too often. Cancel it and invite again.', 429, 'invitation_resend_limit');
        }

        $wait = self::RESEND_COOLDOWN_SECONDS - (int) $invitation->last_sent_at->diffInSeconds(now());
        if ($wait > 0) {
            throw new ApiException('Please wait before resending.', 429, 'invitation_resend_too_soon',
                ['retry_after_seconds' => $wait], ['Retry-After' => (string) $wait]);
        }

        // A role/grant the actor could not give today cannot be re-sent by them.
        $this->assertCanGrant($actor, $invitation->role, $invitation->permissions ?? []);

        return DB::transaction(function () use ($actor, $invitation) {
            $token = $this->newToken();
            $invitation->forceFill([
                'token_hash' => $this->hash($token),
                'expires_at' => now()->addDays(self::INVITATION_VALID_DAYS),
                'last_sent_at' => now(),
                'resend_count' => $invitation->resend_count + 1,
            ])->save();

            $this->send($invitation, $token, $actor->user);
            $this->audit->record('team.invitation_resent', category: AuditCategory::Workspace,
                resource: $invitation, webshopId: $invitation->webshop_id);

            return $invitation;
        });
    }

    public function cancel(WebshopInvitation $invitation): WebshopInvitation
    {
        if ($invitation->status !== InvitationStatus::Pending) {
            throw ApiException::conflict('Only pending invitations can be cancelled.', 'invitation_not_pending');
        }

        $invitation->forceFill(['status' => InvitationStatus::Cancelled, 'cancelled_at' => now()])->save();
        $this->audit->record('team.invitation_cancelled', category: AuditCategory::Workspace,
            resource: $invitation, webshopId: $invitation->webshop_id);

        return $invitation;
    }

    /**
     * Looks up an open invitation by token, for preview. Unknown, used,
     * cancelled and expired tokens all fail the same way.
     */
    public function findOpen(#[\SensitiveParameter] string $token): WebshopInvitation
    {
        $invitation = WebshopInvitation::with(['webshop', 'inviter'])->where('token_hash', $this->hash($token))->first();

        if ($invitation === null || ! $invitation->isOpen()) {
            throw new ApiException('This invitation is invalid or has expired.', 410, 'invitation_invalid');
        }

        return $invitation;
    }

    public function accept(User $user, #[\SensitiveParameter] string $token): WebshopMembership
    {
        // Checked (and audited) outside the transaction so the security audit
        // row is not rolled back with it; re-checked under lock below.
        $candidate = WebshopInvitation::where('token_hash', $this->hash($token))->first();

        if ($candidate !== null && $candidate->isOpen() && $candidate->email !== $user->email) {
            $this->audit->recordSafely('team.invitation_recipient_mismatch', category: AuditCategory::Security,
                resource: $candidate, webshopId: $candidate->webshop_id);

            throw $this->recipientMismatch();
        }

        return DB::transaction(function () use ($user, $token) {
            $invitation = WebshopInvitation::where('token_hash', $this->hash($token))->lockForUpdate()->first();

            if ($invitation === null || ! $invitation->isOpen()) {
                throw new ApiException('This invitation is invalid or has expired.', 410, 'invitation_invalid');
            }

            // An invitation is only as valid as the authority behind it today.
            $inviter = WebshopMembership::with('webshop')->where('webshop_id', $invitation->webshop_id)
                ->where('user_id', $invitation->invited_by)->first();
            $authorityLost = $inviter === null
                || ! $inviter->allows(WorkspacePermission::TeamManage)
                || $invitation->role->rank() > $inviter->role->rank();

            if ($authorityLost || ! $inviter->webshop->status->allowsMemberAccess()) {
                throw new ApiException('This invitation is invalid or has expired.', 410, 'invitation_invalid');
            }

            if ($invitation->email !== $user->email) {
                throw $this->recipientMismatch();
            }

            $membership = WebshopMembership::where('webshop_id', $invitation->webshop_id)
                ->where('user_id', $user->id)->lockForUpdate()->first();

            if ($membership?->isActive()) {
                throw ApiException::conflict('You are already a member of this workspace.', 'already_member');
            }

            $attributes = [
                'role' => $invitation->role,
                'permissions' => $invitation->permissions,
                'status' => MembershipStatus::Active,
                'granted_by' => $invitation->invited_by,
                'joined_at' => now(),
                'suspended_at' => null,
                'removed_at' => null,
            ];

            if ($membership === null) {
                $membership = WebshopMembership::create($attributes + ['webshop_id' => $invitation->webshop_id, 'user_id' => $user->id]);
            } else {
                $membership->forceFill($attributes)->save(); // re-joining after removal
            }

            $invitation->forceFill([
                'status' => InvitationStatus::Accepted,
                'accepted_by' => $user->id,
                'accepted_at' => now(),
            ])->save();

            $this->audit->record('team.invitation_accepted', category: AuditCategory::Workspace,
                resource: $invitation, webshopId: $invitation->webshop_id, metadata: ['role' => $invitation->role->value]);

            return $membership->load('webshop');
        });
    }

    // ---------------------------------------------------------------- members

    /**
     * @param  list<string>|null  $permissions
     */
    public function updateMember(WebshopMembership $actor, WebshopMembership $target, ?MembershipRole $role, ?array $permissions): WebshopMembership
    {
        $this->assertCanManage($actor, $target);

        $newRole = $role ?? $target->role;
        $grants = $this->assertCanGrant($actor, $newRole, $permissions ?? ($target->permissions ?? []));
        $before = ['role' => $target->role->value, 'grants' => $target->permissions ?? []];

        $target->forceFill(['role' => $newRole, 'permissions' => $grants === [] ? null : $grants])->save();

        $this->audit->record('team.member_updated', category: AuditCategory::Workspace,
            resource: (new User)->getMorphClass(), resourceId: $target->user_id, webshopId: $target->webshop_id,
            metadata: ['before' => $before, 'after' => ['role' => $newRole->value, 'grants' => $grants]]);

        return $target;
    }

    public function suspend(WebshopMembership $actor, WebshopMembership $target): WebshopMembership
    {
        $this->assertCanManage($actor, $target);
        $this->changeStatus($target, MembershipStatus::Suspended, 'team.member_suspended');

        return $target;
    }

    public function reactivate(WebshopMembership $actor, WebshopMembership $target): WebshopMembership
    {
        $this->assertCanManage($actor, $target);

        if ($target->status !== MembershipStatus::Suspended) {
            throw ApiException::conflict('Only suspended members can be reactivated.', 'member_not_suspended');
        }

        $this->changeStatus($target, MembershipStatus::Active, 'team.member_reactivated');

        return $target;
    }

    /**
     * Removes a member, or lets a member leave when $actor is the target.
     */
    public function remove(WebshopMembership $actor, WebshopMembership $target): void
    {
        $leaving = $actor->is($target);

        if (! $leaving) {
            $this->assertCanManage($actor, $target);
        }

        DB::transaction(function () use ($target, $leaving) {
            if ($target->role === MembershipRole::Owner) {
                $this->assertNotFinalOwner($target);
            }

            $this->changeStatus($target, MembershipStatus::Removed, $leaving ? 'team.member_left' : 'team.member_removed');
        });
    }

    // ---------------------------------------------------------------- rules

    /**
     * @param  array<mixed>  $permissions
     * @return list<string> validated grants
     */
    private function assertCanGrant(WebshopMembership $actor, MembershipRole $role, array $permissions): array
    {
        if ($role === MembershipRole::Owner) {
            throw new ApiException('The owner role cannot be granted this way.', 403, 'owner_role_not_grantable');
        }

        if ($role->rank() > $actor->role->rank()) {
            throw new ApiException('You cannot grant a role above your own.', 403, 'role_above_own');
        }

        $grants = [];
        $held = $actor->effectivePermissions();

        foreach ($permissions as $value) {
            $permission = is_string($value) ? WorkspacePermission::tryFrom($value) : null;

            if ($permission === null) {
                throw new ApiException('Unknown permission.', 422, 'unknown_permission', ['permission' => $value]);
            }

            if ($permission->isOwnerOnly() || ! in_array($permission, $held, true)) {
                throw new ApiException('You cannot grant a permission you do not hold.', 403, 'permission_above_own',
                    ['permission' => $permission->value]);
            }

            $grants[] = $permission->value;
        }

        return array_values(array_unique($grants));
    }

    private function assertCanManage(WebshopMembership $actor, WebshopMembership $target): void
    {
        if ($actor->is($target)) {
            throw new ApiException('You cannot change your own membership this way.', 403, 'cannot_manage_self');
        }

        if ($target->role === MembershipRole::Owner) {
            throw new ApiException('Owners can only be changed through the ownership workflow.', 403, 'owner_not_manageable');
        }

        if ($actor->role->rank() <= $target->role->rank()) {
            throw new ApiException('You can only manage members below your own role.', 403, 'member_not_below_you');
        }
    }

    private function assertNotFinalOwner(WebshopMembership $owner): void
    {
        $otherOwners = WebshopMembership::where('webshop_id', $owner->webshop_id)
            ->where('role', MembershipRole::Owner)->active()
            ->whereKeyNot($owner->id)->lockForUpdate()->count();

        if ($otherOwners === 0) {
            throw ApiException::conflict('The last owner cannot leave or be removed.', 'final_owner');
        }
    }

    private function changeStatus(WebshopMembership $target, MembershipStatus $status, string $action): void
    {
        $target->forceFill([
            'status' => $status,
            'suspended_at' => $status === MembershipStatus::Suspended ? now() : null,
            'removed_at' => $status === MembershipStatus::Removed ? now() : null,
        ])->save();

        if ($status !== MembershipStatus::Active) {
            User::whereKey($target->user_id)->where('current_webshop_id', $target->webshop_id)
                ->update(['current_webshop_id' => null]);
        }

        $this->audit->record($action, category: AuditCategory::Workspace,
            resource: (new User)->getMorphClass(), resourceId: $target->user_id,
            webshopId: $target->webshop_id, metadata: ['role' => $target->role->value]);
    }

    private function send(WebshopInvitation $invitation, string $token, User $inviter): void
    {
        $locale = User::where('email', $invitation->email)->value('locale') ?? $inviter->preferredLocale();

        Notification::route('mail', $invitation->email)->notify(
            (new WorkspaceInvitationMail($token, $invitation->webshop->name, $inviter->first_name,
                $invitation->role->value, self::INVITATION_VALID_DAYS))->locale($locale),
        );
    }

    private function recipientMismatch(): ApiException
    {
        return new ApiException(
            'This invitation was sent to a different email address. Sign in with that address to accept it.',
            403,
            'invitation_recipient_mismatch',
        );
    }

    private function newToken(): string
    {
        return Str::random(48);
    }

    private function hash(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
