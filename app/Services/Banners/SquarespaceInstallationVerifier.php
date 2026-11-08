<?php

namespace App\Services\Banners;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\SquarespaceConnector;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\PublicAddressGuard;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Squarespace exposes no code-injection API (Commerce APIs are orders/inventory/discounts only;
 * Code Injection is a merchant UI setting), so publication cannot be automated without inventing an
 * endpoint. This automates the rest: an exact frozen snippet, then verified presence (install) and
 * verified absence (cleanup) by a bounded, DNS-pinned, no-redirect read of the verified storefront.
 * Presence in served HTML is configuration evidence, not browser delivery proof.
 */
class SquarespaceInstallationVerifier
{
    private const MAX_BYTES = 2097152;

    public function __construct(private readonly HostResolver $resolver, private readonly AuditLogger $audit) {}

    /** @return array<string,mixed> */
    public function install(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        app(SquarespaceConnector::class)->testConnection($connection); // verified website identity and owned domain
        $snippet = $this->snippet($connection);

        return ['state' => 'manual_installation_required', 'snippet' => $snippet, 'location' => 'Settings > Developer tools > Code injection > Footer',
            'next' => 'shopbonus:banner-native reconcile', 'storefront_delivery' => 'not_observed',
            'source' => 'https://support.squarespace.com/hc/en-us/articles/205815908-Customize-parts-of-your-site-with-code-injection'];
    }

    /** @return array<string,mixed> */
    public function reconcile(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        $snippet = $this->snippet($connection);
        $present = str_contains($this->storefront($connection), $snippet);
        $this->record($connection, $actor, $present ? 'verified' : 'missing', $snippet);

        return ['state' => $present ? 'native_configured' : 'remote_missing', 'evidence' => 'served_html', 'storefront_delivery' => 'not_observed'];
    }

    /** @return array<string,mixed> */
    public function remove(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        $snippet = $this->snippet($connection);
        $html = $this->storefront($connection);
        if (str_contains($html, $snippet) || str_contains($html, 'data-shopbonus-key="'.app(BannerService::class)->installation($connection->webshop)->public_key.'"')) {
            $this->record($connection, $actor, 'removal_pending', $snippet);

            return ['state' => 'removal_pending', 'action' => 'Delete the ShopBonus snippet from Squarespace Code Injection, then run remove again.', 'storefront_delivery' => 'not_observed'];
        }
        $this->record($connection, $actor, 'removed', $snippet);

        return ['state' => 'removed', 'evidence' => 'served_html', 'storefront_delivery' => 'not_observed'];
    }

    public function snippet(WebshopConnection $connection): string
    {
        $key = app(BannerService::class)->installation($connection->webshop)->public_key;

        return '<script defer src="'.htmlspecialchars(EcwidAppPublisher::origin().'/embed/v1.js', ENT_QUOTES).'" data-shopbonus-key="'.htmlspecialchars($key, ENT_QUOTES).'"></script>';
    }

    private function storefront(WebshopConnection $connection): string
    {
        $host = strtolower((string) $connection->webshop->verified_domain);
        if (! $connection->webshop->isOwnershipVerified() || ! preg_match('/\A[a-z0-9.-]{1,253}\z/D', $host)) {
            throw ApiException::conflict('Verify ownership of the exact store domain first.', 'store_identity_unverified');
        }
        $addresses = $this->resolver->addresses($host);
        if ($addresses === [] || ! PublicAddressGuard::allPublic($addresses)) {
            throw ApiException::conflict('The store domain must resolve only to public addresses.', 'provider_address_refused');
        }
        $pin = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        try {
            $response = Http::withHeaders(['Accept' => 'text/html'])->withOptions(['allow_redirects' => false, 'proxy' => '', 'verify' => true,
                'connect_timeout' => 3, 'timeout' => 8, 'stream' => true, 'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pin}"]]])->get('https://'.$host.'/');
            if ($response->status() !== 200) {
                throw new \RuntimeException;
            }
            $stream = $response->toPsrResponse()->getBody();
            $html = '';
            while (! $stream->eof() && strlen($html) <= self::MAX_BYTES) {
                $part = $stream->read(65536);
                if ($part === '') {
                    break;
                }
                $html .= $part;
            }
            if (strlen($html) > self::MAX_BYTES || ! $stream->eof()) {
                throw new \RuntimeException;
            }
        } catch (Throwable) {
            throw new ApiException('The storefront could not be read for verification (no redirects followed).', 502, 'native_banner_unconfirmed');
        }

        return $html;
    }

    private function authorize(WebshopConnection $connection, User $actor): void
    {
        $u = User::find($actor->id);
        $m = WebshopMembership::where('webshop_id', $connection->webshop_id)->where('user_id', $actor->id)->first();
        if (! $u?->isActive() || (! $u->isSuperAdmin() && ($m === null || ! $m->allows(WorkspacePermission::IntegrationsManage) || ! $m->allows(WorkspacePermission::SettingsManage)))) {
            throw new ApiException('Current native publication permissions are required.', 403, 'native_banner_forbidden');
        }
        if ($connection->platform !== EcommercePlatform::Squarespace) {
            throw new \LogicException('SquarespaceInstallationVerifier only serves Squarespace connections.');
        }
        if ($connection->paused_at !== null || ! $connection->status->receivesEvents()) {
            throw ApiException::conflict('The native connection is paused or disconnected.', 'connection_changed');
        }
    }

    private function record(WebshopConnection $connection, User $actor, string $suffix, string $snippet): AuditEvent
    {
        return $this->audit->record('integration.banner_sq_'.$suffix, category: AuditCategory::Integration, resource: $connection, actorUserId: $actor->id, webshopId: $connection->webshop_id,
            metadata: ['domain' => $connection->webshop->verified_domain, 'snippet_sha256' => hash('sha256', $snippet)]);
    }
}
