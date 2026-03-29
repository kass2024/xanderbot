<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Voice replies (FAQ / bot → customer on WhatsApp)
    |--------------------------------------------------------------------------
    | When enabled, FAQ-sourced replies also send a short MP3 via OpenAI TTS.
    | Requires services.openai.key and a public APP_URL for media links.
    */
    'voice_faq_replies' => env('CHATBOT_VOICE_FAQ_REPLIES', false),

    /*
    |--------------------------------------------------------------------------
    | Inbound voice (customer → bot)
    |--------------------------------------------------------------------------
    | Transcribe WhatsApp audio with OpenAI Whisper before FAQ / AI routing.
    */
    'transcribe_inbound_audio' => env('CHATBOT_TRANSCRIBE_AUDIO', true),

];
