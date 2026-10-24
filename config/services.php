<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'flutterwave' => [
        'public_key' => env('FLW_PUBLIC_KEY'),
        'secret_key' => env('FLW_SECRET_KEY'),
        'base_url' => env('FLW_BASE_URL', 'https://api.flutterwave.com'),
        'rave_base_url' => env('RAVE_BASE_URL', 'https://ravesandboxapi.flutterwave.com'),
    ],

    'providus' => [
        'base_url' => env('PROVIDUS_BASE_URL', ''),
        'username' => env('PROVIDUS_USERNAME'),
        'password' => env('PROVIDUS_PASSWORD'),
        'auth_signature' => env('PROVIDUS_AUTH_SIGNATURE'),
        'client_id' => env('PROVIDUS_CLIENT_ID'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'number' => env('TWILIO_NUMBER'),
    ],

];
