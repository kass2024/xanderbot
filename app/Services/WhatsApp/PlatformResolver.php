<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformMetaConnection;
use App\Models\User;
use App\Support\WhatsAppTracker;
use Illuminate\Support\Facades\Log;

/**
 * Resolve WhatsApp phone_number_id from Meta webhooks to a PlatformMetaConnection.
 * Falls back to .env WHATSAPP_* when admin OAuth row is missing (common on VPS).
 */
class PlatformResolver
{
    public function resolve(?string $phoneNumberId): ?PlatformMetaConnection
    {
        if ($phoneNumberId === null || $phoneNumberId === '') {
            return null;
        }

        $platform = PlatformMetaConnection::where('whatsapp_phone_number_id', $phoneNumberId)->first();
        if ($platform) {
            return $platform;
        }

        $envPhoneId = trim((string) config('services.whatsapp.phone_number_id'));
        if ($envPhoneId === '' || $phoneNumberId !== $envPhoneId) {
            WhatsAppTracker::whatsapp('platform_unknown_phone_id', [
                'phone_number_id' => $phoneNumberId,
                'env_phone_id' => $envPhoneId,
            ], 'warning');

            return null;
        }

        $platform = $this->ensureFromEnv();
        if ($platform) {
            WhatsAppTracker::whatsapp('platform_auto_linked_from_env', [
                'platform_id' => $platform->id,
                'phone_number_id' => $phoneNumberId,
            ]);
        }

        return $platform;
    }

    public function ensureFromEnv(): ?PlatformMetaConnection
    {
        $phoneId = trim((string) config('services.whatsapp.phone_number_id'));
        $token = trim((string) config('services.whatsapp.access_token'));
        $wabaId = trim((string) env('WHATSAPP_BUSINESS_ID', ''));

        if ($phoneId === '' || $token === '') {
            Log::error('PlatformResolver: WHATSAPP_PHONE_NUMBER_ID or WHATSAPP_ACCESS_TOKEN missing in .env');

            return null;
        }

        $userId = (int) env('WHATSAPP_PLATFORM_USER_ID', 0);
        if ($userId <= 0) {
            $userId = (int) (User::query()->orderBy('id')->value('id') ?? 0);
        }
        if ($userId <= 0) {
            Log::error('PlatformResolver: no users table row — create an admin user first');

            return null;
        }

        $platform = PlatformMetaConnection::updateOrCreate(
            ['whatsapp_phone_number_id' => $phoneId],
            [
                'connected_by' => $userId,
                'whatsapp_business_id' => $wabaId !== '' ? $wabaId : null,
                'business_id' => trim((string) env('META_AD_ACCOUNT_ID', '')) ?: null,
            ]
        );

        $platform->storeAccessToken($token);

        return $platform->fresh();
    }
}
