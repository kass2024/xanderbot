<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Forward inbound WhatsApp to cPanel Xander (no remote DB on VPS)
    |--------------------------------------------------------------------------
    | Meta webhook stays: https://xanderbot.site/api/webhook/meta
    | cPanel handles DB + prescreening logic at forward_url.
    */
    'forward_url' => env('XANDER_PRESCREENING_URL', 'https://xanderglobalscholars.com/api/prescreening-inbound.php'),

    'forward_secret' => env('PRESCREENING_FORWARD_SECRET', ''),

    'forward_timeout' => (int) env('PRESCREENING_FORWARD_TIMEOUT', 25),

    /*
    |--------------------------------------------------------------------------
    | Local PHP helpers (dev only — leave XANDER_PRESCREENING_URL empty)
    |--------------------------------------------------------------------------
    */
    'xander_php_path' => env('XANDER_PHP_PATH', PHP_OS_FAMILY === 'Windows'
        ? 'C:/xampp/htdocs/Xander'
        : base_path('legacy/xander')),

    'staff_whatsapp' => env('PRESCREENING_STAFF_WHATSAPP', '12704387305,254711807646'),

];
