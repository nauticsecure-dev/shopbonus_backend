<?php

namespace Tests\Concerns;

use OpenSSLAsymmetricKey;

trait UsesFixtureRsaKeys
{
    private function fixturePrivateKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private(file_get_contents(dirname(__DIR__).'/fixtures/rsa_private.pem'));
        $this->assertNotFalse($key, 'tests/fixtures/rsa_private.pem must be a valid private key');

        return $key;
    }

    private function fixturePublicKeyPem(): string
    {
        return file_get_contents(dirname(__DIR__).'/fixtures/rsa_public.pem');
    }
}
