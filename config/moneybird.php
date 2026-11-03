<?php

return [
    'enabled' => (bool) env('MONEYBIRD_ENABLED', false),
    'allow_delivery' => (bool) env('MONEYBIRD_ALLOW_DELIVERY', false),
    'token' => env('MONEYBIRD_API_TOKEN', ''),
    'administration_id' => env('MONEYBIRD_ADMINISTRATION_ID', ''),
    'identity_id' => env('MONEYBIRD_IDENTITY_ID', ''),
    // Reviewed workflow must avoid collecting or reminding for liabilities already paid from merchant funding.
    'workflow_id' => env('MONEYBIRD_WORKFLOW_ID', ''),
    // Reference to the reviewed actual seller/contact/tax/ledger setup; not a claim of legal certification.
    'mapping_reference' => env('MONEYBIRD_MAPPING_REFERENCE', ''),
    // Existing funding liabilities are fixed EUR totals; invoice prices include configured tax, never add tax to wallet liabilities.
    'reward_tax_rate_id' => env('MONEYBIRD_REWARD_TAX_RATE_ID', ''),
    'reward_ledger_account_id' => env('MONEYBIRD_REWARD_LEDGER_ACCOUNT_ID', ''),
    'fee_tax_rate_id' => env('MONEYBIRD_FEE_TAX_RATE_ID', ''),
    'fee_ledger_account_id' => env('MONEYBIRD_FEE_LEDGER_ACCOUNT_ID', ''),
    // Configure per-shop contact IDs locally (keyed by webshop public ID); never create contacts from guessed company data.
    'contacts' => [],
];
