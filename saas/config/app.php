<?php
return [
    'name' => env('APP_NAME', 'Sahdev'), 'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false), 'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en', 'faker_locale' => 'en_US',
    'key' => env('APP_KEY'), 'cipher' => 'AES-256-CBC',
    'previous_keys' => array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    'maintenance' => ['driver' => 'file'],
];
