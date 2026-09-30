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

    'meta_whatsapp' => [
        'api_version' => env('META_WHATSAPP_API_VERSION', 'v18.0'),

        /*
         * The app secret Meta signs webhook payloads with.
         *
         * `POST /webhooks/whatsapp` is a public, unauthenticated endpoint that
         * creates conversations, messages and contacts. Without this, anything
         * that can reach the server can post a payload and have it processed as
         * though Meta sent it.
         *
         * Left null it stays unset, and the endpoint keeps accepting payloads —
         * turning verification on by default would take inbound WhatsApp down
         * for a running system the moment this shipped. Every unverified event
         * is recorded as such and the Integrations page says so out loud.
         */
        'app_secret' => env('META_WHATSAPP_APP_SECRET'),
    ],

    'meta_instagram' => [
        'api_version' => env('META_INSTAGRAM_API_VERSION', 'v18.0'),
    ],

    'openai' => [
        'api_base' => env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
    ],

];
