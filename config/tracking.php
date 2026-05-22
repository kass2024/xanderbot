<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp file tracking
    |--------------------------------------------------------------------------
    | Writes structured events to storage/logs/whatsapp-*.log.
    | Pre-screening tracking lives in the cPanel Xander project, not here.
    */
    'whatsapp_enabled' => filter_var(env('WHATSAPP_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOL),

];
