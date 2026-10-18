<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | Provider assigned to newly created WhatsApp accounts: "mock",
    | "unofficial_web" or "official_cloud". The mock provider needs no
    | external services and is the safe default for local development.
    |
    */

    'default_provider' => env('MESSAGING_DEFAULT_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Unofficial WhatsApp Web Provider
    |--------------------------------------------------------------------------
    |
    | Baileys is not endorsed by WhatsApp. It must be enabled explicitly and
    | is intended for learning and controlled testing with consenting test
    | accounts only. It never supports bulk messaging.
    |
    */

    'unofficial_enabled' => (bool) env('MESSAGING_UNOFFICIAL_ENABLED', false),

    'gateway' => [
        'url' => env('GATEWAY_URL', 'http://127.0.0.1:3100'),
        'token' => env('GATEWAY_API_TOKEN'),
        'webhook_secret' => env('GATEWAY_WEBHOOK_SECRET'),
        'webhook_secret_previous' => env('GATEWAY_WEBHOOK_SECRET_PREVIOUS'),
        'signature_tolerance' => (int) env('GATEWAY_SIGNATURE_TOLERANCE', 300),
        'timeout' => (int) env('GATEWAY_TIMEOUT', 15),
        'connect_timeout' => (int) env('GATEWAY_CONNECT_TIMEOUT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Official WhatsApp Business Cloud API
    |--------------------------------------------------------------------------
    |
    | Requires an authorized Meta Business account, a registered phone number
    | and a system-user access token. Nothing here is usable without them.
    |
    */

    'official' => [
        'graph_url' => env('WHATSAPP_CLOUD_GRAPH_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v25.0'),
        'access_token' => env('WHATSAPP_CLOUD_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'timeout' => (int) env('WHATSAPP_CLOUD_TIMEOUT', 15),
    ],

    'qr_ttl' => (int) env('MESSAGING_QR_TTL', 60),

    'attachments' => [
        'disk' => env('MESSAGING_ATTACHMENT_DISK', 'local'),
        'max_kilobytes' => (int) env('MESSAGING_ATTACHMENT_MAX_KB', 16384),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (days)
    |--------------------------------------------------------------------------
    |
    | Applied daily by `app:prune-records`. Messages, contacts and consent
    | records are never pruned automatically.
    |
    */

    'retention' => [
        'gateway_events_days' => (int) env('RETENTION_GATEWAY_EVENTS_DAYS', 30),
        'automation_executions_days' => (int) env('RETENTION_AUTOMATION_EXECUTIONS_DAYS', 90),
        'device_connection_events_days' => (int) env('RETENTION_DEVICE_EVENTS_DAYS', 180),
        'audit_logs_days' => (int) env('RETENTION_AUDIT_LOGS_DAYS', 365),
        'read_notifications_days' => (int) env('RETENTION_READ_NOTIFICATIONS_DAYS', 30),
    ],

];
