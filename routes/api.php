<?php

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

Route::get('/webhook/diagnostic', function () {
    $envPhoneId = (string) config('services.whatsapp.phone_number_id');
    $platform = $envPhoneId !== ''
        ? \App\Models\PlatformMetaConnection::where('whatsapp_phone_number_id', $envPhoneId)->first()
        : null;

    $hitsFile = storage_path('logs/webhook-hits.log');
    $hitsTail = [];
    if (is_readable($hitsFile)) {
        $lines = file($hitsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $hitsTail = is_array($lines) ? array_slice($lines, -10) : [];
    }

    return response()->json([
        'webhook_url' => url('/api/webhook/meta'),
        'meta_console_must_subscribe' => 'messages (includes status + inbound)',
        'app_secret_configured' => (bool) config('services.whatsapp_webhook.app_secret'),
        'verify_token_configured' => (bool) config('services.whatsapp_webhook.verify_token'),
        'env_whatsapp_phone_number_id' => $envPhoneId,
        'platform_linked_in_db' => (bool) $platform,
        'platform_id' => $platform?->id,
        'prescreening_forward_enabled' => config('prescreening.forward_enabled'),
        'prescreening_forward_url' => config('prescreening.forward_url'),
        'prescreening_forward_secret_set' => (bool) config('prescreening.forward_secret'),
        'tracking_enabled' => config('tracking.whatsapp_enabled'),
        'recent_webhook_hits' => $hitsTail,
        'tail_commands' => [
            'laravel' => 'tail -f storage/logs/laravel.log | grep -iE META_WEBHOOK|delivery|prescreen',
            'whatsapp' => 'tail -f storage/logs/whatsapp-'.date('Y-m-d').'.log',
            'prescreening' => 'tail -f storage/logs/prescreening-'.date('Y-m-d').'.log',
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