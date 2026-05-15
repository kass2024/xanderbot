<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Path to legacy Xander PHP app (shared DB + prescreening helpers)
    |--------------------------------------------------------------------------
    */
    // Linux production: /var/www/html/Xander or /var/www/Xander — set in .env
    'xander_php_path' => env('XANDER_PHP_PATH', PHP_OS_FAMILY === 'Windows'
        ? 'C:/xampp/htdocs/Xander'
        : '/var/www/html/Xander'),

    'staff_whatsapp' => env('PRESCREENING_STAFF_WHATSAPP', '12704387305,254711807646'),

];
