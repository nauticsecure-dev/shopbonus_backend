<?php

return [
    // Argon2id preserves the full Unicode passphrase accepted by the password policy.
    'driver' => env('HASH_DRIVER', 'argon2id'),
    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => 72,
    ],
    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        // Existing bcrypt hashes are verified and upgraded on successful login.
        'verify' => false,
    ],
    'rehash_on_login' => true,
];
