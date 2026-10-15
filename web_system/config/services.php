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

    'ai_severity' => [
        // Archived image-service settings; AiSeverityClient never runs image triage.
        'enabled' => env('AI_SEVERITY_ENABLED', false),
        'url' => env(
            'AI_SEVERITY_SERVICE_URL',
            'http://'
            .env('AI_SEVERITY_SERVICE_HOST', '127.0.0.1')
            .':'
            .env('AI_SEVERITY_SERVICE_PORT', '8100')
        ),
        'timeout' => (int) env('AI_SEVERITY_TIMEOUT', 20),
        'retry_attempts' => (int) env('AI_SEVERITY_RETRY_ATTEMPTS', 1),
        'retry_delay_ms' => (int) env('AI_SEVERITY_RETRY_DELAY_MS', 1500),
        'dispatch' => env('AI_SEVERITY_DISPATCH', 'sync'),
        'model_name' => env('AI_SEVERITY_MODEL_NAME', 'bontoc_southern_leyte_production_candidate_external'),
        'model_version' => env('AI_SEVERITY_MODEL_VERSION', '0.3.0'),
        'require_civilian_photo_gate' => env('AI_SEVERITY_REQUIRE_CIVILIAN_PHOTO_GATE', false),
    ],

    // Enable only after the separate text dataset/model review workflow is complete.
    'ai_text' => [
        // Fail closed: reports await responder severity unless another mode is explicitly configured.
        'mode' => strtoupper((string) env('AI_TEXT_MODE', 'MANUAL_ONLY')),
        'enabled' => env('AI_TEXT_ENABLED', false),
        'url' => env('AI_TEXT_SERVICE_URL', 'http://127.0.0.1:8100'),
        'timeout' => (int) env('AI_TEXT_TIMEOUT', 10),
        'connect_timeout' => (int) env('AI_TEXT_CONNECT_TIMEOUT', 3),
        'allow_experimental' => env('AI_TEXT_ALLOW_EXPERIMENTAL', false),
        // Separate local-only evaluation target; never used by report submission.
        'test_url' => env('AI_TEXT_TEST_SERVICE_URL', 'http://127.0.0.1:8101'),
        'test_timeout' => (int) env('AI_TEXT_TEST_TIMEOUT', 90),
    ],

    'lora_ingest' => [
        'token' => env('LORA_INGEST_TOKEN'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env(
            'GOOGLE_REDIRECT_URI',
            rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/').'/auth/google/callback',
        ),
    ],

    'routing' => [
        'enabled' => env('ROUTING_SERVICE_ENABLED', env('APP_ENV') !== 'testing'),
        'url' => env('ROUTING_SERVICE_URL', 'https://router.project-osrm.org'),
        'profile' => env('ROUTING_SERVICE_PROFILE', 'driving'),
        'timeout' => (int) env('ROUTING_SERVICE_TIMEOUT', 8),
        'cache_ttl_minutes' => (int) env('ROUTING_SERVICE_CACHE_TTL_MINUTES', 10),
        'provider' => env('ROUTING_SERVICE_PROVIDER', 'OSRM Public Routing'),
    ],

];
