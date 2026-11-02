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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // The address the provider sends the member back to: the site's own (App\Support\OAuthProviders::callbackUrl():
        // APP_URL, /index.php after it where the site's links carry it), unless GOOGLE_REDIRECT_URI names another one.
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        // The address the provider sends the member back to: the site's own (App\Support\OAuthProviders::callbackUrl():
        // APP_URL, /index.php after it where the site's links carry it), unless FACEBOOK_REDIRECT_URI names another one.
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],
    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY', ''),
    ],
    'brevo' => [
        'api_key' => env('BREVO_API_KEY', ''),
    ],
    'upload18' => [
        'api_key' => env('UPLOAD18_API_KEY', ''),
        'api_url' => env('UPLOAD18_API_URL', 'https://upload18.org/api'),
        'default_cid' => env('UPLOAD18_DEFAULT_CID', '15'),
        'default_mycid' => env('UPLOAD18_DEFAULT_MYCID', '0'),
        'default_fid' => env('UPLOAD18_DEFAULT_FID', '18'),
        'allow_server_fallback' => env('UPLOAD18_ALLOW_SERVER_FALLBACK', false),
    ],
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],
];
