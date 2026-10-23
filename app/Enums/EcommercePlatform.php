<?php

namespace App\Enums;

/**
 * Ecommerce platforms named on the Trello board (#1, #2). Listing a platform
 * says nothing about adapter support: capabilities are verified per provider
 * in B04 (OPEN-PROVIDER-CAPABILITIES).
 */
enum EcommercePlatform: string
{
    case WooCommerce = 'woocommerce';
    case Shopify = 'shopify';
    case Wix = 'wix';
    case BigCommerce = 'bigcommerce';
    case Ecwid = 'ecwid';
    case LightspeedC = 'lightspeed_c';
    case LightspeedE = 'lightspeed_e';
    case PrestaShop = 'prestashop';
    case OpenCart = 'opencart';
    case Shopware6 = 'shopware6';
    case Magento = 'magento';
    case Squarespace = 'squarespace';
    case Mijnwebwinkel = 'mijnwebwinkel';
    case Ccv = 'ccv';
    case Other = 'other';
}
