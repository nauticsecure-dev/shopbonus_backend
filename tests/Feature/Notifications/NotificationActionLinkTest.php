<?php

namespace Tests\Feature\Notifications;

use App\Models\CentralNotification;
use App\Services\Notifications\NotificationMail;
use App\Support\FrontendPath;
use Tests\TestCase;

class NotificationActionLinkTest extends TestCase
{
    public function test_default_identity_destinations_render_existing_frontend_routes(): void
    {
        $defaults = require base_path('config/shopbonus.php');
        config(['shopbonus.identity.frontend_paths' => $defaults['identity']['frontend_paths']]);
        $expected = [
            'consumer_benefits' => '/mijn-voordelen',
            'consumer_wallet' => '/mijn-voordelen?tab=transacties',
            'consumer_vouchers' => '/mijn-voordelen?tab=vouchers',
            'workspace_home' => '/dashboard?workspace=shop_fixture',
            'workspace_wallet' => '/dashboard?workspace=shop_fixture',
            'workspace_offers' => '/dashboard/offers?workspace=shop_fixture',
            'workspace_banners' => '/dashboard/banners?workspace=shop_fixture',
            'workspace_integrations' => '/dashboard/app-store?workspace=shop_fixture',
            'admin_home' => '/admin',
        ];
        foreach ($expected as $key => $target) {
            $path = FrontendPath::to($key, ['workspace' => 'shop_fixture']);
            $this->assertSame($target, $path);
            $this->assertStringContainsString('https://shopbonus.example'.$target, $this->render($path)['text']);
        }
    }

    private function render(string $path, bool $workspace = false): array
    {
        config(['shopbonus.identity.frontend_url' => 'https://shopbonus.example']);
        $notification = new CentralNotification;
        $notification->data = ['title' => 'Details', 'body' => 'View your account.', 'action_label' => 'Open', 'locale' => 'en', 'action_route' => $path];
        if ($workspace) {
            $notification->recipient_type = 'workspace';
            $notification->data = [...$notification->data, 'workspace_id' => 'shop_fixture'];
        }

        return app(NotificationMail::class)->render($notification);
    }

    public function test_email_links_use_real_frontend_pages_and_keep_recipient_context(): void
    {
        foreach (['/app/benefits' => '/mijn-voordelen', '/app/workspaces?workspace=shop_fixture' => '/dashboard?workspace=shop_fixture', '/app/platform' => '/admin', '/app/support?conversation=thread_fixture' => '/app/support?conversation=thread_fixture'] as $source => $target) {
            $views = $this->render($source);
            $this->assertStringContainsString('https://shopbonus.example'.$target, $views['html']);
            $this->assertStringContainsString('https://shopbonus.example'.$target, $views['text']);
        }
    }

    public function test_operator_can_override_the_destination_without_losing_workspace(): void
    {
        config(['notifications.action_paths./app/workspaces' => '/dashboard/notifications']);
        $this->assertStringContainsString('https://shopbonus.example/dashboard/notifications?workspace=shop_fixture', $this->render('/app/workspaces?workspace=shop_fixture')['text']);
    }

    public function test_workspace_support_opens_the_authorized_workspace_conversation(): void
    {
        $this->assertStringContainsString('https://shopbonus.example/dashboard/support?conversation=thread_fixture&workspace=shop_fixture', $this->render('/app/support?conversation=thread_fixture', true)['text']);
    }

    public function test_configuration_cannot_redirect_to_an_external_origin(): void
    {
        config(['notifications.action_paths./app/benefits' => '//attacker.example']);
        $this->expectException(\LogicException::class);
        $this->render('/app/benefits');
    }

    public function test_configuration_cannot_add_tracking_parameters(): void
    {
        config(['notifications.action_paths./app/benefits' => '/mijn-voordelen?utm_source=email']);
        $this->expectException(\LogicException::class);
        $this->render('/app/benefits');
    }
}
