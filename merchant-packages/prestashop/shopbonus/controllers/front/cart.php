<?php

/** Guest/customer cookie owns the cart. No request cart/customer ID is accepted. */
class ShopbonusCartModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, no-store, max-age=0');
        header('Vary: Cookie');
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            exit('{}');
        }
        $snapshot = $this->module->cartSnapshot();
        if ($snapshot === null) {
            http_response_code(409);
        }
        exit(json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
