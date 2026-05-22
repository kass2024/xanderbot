<?php

use App\Services\WhatsApp\PlatformResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Webhooks\MetaWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are stateless and use the "api" middleware group.
| No sessions, no CSRF, no cookies.
|
| NOTE: WhatsApp pre-screening lives in the cPanel project at
| C:\xampp\htdocs\Xander. xanderbot is the chatbot only; it must NOT
| route or forward inbound WhatsApp messages to pre-screening.
|
*/

/*
|--------------------------------------------------------------------------
| Health Check (Production Monitoring)
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'app' => config('app.name'),
        'env' => config('app.env'),
    ]);
});

Route::get('/webhook/test-hit', function () {
    $hit = [
        'at' => now()->toIso8601String(),
        'source' => 'manual_browser_test',
        'ip' => request()->ip(),
    ];
    @file_put_contents(
        storage_path('logs/webhook-hits.log'),
        json_encode($hit).PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    return response()->json([
        'ok' => true,
        'message' => 'Wrote one line to storage/logs/webhook-hits.log — tail that file to confirm.',
    ]);
});

Route::get('/webhook/diagnostic', function () {
    $envPhoneId = (string) config('services.whatsapp.phone_number_id');
    $platform = $envPhoneId !== ''
        ? app(PlatformResolver::class)->resolve($envPhoneId)
        : null;

    $hitsFile = storage_path('logs/webhook-hits.log');
    $hitsTail = [];
    if (is_readable($hitsFile)) {
        $lines = file($hitsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $hitsTail = is_array($lines) ? array_slice($lines, -10) : [];
    }

    return response()->json([
        'webhook_url' => url('/api/webhook/meta'),
        'meta_console_set_callback_to' => 'https://xanderbot.site/api/webhook/meta',
        'meta_console_must_subscribe' => 'messages (includes status + inbound)',
        'app_secret_configured' => (bool) config('services.whatsapp_webhook.app_secret'),
        'verify_token_configured' => (bool) config('services.whatsapp_webhook.verify_token'),
        'env_whatsapp_phone_number_id' => $envPhoneId,
        'platform_linked_in_db' => (bool) $platform,
        'platform_id' => $platform?->id,
        'tracking_enabled' => config('tracking.whatsapp_enabled'),
        'recent_webhook_hits' => $hitsTail,
        'tail_commands' => [
            'laravel' => 'tail -f storage/logs/laravel.log',
            'whatsapp' => 'tail -f storage/logs/whatsapp-'.date('Y-m-d').'.log',
            'webhook' => 'tail -f storage/logs/webhook.log',
            'hits' => 'tail -f storage/logs/webhook-hits.log',
        ],
        'php_fpm' => PHP_SAPI,
    ]);
});


/*
|--------------------------------------------------------------------------
| WhatsApp Webhook (Meta Cloud API)
|--------------------------------------------------------------------------
|
| IMPORTANT:
| - Must be publicly accessible
| - Must return 200 within 10 seconds
| - Must NOT use auth middleware
|
*/

Route::prefix('webhook')->group(function () {

    // Webhook verification (Meta GET request)
    Route::get('/meta', [MetaWebhookController::class, 'verify'])
        ->name('webhook.meta.verify');

    // Incoming events (Meta POST request)
    Route::post('/meta', [MetaWebhookController::class, 'handle'])
        ->name('webhook.meta.handle');

});


/*
|--------------------------------------------------------------------------
| Protected API Routes (Optional Future Expansion)
|--------------------------------------------------------------------------
|
| For internal API usage (dashboard, admin, etc.)
|
*/

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/user', function (Request $request) {
        return response()->json($request->user());
    });

});
