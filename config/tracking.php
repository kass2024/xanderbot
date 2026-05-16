<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp / pre-screening file tracking
    |--------------------------------------------------------------------------
    | Writes to storage/logs/whatsapp-*.log and prescreening-*.log
    */
    'whatsapp_enabled' => filter_var(env('WHATSAPP_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOL),

];
