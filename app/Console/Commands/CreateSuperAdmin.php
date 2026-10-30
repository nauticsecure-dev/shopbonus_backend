<?php

namespace App\Console\Commands;

use App\Enums\AuditCategory;
use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Private provisioning of a new Super Admin account (Trello #4: admins are
 * created internally, never through public registration). Prompts for missing
 * values; the password is read hidden and checked against the same rules as
 * signup. For an existing user use shopbonus:grant-super-admin instead.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'shopbonus:create-admin {--name= : First name} {--email= : Login email}
        {--password= : Avoid in shared shells (stays in shell history); omit to be prompted}';

    protected $description = 'Create a new verified Super Admin account with a name, email and password';

    public function handle(AuditLogger $audit): int
    {
        $name = trim((string) ($this->option('name') ?? $this->ask('Name')));
        $email = User::normalizeEmail((string) ($this->option('email') ?? $this->ask('Email')));
        $password = $this->option('password');
        if ($password === null) {
            $password = (string) $this->secret('Password (min 10 characters, letters and numbers)');
            if ($password !== (string) $this->secret('Confirm password')) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $password], [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::defaults()],
        ], ['email.unique' => 'A user with this email already exists. Use shopbonus:grant-super-admin to make them a Super Admin.']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $password, $audit) {
            $user = User::create(['first_name' => $name, 'email' => $email, 'password' => $password, 'locale' => 'en']);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
            $audit->record('admin.super_admin_granted', category: AuditCategory::Admin, resource: $user, metadata: ['via' => 'console', 'created' => true]);

            return $user;
        });

        $this->info("Super Admin {$user->email} created.");
        if (config('shopbonus.admin.mfa_required')) {
            $this->warn('The admin API stays closed (403 mfa_required) until this user signs in and enables two-factor authentication.');
        }

        return self::SUCCESS;
    }
}
