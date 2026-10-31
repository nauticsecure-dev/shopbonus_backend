<?php

// A non-empty English attributes map prevents the Dutch fallback locale from
// supplying field labels inside otherwise English validation messages.
return [
    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'current_password' => 'current password',
        'first_name' => 'first name',
        'phone' => 'phone',
        'locale' => 'locale',
        'code' => 'code',
        'name' => 'name',
        'description' => 'description',
        'amount_minor' => 'amount minor',
        'choices' => 'choices',
        'action' => 'action',
        'policy_version' => 'policy version',
    ],
];
