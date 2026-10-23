<?php

namespace App\Console\Commands;

use App\Enums\AuditCategory;
use App\Models\IntegrationClient;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Private provisioning of partner credentials (Answer24). There is no API
 * for this. The secret is printed once and never again.
 */
class CreateIntegrationClient extends Command
{
    public const SCOPES = ['answer24.contact_results', 'answer24.handoffs', 'answer24.claims'];

    protected $signature = 'shopbonus:create-integration-client {name} {--target=answer24} {--scope=* : Scopes (default: all Answer24 scopes)} {--revoke= : Revoke the client with this key ID}';

    protected $description = 'Create (or revoke) a signed server-to-server integration credential';

    public function handle(AuditLogger $audit): int
    {
        if (($keyId = $this->option('revoke')) !== null) {
            $client = IntegrationClient::where('key_id', $keyId)->first();
            if ($client === null) {
                $this->error('No integration client with that key ID.');

                return self::FAILURE;
            }
            $client->forceFill(['active' => false, 'revoked_at' => now()])->save();
            $audit->record('integration.client_revoked', category: AuditCategory::Integration, resource: $client, metadata: ['via' => 'console']);
            $this->info('Integration client revoked.');

            return self::SUCCESS;
        }

        $scopes = $this->option('scope') ?: self::SCOPES;
        if (array_diff($scopes, self::SCOPES) !== []) {
            $this->error('Unknown scope. Allowed: '.implode(', ', self::SCOPES));

            return self::FAILURE;
        }

        $secret = Str::random(48);
        $client = IntegrationClient::create([
            'name' => (string) $this->argument('name'), 'target' => (string) $this->option('target'),
            'key_id' => 'sbk_'.Str::lower(Str::random(24)), 'secret' => $secret, 'scopes' => array_values($scopes), 'active' => true,
        ]);
        $audit->record('integration.client_created', category: AuditCategory::Integration, resource: $client, metadata: ['via' => 'console', 'scopes' => $client->scopes]);

        $this->info('Integration client created. Store the secret now; it is not shown again.');
        $this->line('Key ID: '.$client->key_id);
        $this->line('Secret: '.$secret);

        return self::SUCCESS;
    }
}
