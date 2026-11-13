<?php
return [
    'registration' => env('REGISTRATION_ENABLED', false),
    'thin_connector_preview' => env('THIN_CONNECTOR_PREVIEW', false),
    'release_public_key' => env('RELEASE_PUBLIC_KEY'),
    'billing_url' => rtrim(env('BILLING_WHMCS_URL', ''), '/'),
    'billing_identifier' => env('BILLING_API_IDENTIFIER'), 'billing_secret' => env('BILLING_API_SECRET'),
    'billing_webhook_secret' => env('BILLING_WEBHOOK_SECRET'),
    'billing_currency_id' => (int) env('BILLING_CURRENCY_ID', 1),
    'billing_currency' => env('BILLING_CURRENCY', 'INR'),
    'tenant_host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
    'tenant_port' => env('TENANT_DB_PORT', env('DB_PORT', '5432')),
    'tenant_sslmode' => env('TENANT_DB_SSLMODE', 'prefer'),
    'retention_days' => (int) env('CONVERSATION_RETENTION_DAYS', 30),
];
