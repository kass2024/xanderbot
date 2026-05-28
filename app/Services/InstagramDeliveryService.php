<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\Creative;
use Exception;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstagramDeliveryService
{
    public function __construct(
        protected MetaAdsService $meta,
    ) {}

    /**
     * Repair all synced campaigns (via ad sets), ad sets, creatives, and ads for Instagram delivery.
     *
     * @return array{
     *     campaigns: int,
     *     adsets: array{updated: int, skipped: int, failed: int},
     *     creatives: array{updated: int, skipped: int, failed: int},
     *     ads: array{updated: int, skipped: int, failed: int},
     *     errors: list<string>
     * }
     */
    public function repairAll(bool $forceAdSets = true): array
    {
        $this->assertInstagramConfigured();

        $placementMap = $this->loadAccountPlacementMap();

        $stats = [
            'campaigns' => Campaign::query()->whereNotNull('meta_id')->count(),
            'adsets' => ['updated' => 0, 'skipped' => 0, 'failed' => 0],
            'creatives' => ['updated' => 0, 'skipped' => 0, 'failed' => 0],
            'ads' => ['updated' => 0, 'skipped' => 0, 'failed' => 0],
            'errors' => [],
        ];

        AdSet::query()
            ->whereNotNull('meta_id')
            ->with('campaign.adAccount')
            ->orderBy('id')
            ->each(function (AdSet $adSet) use (&$stats, $forceAdSets, $placementMap) {
                try {
                    $force = $forceAdSets || $this->adSetLacksInstagramDelivery($adSet, $placementMap);
                    if ($this->repairAdSet($adSet, $force)) {
                        $stats['adsets']['updated']++;
                    } else {
                        $stats['adsets']['skipped']++;
                    }
                } catch (Throwable $e) {
                    $stats['adsets']['failed']++;
                    $stats['errors'][] = 'Ad set '.$adSet->name.': '.$e->getMessage();
                    Log::warning('IG_REPAIR_ADSET_FAILED', ['adset_id' => $adSet->id, 'error' => $e->getMessage()]);
                }
            });

        Creative::query()
            ->whereNotNull('meta_id')
            ->with(['adset.campaign.adAccount', 'campaign.adAccount'])
            ->orderBy('id')
            ->each(function (Creative $creative) use (&$stats) {
                try {
                    if ($this->repairCreative($creative, true)) {
                        $stats['creatives']['updated']++;
                    } else {
                        $stats['creatives']['skipped']++;
                    }
                } catch (Throwable $e) {
                    $stats['creatives']['failed']++;
                    $stats['errors'][] = 'Creative '.$creative->name.': '.$e->getMessage();
                    Log::warning('IG_REPAIR_CREATIVE_FAILED', ['creative_id' => $creative->id, 'error' => $e->getMessage()]);
                }
            });

        Ad::query()
            ->whereNotNull('meta_ad_id')
            ->with(['creative', 'adSet.campaign.adAccount'])
            ->orderBy('id')
            ->each(function (Ad $ad) use (&$stats) {
                try {
                    if ($this->repairAd($ad, false)) {
                        $stats['ads']['updated']++;
                    } else {
                        $stats['ads']['skipped']++;
                    }
                } catch (Throwable $e) {
                    $stats['ads']['failed']++;
                    $stats['errors'][] = 'Ad '.$ad->name.': '.$e->getMessage();
                    Log::warning('IG_REPAIR_AD_FAILED', ['ad_id' => $ad->id, 'error' => $e->getMessage()]);
                }
            });

        $this->backfillInstagramEnabledFlags();

        return $stats;
    }

    /**
     * Mark ads as IG-enabled when creative JSON already has instagram_user_id (UI status).
     */
    public function backfillInstagramEnabledFlags(): int
    {
        $count = 0;

        Ad::query()
            ->whereNotNull('meta_ad_id')
            ->with('creative')
            ->orderBy('id')
            ->each(function (Ad $ad) use (&$count) {
                if ($ad->instagram_enabled_at) {
                    return;
                }

                if (! $ad->creative || ! $this->creativeHasInstagramActor($ad->creative)) {
                    return;
                }

                $this->markAdInstagramEnabled($ad);
                $count++;
            });

        return $count;
    }

    public function summaryMessage(array $stats): string
    {
        $parts = [
            $stats['campaigns'].' campaign(s) in account',
            $stats['adsets']['updated'].' ad set(s) updated on Meta',
            $stats['creatives']['updated'].' creative(s) rebuilt with Instagram',
            $stats['ads']['updated'].' ad(s) linked to IG creatives',
        ];

        $failed = $stats['adsets']['failed'] + $stats['creatives']['failed'] + $stats['ads']['failed'];
        if ($failed > 0) {
            $parts[] = $failed.' item(s) failed';
        }

        return implode('. ', $parts).'. IG impressions may take a few hours to appear.';
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        $diagnosis = $this->meta->diagnoseInstagramConnection();

        return array_merge($diagnosis, [
            'entities' => [
                'campaigns' => Campaign::query()->whereNotNull('meta_id')->count(),
                'adsets' => AdSet::query()->whereNotNull('meta_id')->count(),
                'creatives' => Creative::query()->whereNotNull('meta_id')->count(),
                'ads' => Ad::query()->whereNotNull('meta_ad_id')->count(),
            ],
            'new_ads' => [
                'adset_placements' => 'Facebook + Instagram on create (automatic & manual)',
                'creative' => 'instagram_user_id on Meta creative create',
                'ad' => 'IG-enabled creative preferred on create',
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function assertInstagramConfigured(?string $pageId = null): void
    {
        if ($this->meta->resolveInstagramUserId($pageId) === null) {
            $diag = $this->meta->diagnoseInstagramConnection($pageId);
            $page = $diag['page_id'] ?: '(META_PAGE_ID not set)';

            throw new Exception(
                'No Instagram account found for Facebook Page '.$page.'. '
                .'Link the Page to Instagram in Meta Business Suite, assign the Page to your Business/system user, '
                .'or set META_INSTAGRAM_USER_ID in .env. Run: php artisan meta:verify-instagram'
            );
        }
    }

    /**
     * Update Meta + local ad set targeting to include Instagram.
     */
    public function repairAdSet(AdSet $adSet, bool $forceMeta = false): bool
    {
        if (empty($adSet->meta_id)) {
            return false;
        }

        $metaChanged = $this->meta->ensureAdSetTargetsInstagram((string) $adSet->meta_id, $forceMeta);

        $localTargeting = is_array($adSet->targeting) ? $adSet->targeting : [];
        $patched = $this->meta->targetingWithFacebookAndInstagram($localTargeting);
        $localChanged = json_encode($patched) !== json_encode($localTargeting);

        if ($localChanged) {
            $adSet->update(['targeting' => $patched]);
        }

        return $metaChanged || $localChanged;
    }

    /**
     * Recreate Meta creative with instagram_user_id and update local record.
     */
    public function repairCreative(Creative $creative, bool $force = false): bool
    {
        if (empty($creative->meta_id)) {
            return false;
        }

        $adSet = $this->resolveAdSetForCreative($creative);
        $accountId = $this->resolveAccountIdForCreative($creative, $adSet);

        if ($accountId === null) {
            throw new Exception('No Meta ad account for this creative.');
        }

        $stored = is_array($creative->json_payload) ? $creative->json_payload : [];
        $spec = is_array($stored['object_story_spec'] ?? null) ? $stored['object_story_spec'] : [];
        if (! $force && ! empty($spec['instagram_user_id'])) {
            return false;
        }

        $url = $this->meta->normalizeLandingUrlForMeta(
            (string) ($creative->destination_url ?? ''),
            false
        );

        if ($url === '' && empty($spec['link_data']['link'])) {
            throw new Exception('Creative has no destination URL.');
        }

        $newCreativeId = $this->createInstagramCreativeOnMeta($accountId, $creative, $adSet, $url);
        $inlineSpec = $this->buildInlineLinkCreativeSpec($creative, $adSet, $url);

        if (empty($inlineSpec['object_story_spec']['instagram_user_id'])) {
            throw new Exception('Could not build an Instagram-enabled creative.');
        }

        $creative->update([
            'meta_id' => $newCreativeId,
            'json_payload' => [
                'name' => $creative->name,
                'object_story_spec' => $inlineSpec['object_story_spec'],
            ],
        ]);

        Log::info('IG_REPAIR_CREATIVE_OK', [
            'creative_id' => $creative->id,
            'meta_creative_id' => $newCreativeId,
        ]);

        return true;
    }

    /**
     * Repair ad set, optional creative rebuild, and attach IG creative to the live Meta ad.
     *
     * @param  bool  $recreateCreative  When false (bulk run after creatives pass), only attaches the current creative meta_id.
     */
    public function repairAd(Ad $ad, bool $recreateCreative = true): bool
    {
        $ad->loadMissing(['creative', 'adSet.campaign.adAccount']);

        if (empty($ad->meta_ad_id)) {
            return false;
        }

        if (! $ad->adSet?->meta_id) {
            throw new Exception('Ad set is not synced with Meta.');
        }

        if (! $ad->creative) {
            throw new Exception('Creative is missing.');
        }

        $stored = is_array($ad->creative->json_payload) ? $ad->creative->json_payload : [];
        $spec = is_array($stored['object_story_spec'] ?? null) ? $stored['object_story_spec'] : [];
        $pageId = (string) ($spec['page_id'] ?? config('services.meta.page_id', ''));
        $this->assertInstagramConfigured($pageId);

        $this->repairAdSet($ad->adSet, true);

        if ($recreateCreative) {
            $this->repairCreative($ad->creative, true);
        }

        $ad->creative->refresh();

        $creativeMetaId = (string) $ad->creative->meta_id;
        if ($creativeMetaId === '') {
            throw new Exception('Creative has no Meta ID after repair.');
        }

        $response = $this->meta->attachCreativeToAd((string) $ad->meta_ad_id, $creativeMetaId);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'Meta failed to attach Instagram creative.');
        }

        $this->markAdInstagramEnabled($ad);

        Log::info('IG_REPAIR_AD_OK', [
            'ad_id' => $ad->id,
            'meta_ad_id' => $ad->meta_ad_id,
            'creative_meta_id' => $creativeMetaId,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildInlineLinkCreativeSpec(Creative $creative, ?AdSet $adset, string $landingUrl): array
    {
        $stored = is_array($creative->json_payload) ? $creative->json_payload : [];
        $spec = is_array($stored['object_story_spec'] ?? null) ? $stored['object_story_spec'] : [];
        $linkData = is_array($spec['link_data'] ?? null) ? $spec['link_data'] : [];

        $pageId = (string) ($spec['page_id'] ?? config('services.meta.page_id', ''));

        if ($pageId === '' && $adset && ! empty($adset->meta_id)) {
            $metaAdSet = $this->meta->getAdSet((string) $adset->meta_id);
            $po = $metaAdSet['promoted_object'] ?? null;
            if (is_array($po) && ! empty($po['page_id'])) {
                $pageId = (string) $po['page_id'];
            }
        }

        $linkData['link'] = $landingUrl;
        $linkData['message'] = $this->sanitizeLinkMessage(
            (string) ($linkData['message'] ?? $creative->body ?? '')
        );
        $linkData['name'] = $linkData['name'] ?? ($creative->headline ?? $creative->name);

        if (empty($linkData['image_hash']) && ! empty($creative->image_hash)) {
            $linkData['image_hash'] = $creative->image_hash;
        }

        $ctaType = $creative->call_to_action ?: 'LEARN_MORE';
        $linkData['call_to_action'] = [
            'type' => $ctaType,
            'value' => ['link' => $landingUrl],
        ];

        $objectStorySpec = [
            'page_id' => $pageId,
            'link_data' => $linkData,
        ];

        $ig = trim((string) ($spec['instagram_user_id'] ?? ''));
        if ($ig === '') {
            $ig = $this->meta->resolveInstagramUserId($pageId) ?? '';
        }
        if ($ig !== '') {
            $objectStorySpec['instagram_user_id'] = $ig;
        }

        return [
            'name' => (string) ($creative->name ?? 'Ad Creative'),
            'object_story_spec' => $objectStorySpec,
        ];
    }

    public function createInstagramCreativeOnMeta(
        string $accountId,
        Creative $creative,
        ?AdSet $adset,
        ?string $landingUrl = null
    ): string {
        $url = $landingUrl ?? $this->meta->normalizeLandingUrlForMeta(
            (string) ($creative->destination_url ?? ''),
            false
        );

        $creativePayload = [
            'name' => (string) ($creative->name ?? 'Creative').' IG '.now()->format('YmdHis'),
            'object_story_spec' => $this->buildInlineLinkCreativeSpec($creative, $adset, $url)['object_story_spec'],
        ];

        $created = $this->meta->createCreative($accountId, $creativePayload);

        if (empty($created['id'])) {
            throw new Exception('Meta creative creation failed.');
        }

        return (string) $created['id'];
    }

    protected function sanitizeLinkMessage(string $message): string
    {
        $message = trim($message);
        $message = preg_replace('/\s*Destination\s+URL\s*\r?\n.*$/is', '', $message) ?? $message;

        return trim($message);
    }

    protected function resolveAdSetForCreative(Creative $creative): ?AdSet
    {
        if ($creative->relationLoaded('adset') && $creative->adset) {
            return $creative->adset;
        }

        if ($creative->adset_id) {
            return AdSet::query()->find($creative->adset_id);
        }

        if ($creative->campaign_id) {
            return AdSet::query()
                ->where('campaign_id', $creative->campaign_id)
                ->whereNotNull('meta_id')
                ->first();
        }

        return null;
    }

    protected function resolveAccountIdForCreative(Creative $creative, ?AdSet $adSet): ?string
    {
        $raw = $adSet?->campaign?->adAccount?->meta_id
            ?? $creative->campaign?->adAccount?->meta_id
            ?? AdAccount::query()->whereNotNull('meta_id')->value('meta_id');

        if (! $raw) {
            return null;
        }

        $accountId = (string) $raw;

        return str_starts_with($accountId, 'act_') ? $accountId : 'act_'.$accountId;
    }

    /**
     * @param  array<string, mixed>  $targeting
     */
    protected function adSetMissingInstagram(array $targeting): bool
    {
        $platforms = $targeting['publisher_platforms'] ?? [];

        return ! is_array($platforms) || ! in_array('instagram', $platforms, true);
    }

    protected function resolveAccountIdForAd(Ad $ad): ?string
    {
        $ad->loadMissing('adSet.campaign.adAccount');

        return $this->resolveAccountIdForCreative($ad->creative ?? new Creative(), $ad->adSet);
    }

    /**
     * @return array<string, array<string, array{impressions: int, clicks: int, spend: float}>>
     */
    protected function loadAccountPlacementMap(): array
    {
        $raw = AdAccount::query()->whereNotNull('meta_id')->value('meta_id')
            ?? config('services.meta.ad_account_id');

        if (! $raw) {
            return [];
        }

        try {
            return $this->meta->getAdPlacementInsightsMap((string) $raw, 'maximum');
        } catch (Throwable $e) {
            Log::warning('IG_REPAIR_PLACEMENT_MAP_FAILED', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<string, array<string, array{impressions: int, clicks: int, spend: float}>>  $placementMap
     */
    protected function adSetLacksInstagramDelivery(AdSet $adSet, array $placementMap): bool
    {
        $adSet->loadMissing(['ads']);

        foreach ($adSet->ads as $ad) {
            if (empty($ad->meta_ad_id)) {
                continue;
            }

            $delivery = $placementMap[(string) $ad->meta_ad_id] ?? [];
            $igImpressions = (int) ($delivery['instagram']['impressions'] ?? 0);

            if ($igImpressions > 0) {
                return false;
            }
        }

        return $adSet->ads->whereNotNull('meta_ad_id')->isNotEmpty();
    }

    public function clearInsightsCaches(?string $accountId = null): void
    {
        $accountId = $accountId ?? config('services.meta.ad_account_id');
        if (! $accountId) {
            return;
        }

        $hash = md5((string) $accountId);
        \Illuminate\Support\Facades\Cache::forget('meta_ad_insights_maps:'.$hash);
        foreach (['maximum', 'today', 'last_7d', 'last_3d'] as $preset) {
            \Illuminate\Support\Facades\Cache::forget('meta_ad_placement_maps:'.md5($hash.':'.$preset));
        }
        \Illuminate\Support\Facades\Cache::forget('meta_ad_placement_maps:'.$hash);
    }

    public function markAdInstagramEnabled(Ad $ad): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('ads', 'instagram_enabled_at')) {
            $ad->update(['instagram_enabled_at' => now()]);
            $ad->instagram_enabled_at = now();
        }
    }

    public function creativeHasInstagramActor(Creative $creative): bool
    {
        $stored = is_array($creative->json_payload) ? $creative->json_payload : [];
        $spec = is_array($stored['object_story_spec'] ?? null) ? $stored['object_story_spec'] : [];

        return trim((string) ($spec['instagram_user_id'] ?? '')) !== '';
    }

    /**
     * Full Instagram delivery audit for UI + insight page.
     *
     * @param  array<string, array<string, mixed>>  $placementDelivery
     * @return array<string, mixed>
     */
    public function auditAdDelivery(Ad $ad, array $placementDelivery = [], bool $verifyMetaLive = false): array
    {
        $ad->loadMissing(['adSet.campaign.adAccount']);

        if ($ad->creative_id) {
            $ad->unsetRelation('creative');
            $ad->load('creative');
        }

        $recentDelivery = [];
        $recentPreset = 'last_7d';
        if ($ad->created_at && $ad->created_at->greaterThan(now()->subDays(3))) {
            $recentPreset = 'today';
        }

        if ($verifyMetaLive && ! empty($ad->meta_ad_id)) {
            $accountId = $this->resolveAccountIdForAd($ad);
            if ($accountId) {
                try {
                    $recentMap = $this->meta->getAdPlacementInsightsMap($accountId, $recentPreset);
                    $recentDelivery = $recentMap[(string) $ad->meta_ad_id] ?? [];
                } catch (Throwable $e) {
                    Log::warning('IG_AUDIT_RECENT_PLACEMENT_FAILED', ['ad_id' => $ad->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $ig = $placementDelivery['instagram'] ?? [];
        $igRecent = $recentDelivery['instagram'] ?? [];
        $fb = $placementDelivery['facebook'] ?? [];
        $igImpressionsLifetime = (int) ($ig['impressions'] ?? 0);
        $igImpressionsRecent = (int) ($igRecent['impressions'] ?? 0);
        $igImpressions = max($igImpressionsLifetime, $igImpressionsRecent);
        $fbImpressions = (int) ($fb['impressions'] ?? 0);
        $igClicks = (int) ($ig['clicks'] ?? 0);
        $fbClicks = (int) ($fb['clicks'] ?? 0);
        $igSpend = (float) ($ig['spend'] ?? 0);
        $fbSpend = (float) ($fb['spend'] ?? 0);
        $an = $placementDelivery['audience_network'] ?? [];
        $anImpressions = (int) ($an['impressions'] ?? 0);

        $targetsIg = $ad->adSet?->targetsInstagram() ?? false;
        $creativeHasIg = $ad->creative ? $this->creativeHasInstagramActor($ad->creative) : false;
        $enabledAt = $ad->instagram_enabled_at ?? null;
        $configuredOnMeta = $targetsIg && $creativeHasIg;
        $markedEnabled = $enabledAt !== null;

        $metaCreativeHasIg = null;
        $metaCreativeError = null;

        if ($verifyMetaLive && ! empty($ad->meta_ad_id)) {
            try {
                $live = $this->meta->getAdWithCreativeSpec((string) $ad->meta_ad_id);
                $oss = $live['creative']['object_story_spec'] ?? null;
                if (is_string($oss)) {
                    $decoded = json_decode($oss, true);
                    $oss = is_array($decoded) ? $decoded : [];
                }
                $metaCreativeHasIg = is_array($oss) && ! empty($oss['instagram_user_id']);
            } catch (Throwable $e) {
                $metaCreativeError = $e->getMessage();
            }
        }

        $status = 'not_configured';
        $statusLabel = 'Not configured for Instagram';

        $deliveryWarning = null;

        if ($igImpressions > 0) {
            $status = 'live';
            $statusLabel = 'Delivering on Instagram ('.number_format($igImpressions).' impressions)';
        } elseif ($markedEnabled || $configuredOnMeta || $metaCreativeHasIg === true) {
            $status = 'enabled';
            $statusLabel = 'IG enabled on Meta — waiting for impressions';
            if ($igImpressionsRecent === 0 && $igImpressionsLifetime === 0) {
                if ($anImpressions > 0 && $fbImpressions > 0) {
                    $deliveryWarning = 'Ad is new but Meta reports Audience Network + Facebook, Instagram 0. '
                        .'Usually the ad set was created with Audience Network in manual placements, or spend started before enable-instagram. '
                        .'Create a new ad set (Automatic placements) or run meta:enable-instagram --force-adsets. '
                        .'Note: Meta last_7d excludes today — use the today breakdown for ads created today.';
                } elseif ($anImpressions > 0) {
                    $deliveryWarning = 'Impressions are on Audience Network only. Run: php artisan meta:enable-instagram --force-adsets';
                }
            }
        } elseif ($targetsIg && $fbImpressions > 0) {
            $status = 'pending';
            $statusLabel = 'IG targeted — click Enable IG or wait for delivery';
        }

        return [
            'status' => $status,
            'status_label' => $statusLabel,
            'targets' => $ad->adSet?->placementTargetLabels() ?? [],
            'targets_instagram' => $targetsIg,
            'configured_adset' => $targetsIg,
            'configured_creative' => $creativeHasIg,
            'meta_creative_has_ig' => $metaCreativeHasIg,
            'meta_creative_error' => $metaCreativeError,
            'instagram_enabled_at' => $enabledAt?->toIso8601String(),
            'instagram_user_id' => $this->extractInstagramUserId($ad->creative),
            'delivers_instagram' => $igImpressions > 0,
            'delivers_facebook' => $fbImpressions > 0,
            'instagram_impressions' => $igImpressions,
            'instagram_impressions_lifetime' => $igImpressionsLifetime,
            'instagram_impressions_recent' => $igImpressionsRecent,
            'insights_recent_preset' => $recentPreset,
            'instagram_clicks' => $igClicks,
            'instagram_spend' => $igSpend,
            'facebook_impressions' => $fbImpressions,
            'facebook_clicks' => $fbClicks,
            'facebook_spend' => $fbSpend,
            'audience_network_impressions' => $anImpressions,
            'delivery_warning' => $deliveryWarning,
            'checks' => [
                ['ok' => $targetsIg, 'label' => 'Ad set targets Instagram'],
                ['ok' => $markedEnabled, 'label' => 'Enable IG applied (instagram_enabled_at)'],
                ['ok' => $creativeHasIg, 'label' => 'Creative has instagram_user_id (local)'],
                ['ok' => $metaCreativeHasIg === true, 'label' => 'Meta ad creative has instagram_user_id', 'note' => $metaCreativeHasIg === null ? 'Could not verify live' : null],
                ['ok' => $igImpressions > 0, 'label' => 'Instagram impressions from Meta insights'],
            ],
            'curl_commands' => $this->buildCurlDebugCommands($ad),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $placementDelivery
     * @return array<string, mixed>
     */
    public function buildPlacementPayloadForAd(Ad $ad, array $placementDelivery = []): array
    {
        $audit = $this->auditAdDelivery($ad, $placementDelivery);

        return array_merge($audit, [
            'summary' => $audit['status_label'],
        ]);
    }

    protected function extractInstagramUserId(?Creative $creative): ?string
    {
        if (! $creative) {
            return null;
        }

        $stored = is_array($creative->json_payload) ? $creative->json_payload : [];
        $spec = is_array($stored['object_story_spec'] ?? null) ? $stored['object_story_spec'] : [];
        $id = trim((string) ($spec['instagram_user_id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    /**
     * @return list<array{title: string, command: string}>
     */
    protected function buildCurlDebugCommands(Ad $ad): array
    {
        $version = config('services.meta.graph_version', 'v19.0');
        $base = 'https://graph.facebook.com/'.$version;
        $token = 'REDACTED_USE_ENV_META_TOKEN';
        $accountId = config('services.meta.ad_account_id', 'act_ACCOUNT');
        if (! str_starts_with((string) $accountId, 'act_')) {
            $accountId = 'act_'.$accountId;
        }

        $commands = [];

        if ($ad->meta_ad_id) {
            $commands[] = [
                'title' => 'Ad + creative (check instagram_user_id on Meta)',
                'command' => 'curl -s "'.$base.'/'.$ad->meta_ad_id.'?fields=id,name,status,creative{id,object_story_spec}&access_token='.$token.'" | jq',
            ];
            $commands[] = [
                'title' => 'This ad — impressions by platform (IG vs FB)',
                'command' => 'curl -s "'.$base.'/'.$ad->meta_ad_id.'/insights?fields=impressions,clicks,spend&breakdowns=publisher_platform&date_preset=maximum&access_token='.$token.'" | jq',
            ];
        }

        if ($ad->adSet?->meta_id) {
            $commands[] = [
                'title' => 'Ad set targeting (publisher_platforms)',
                'command' => 'curl -s "'.$base.'/'.$ad->adSet->meta_id.'?fields=id,name,targeting&access_token='.$token.'" | jq',
            ];
        }

        $commands[] = [
            'title' => 'Ad account Instagram accounts',
            'command' => 'curl -s "'.$base.'/'.$accountId.'/instagram_accounts?fields=id,username&access_token='.$token.'" | jq',
        ];

        $pageId = config('services.meta.page_id', 'PAGE_ID');
        $commands[] = [
            'title' => 'Page linked Instagram',
            'command' => 'curl -s "'.$base.'/'.$pageId.'?fields=connected_instagram_account{id,username}&access_token='.$token.'" | jq',
        ];

        return $commands;
    }
}
