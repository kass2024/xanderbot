<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Routing Priority fixed 
    |--------------------------------------------------------------------------
    | Order of agents for escalation (level 1 → level 2 → ...)
    */
    'agent_priority' => explode(',', env('CHAT_AGENT_PRIORITY', '4,5')),

    /*
    |--------------------------------------------------------------------------
    | Agent Response Timeout (seconds)
    |--------------------------------------------------------------------------
    | After this time → reassign to next agent
    */
    'agent_timeout' => env('CHAT_AGENT_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Bot Fallback Timeout (seconds)
    |--------------------------------------------------------------------------
    | After this time → return conversation to AI
    */
    'bot_fallback_timeout' => env('CHAT_BOT_FALLBACK_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Agent silence (seconds)
    |--------------------------------------------------------------------------
    | If the last message in the thread is from the customer and this many
    | seconds pass with no new reply, the conversation returns to the bot.
    | Defaults to bot_fallback_timeout when CHAT_AGENT_SILENCE_TIMEOUT is unset.
    */
    'agent_silence_timeout' => env('CHAT_AGENT_SILENCE_TIMEOUT', null),

    /*
    |--------------------------------------------------------------------------
    | No agent session (seconds)
    |--------------------------------------------------------------------------
    | Human mode but no agent could be assigned — release to bot after this wait.
    */
    'no_agent_session_timeout' => (int) env('CHAT_NO_AGENT_SESSION_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Enable Auto Fallback to Bot
    |--------------------------------------------------------------------------
    */
    'enable_bot_fallback' => env('CHAT_ENABLE_BOT_FALLBACK', true),

    /*
    |--------------------------------------------------------------------------
    | Enable Agent Reassignment
    |--------------------------------------------------------------------------
    */
    'enable_agent_reassignment' => env('CHAT_ENABLE_AGENT_REASSIGNMENT', true),

    /*
    |--------------------------------------------------------------------------
    | Default Escalation Level
    |--------------------------------------------------------------------------
    */
    'default_escalation_level' => 1,

];