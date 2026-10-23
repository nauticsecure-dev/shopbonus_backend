<?php

namespace App\Enums;

/**
 * Where the SPA should send a signed-in user next. Always computed by the
 * server from verified account state; never taken from a client-supplied URL.
 */
enum Destination: string
{
    case VerifyEmail = 'verify_email';
    case ConsumerDashboard = 'consumer_dashboard';
    case WebshopSetup = 'webshop_setup';
}
