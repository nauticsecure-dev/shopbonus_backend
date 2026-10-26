<?php

namespace App\Services\Notifications;

final class NotificationCatalogue
{
    /** type => audience, mandatory email, NL title, EN title. Body never copies source/customer prose. */
    public const EVENTS = [
        'account_welcome' => ['consumer', true, 'Welkom bij ShopBonus', 'Welcome to ShopBonus'],
        'email_verified' => ['consumer', false, 'E-mailadres bevestigd', 'Email address verified'],
        'cashback_credited' => ['consumer', false, 'ShopBonus-tegoed ontvangen', 'ShopBonus credit received'],
        'voucher_received' => ['consumer', true, 'Je voucher staat klaar', 'Your voucher is ready'],
        'voucher_expiring' => ['consumer', false, 'Je voucher verloopt binnenkort', 'Your voucher expires soon'],
        'voucher_used' => ['consumer', false, 'Voucher gebruikt', 'Voucher used'],
        'voucher_cancelled' => ['consumer', true, 'Voucher geannuleerd', 'Voucher cancelled'],
        'cashback_adjusted' => ['consumer', true, 'Je tegoed is aangepast', 'Your credit was adjusted'],
        'support_reply' => ['consumer', false, 'Nieuw antwoord van support', 'New support reply'],
        'favorite_merchant_offer' => ['consumer', false, 'Nieuwe aanbieding van een favoriet', 'New offer from a favorite webshop'],
        'workspace_approved' => ['workspace', true, 'Webshop goedgekeurd', 'Workspace approved'],
        'workspace_rejected' => ['workspace', true, 'Webshop afgewezen', 'Workspace rejected'],
        'offer_published' => ['workspace', false, 'Aanbieding gepubliceerd', 'Offer published'],
        'offer_expiring' => ['workspace', false, 'Aanbieding verloopt binnenkort', 'Offer expires soon'],
        'commission_changed' => ['workspace', true, 'Commissie-instelling gewijzigd', 'Commission setting changed'],
        'integration_attention' => ['workspace', false, 'Controleer je webshopkoppeling', 'Your integration needs attention'],
        'workspace_support_reply' => ['workspace', false, 'Nieuw antwoord van support', 'New support reply'],
        'workspace_review_required' => ['admin', false, 'Webshop wacht op beoordeling', 'Workspace requires review'],
        'ai_support_escalated' => ['admin', false, 'Support wacht op beoordeling', 'Support requires human review'],
        'affiliate_import_failed' => ['admin', false, 'Affiliate-import mislukt', 'Affiliate import failed'],
        'notification_delivery_failed' => ['admin', false, 'Melding kon niet worden bezorgd', 'Notification delivery failed'],
        'critical_notification_failure' => ['admin', true, 'Belangrijke melding niet beschikbaar', 'Critical notification unavailable'],
        'merchant_attention' => ['workspace', false, 'Webshopmelding', 'Workspace notification'],
    ];

    public static function optionalInApp(string $type): bool
    {
        return $type === 'favorite_merchant_offer';
    }

    /** @return array<string, string> */
    public static function content(string $event, string $locale, ?string $amount = null): array
    {
        $definition = self::EVENTS[$event] ?? throw new \LogicException('Unknown notification event.');
        $nl = $locale === 'nl';
        $title = $definition[$nl ? 2 : 3];
        $body = $nl ? 'Bekijk de actuele gegevens in je ShopBonus-account.' : 'View the current details in your ShopBonus account.';
        if ($event === 'cashback_credited' && $amount !== null) {
            $body = $nl ? $amount.' is bijgeschreven in je ShopBonus-wallet.' : $amount.' was credited to your ShopBonus wallet.';
        }

        return ['title' => $title, 'body' => $body, 'action_label' => $nl ? 'Bekijk details' : 'View details'];
    }
}
