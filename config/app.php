<?php

return [

    'name' => env('APP_NAME', 'Paylidate'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL'),

    'timezone' => 'UTC',

    'locale' => 'en',

    'fallback_locale' => 'en',

    'faker_locale' => 'en_US',

    'cipher' => 'AES-256-CBC',

    // Generate with `php artisan key:generate`; never commit a real key.
    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    'domain' => env('DOMAIN'),

    // Base URL of the web client, used in emailed links.
    'frontend_url' => rtrim(env('FRONTEND_URL', 'https://paylidate.com'), '/'),

    // Comma separated list of staff addresses notified about withdrawals and reports.
    'admin_emails' => array_filter(explode(',', env('ADMIN_EMAILS', ''))),

];
