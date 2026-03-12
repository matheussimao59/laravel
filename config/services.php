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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mercado_livre' => [
        'client_id' => env('ML_CLIENT_ID'),
        'client_secret' => env('ML_CLIENT_SECRET'),
        'base_url' => env('ML_API_BASE_URL', 'https://api.mercadolibre.com'),
    ],

    'fiscal_provider' => [
        'token' => env('NFE_PROVIDER_TOKEN'),
        'base_url' => env('NFE_PROVIDER_BASE_URL', 'https://api.nuvemfiscal.com.br'),
        'issue_path' => env('NFE_ISSUE_PATH', '/v1/nfe'),
        'status_path_template' => env('NFE_STATUS_PATH_TEMPLATE', '/v1/nfe/{id}'),
    ],

];
