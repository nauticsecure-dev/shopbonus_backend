<?php

namespace App\Services\Banners;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\BigCommerceConnector;
use App\Models\BannerInstallation;
use App\Models\WebshopConnection;
use App\Services\Consent\CookieConsents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A native cart UUID is a bearer capability. Never accepts a browser order ID or payment claim. */
class BigCommerceCartAssignments
{
    /** @param list<array<string,mixed>> $assignments */
    public function bind(BannerInstallation $installation, string $visitor, string $cartId, array $assignments, string $consentId): void
    {
        if (! config('integrations.assignment_metadata.bigcommerce_cart_bridge')) {
            throw ApiException::conflict('BigCommerce cart assignments are disabled.', 'cart_assignment_disabled');
        }
        if ($assignments === []) {
            return;
        }
        $connections = WebshopConnection::where('webshop_id', $installation->webshop_id)->where('platform', EcommercePlatform::BigCommerce)
            ->where('status', '!=', 'disconnected')->whereNull('paused_at')->get();
        if ($connections->count() !== 1) {
            throw ApiException::conflict('Configure one active BigCommerce connection.', 'cart_assignment_connection');
        }
        $connection = $connections->sole();
        $hash = $this->cartHash($connection, $cartId);
        $generation = $connection->credential_generation;
        // The server-issued assignment list already binds shop, visitor and current consent.
        $visitorHash = hash_hmac('sha256', 'banner-visitor|'.$installation->webshop_id.'|'.$visitor, (string) config('app.key'));
        $assignment = DB::table('banner_assignments')->where('token_hash', app(BannerExperiments::class)->tokenHash($assignments[0]['assignment_token']))
            ->where('visitor_hash', $visitorHash)->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if ($assignment === null) {
            throw ApiException::conflict('The assignment is no longer current.', 'cart_assignment_expired');
        }
        $existing = DB::table('banner_cart_assignments')->where('cart_hash', $hash)->first();
        if ($existing === null) {
            app(BigCommerceConnector::class)->verifyAssignmentCart($connection, $cartId);
        }
        DB::transaction(function () use ($connection, $generation, $hash, $assignment, $consentId) {
            $current = WebshopConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if (! app(CookieConsents::class)->trackingAllowed($consentId) || ! $current->acceptsData() || ! $current->webshop->isOwnershipVerified() || $current->webshop->status->value !== 'approved'
                || $current->credential_generation !== $generation || $this->identity($current) !== $this->identity($connection)
                || DB::table('banner_assignments')->where('id', $assignment->id)->whereNull('revoked_at')->where('expires_at', '>', now())->lockForUpdate()->first() === null) {
                throw ApiException::conflict('The connection or assignment changed.', 'cart_assignment_expired');
            }
            $row = DB::table('banner_cart_assignments')->where('cart_hash', $hash)->first();
            if ($row !== null && (int) $row->assignment_id !== (int) $assignment->id) {
                throw ApiException::conflict('This cart already has an assignment.', 'cart_assignment_conflict');
            }
            if ($row === null) {
                DB::table('banner_cart_assignments')->insert(['connection_id' => $current->id, 'credential_generation' => $generation,
                    'cart_hash' => $hash, 'assignment_id' => $assignment->id, 'created_at' => now()]);
            }
        });
    }

    /** Called only after the connector's native store/order identity checks.
     * @param  array<string,mixed>  $order
     */
    public function token(WebshopConnection $connection, array $order): ?string
    {
        $cart = $order['cart_id'] ?? null;
        if (! config('integrations.assignment_metadata.bigcommerce_cart_bridge') || ! is_string($cart) || ! Str::isUuid($cart) || ! $connection->acceptsData()) {
            return null;
        }
        $date = $order['date_created'] ?? null;
        if (! is_string($date) || trim($date) === '') {
            return null;
        }
        $row = DB::table('banner_cart_assignments as links')->join('banner_assignments as a', 'a.id', '=', 'links.assignment_id')
            ->join('banner_experiments as e', 'e.id', '=', 'a.experiment_id')->join('banner_campaigns as c', 'c.id', '=', 'e.campaign_id')
            ->where('links.cart_hash', $this->cartHash($connection, $cart))->where('links.connection_id', $connection->id)
            ->where('links.credential_generation', $connection->credential_generation)->where('c.webshop_id', $connection->webshop_id)
            ->whereNull('a.revoked_at')->where('a.expires_at', '>', now())
            ->select('e.public_id', 'a.visitor_hash', 'a.token_hash', 'a.expires_at', 'links.created_at')->first();
        try {
            $created = CarbonImmutable::parse($date);
        } catch (\Throwable) {
            return null;
        }
        if ($row === null || $created->lt(CarbonImmutable::parse($row->created_at)) || $created->gt(CarbonImmutable::parse($row->expires_at))) {
            return null;
        }
        $token = 'bx_'.hash_hmac('sha256', 'banner-assignment|'.$row->public_id.'|'.$row->visitor_hash, (string) config('app.key'));

        return hash_equals($row->token_hash, app(BannerExperiments::class)->tokenHash($token)) ? $token : null;
    }

    private function identity(WebshopConnection $connection): string
    {
        return $connection->external_shop_ref.'|'.$connection->webshop->verified_domain;
    }

    private function cartHash(WebshopConnection $connection, string $cart): string
    {
        return hash_hmac('sha256', 'bigcommerce-cart|'.$connection->id.'|'.$connection->credential_generation.'|'.$this->identity($connection).'|'.strtolower($cart), (string) config('app.key'));
    }
}
