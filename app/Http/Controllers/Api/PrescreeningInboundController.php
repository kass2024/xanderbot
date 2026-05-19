<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Prescreening\XanderPrescreeningBridge;
use App\Support\WhatsAppTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Same contract as cPanel prescreening-inbound.php — can run on xanderbot VPS.
 */
class PrescreeningInboundController extends Controller
{
    public function __construct(
        protected XanderPrescreeningBridge $bridge
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->authorizeForward($request)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $action = strtolower(trim((string) $request->input('action', 'handle')));
        $from = preg_replace('/\D+/', '', (string) $request->input('from', '')) ?: '';

        if ($from === '') {
            return response()->json(['error' => 'Missing from'], 422);
        }

        return match ($action) {
            'active_session' => $this->activeSession($from),
            'delivery_status' => $this->deliveryStatus($request),
            'handle' => $this->inboundMessage($from, $request),
            default => response()->json(['error' => 'Unknown action'], 400),
        };
    }

    protected function authorizeForward(Request $request): bool
    {
        $expected = (string) config('prescreening.forward_secret');
        if ($expected === '') {
            return false;
        }

        $secret = (string) ($request->header('X-Xander-Forward-Secret')
            ?: $request->input('secret', ''));

        return $secret !== '' && hash_equals($expected, $secret);
    }

    protected function activeSession(string $from): JsonResponse
    {
        $info = $this->bridge->activeSessionInfo($from);

        return response()->json([
            'active' => (bool) ($info['active'] ?? false),
            'step' => $info['step'] ?? null,
        ]);
    }

    protected function deliveryStatus(Request $request): JsonResponse
    {
        WhatsAppTracker::prescreening('delivery_status_local', [
            'status' => $request->input('status'),
            'wamid' => $request->input('wamid'),
            'recipient_id' => $request->input('recipient_id'),
        ]);

        return response()->json(['recorded' => true]);
    }

    protected function inboundMessage(string $from, Request $request): JsonResponse
    {
        $message = $request->input('message');
        if (! is_array($message)) {
            return response()->json(['handled' => false, 'error' => 'Missing message'], 422);
        }

        $handled = $this->bridge->processInboundLocal($from, $message);
        WhatsAppTracker::prescreening('inbound_local_api', [
            'from' => $from,
            'handled' => $handled,
            'type' => $message['type'] ?? null,
        ]);

        return response()->json(['handled' => $handled]);
    }
}
