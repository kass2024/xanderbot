<?php

$appUrl = rtrim((string) env('APP_URL', ''), '/');
$defaultForward = $appUrl !== ''
    ? $appUrl.'/api/prescreening/inbound'
    : 'https://xanderglobalscholars.com/api/prescreening-inbound.php';

return [

    /*
    |--------------------------------------------------------------------------
    | Web-only start: invite is sent from admin (web). Students cannot type
    | "prescreening" to start. WhatsApp webhook only handles invited sessions.
    |--------------------------------------------------------------------------
    */
    'web_invite_only' => filter_var(env('PRESCREENING_WEB_INVITE_ONLY', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | On completion: row goes to prescreening_submissions (web list). Optional WA.
    |--------------------------------------------------------------------------
    */
    'completion_whatsapp_thank_you' => filter_var(env('PRESCREENING_COMPLETION_WHATSAPP_THANK_YOU', true), FILTER_VALIDATE_BOOL),
    'completion_notify_whatsapp' => filter_var(env('PRESCREENING_COMPLETION_NOTIFY_WHATSAPP', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Mode: local = VPS legacy helpers + DB | forward = HTTP to forward_url
    |--------------------------------------------------------------------------
    */
    'mode' => env('PRESCREENING_MODE', 'forward'),

    /*
    |--------------------------------------------------------------------------
    | Forward inbound WhatsApp (when mode=forward)
    |--------------------------------------------------------------------------
    | Default on VPS: same app /api/prescreening/inbound (not cPanel).
    | cPanel only if you set XANDER_PRESCREENING_URL explicitly.
    */
    'forward_enabled' => filter_var(env('PRESCREENING_FORWARD_ENABLED', true), FILTER_VALIDATE_BOOL),

    'forward_url' => env('XANDER_PRESCREENING_URL', $defaultForward),

    /*
    |--------------------------------------------------------------------------
    | Meta message template names (must match Business Manager)
    |--------------------------------------------------------------------------
    */
    'invite_template' => env('WHATSAPP_PRESCREENING_INVITE_TEMPLATE', 'xander_prescreening_invite'),
    'invite_template_lang' => env('WHATSAPP_PRESCREENING_INVITE_TEMPLATE_LANG', 'en_US'),
    'received_template' => env('WHATSAPP_PRESCREENING_RECEIVED_TEMPLATE', 'xander_prescreening_received'),
    'received_template_lang' => env('WHATSAPP_PRESCREENING_RECEIVED_TEMPLATE_LANG', 'en'),

    'forward_secret' => env('PRESCREENING_FORWARD_SECRET', ''),

    /** Keep low — Meta webhook must respond within ~10s */
    'forward_timeout' => (int) env('PRESCREENING_FORWARD_TIMEOUT', 8),

    'forward_session_timeout' => (int) env('PRESCREENING_FORWARD_SESSION_TIMEOUT', 4),

    /*
    |--------------------------------------------------------------------------
    | Local PHP helpers (dev only — leave XANDER_PRESCREENING_URL empty)
    |--------------------------------------------------------------------------
    */
    'xander_php_path' => env('XANDER_PHP_PATH', PHP_OS_FAMILY === 'Windows'
        ? 'C:/xampp/htdocs/Xander'
        : base_path('legacy/xander')),

    'staff_whatsapp' => env('PRESCREENING_STAFF_WHATSAPP', '12704387305,254711807646'),

    /** Ignored when web_invite_only=true */
    'triggers' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'PRESCREENING_TRIGGERS',
        ''
    ))))),

];
