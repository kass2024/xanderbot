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

    return response()->json([
        'webhook_url' => url('/api/webhook/meta'),
        'app_secret_configured' => (bool) config('services.whatsapp_webhook.app_secret'),
        'verify_token_configured' => (bool) config('services.whatsapp_webhook.verify_token'),
        'env_whatsapp_phone_number_id' => $envPhoneId,
        'platform_linked_in_db' => (bool) $platform,
        'platform_id' => $platform?->id,
        'prescreening_forward_enabled' => config('prescreening.forward_enabled'),
        'prescreening_forward_url' => config('prescreening.forward_url'),
        'prescreening_forward_secret_set' => (bool) config('prescreening.forward_secret'),
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