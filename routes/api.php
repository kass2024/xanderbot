<?php

use App\Http\Controllers\Api\PrescreeningInboundController;
use App\Services\Prescreening\XanderPrescreeningBridge;
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
        'cpanel_proxy_env' => 'XANDERBOT_WEBHOOK_URL on cPanel if Meta still points at xanderglobalscholars.com',
        'meta_console_must_subscribe' => 'messages (includes status + inbound)',
        'app_secret_configured' => (bool) config('services.whatsapp_webhook.app_secret'),
        'verify_token_configured' => (bool) config('services.whatsapp_webhook.verify_token'),
        'env_whatsapp_phone_number_id' => $envPhoneId,
        'platform_linked_in_db' => (bool) $platform,
        'platform_id' => $platform?->id,
        'prescreening_web_invite_only' => config('prescreening.web_invite_only'),
        'prescreening_mode' => config('prescreening.mode'),
        'prescreening_forward_enabled' => config('prescreening.forward_enabled'),
        'prescreening_forward_url' => config('prescreening.forward_url'),
        'prescreening_inbound_url' => url('/api/prescreening/inbound'),
        'prescreening_forward_secret_set' => (bool) config('prescreening.forward_secret'),
        'prescreening_invite_template' => config('prescreening.invite_template'),
        'delivery_forward_in_webhook' => str_contains(
            (string) @file_get_contents(app_path('Http/Controllers/Webhooks/MetaWebhookController.php')),
            'forwardDeliveryStatus'
        ),
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
| Pre-screening forward ping (proves VPS → cPanel delivery_status path)
|--------------------------------------------------------------------------
| GET /api/prescreening/forward-ping?secret=PRESCREENING_FORWARD_SECRET
*/
Route::post('/prescreening/inbound', [PrescreeningInboundController::class, 'handle'])
    ->name('prescreening.inbound');

Route::get('/prescreening/forward-ping', function (Request $request) {
    $secret = (string) $request->query('secret', '');
    $expected = (string) config('prescreening.forward_secret');
    if ($expected === '' || ! hash_equals($expected, $secret)) {
        return response()->json(['error' => 'Forbidden — pass ?secret=PRESCREENING_FORWARD_SECRET'], 403);
    }

    $bridge = app(XanderPrescreeningBridge::class);
    $bridge->forwardDeliveryStatus([
        'id' => 'vps-ping-test-wamid',
        'status' => 'delivered',
        'recipient_id' => '0000000000',
        'errors' => [],
    ]);

    return response()->json([
        'ok' => true,
        'message' => 'POST sent to cPanel delivery_status. On cPanel invite log, look for delivery_status_forward in whatsapp-prescreening.log.',
        'cpanel_url' => config('prescreening.forward_url'),
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