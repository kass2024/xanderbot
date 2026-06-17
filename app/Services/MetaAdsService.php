<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaAdsService
{
    protected string $baseUrl;
    protected string $accessToken;
    protected string $defaultAccount;
    protected bool $debug;

    public function __construct()
    {
        $version = config('services.meta.graph_version', 'v22.0');

        $this->baseUrl = "https://graph.facebook.com/{$version}";
        $this->accessToken = config('services.meta.token');
        $this->defaultAccount = $this->formatAccount(
            config('services.meta.ad_account_id')
        );

        $this->debug = config('app.debug',false);

        if(!$this->accessToken){
            throw new Exception('Meta access token missing in config/services.php');
        }

        Log::info('META_SERVICE_INITIALIZED',[
            'account'=>$this->defaultAccount,
            'graph_version'=>$version
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FORMAT ACCOUNT
    |--------------------------------------------------------------------------
    */

    protected function formatAccount(?string $id): string
    {
        if(!$id){
            throw new Exception('Meta Ad Account ID missing.');
        }

        return str_starts_with($id,'act_') ? $id : "act_{$id}";
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP CLIENT
    |--------------------------------------------------------------------------
    */

    protected function client()
    {
        /*
        |--------------------------------------------------------------------------
        | No Http::retry() here
        |--------------------------------------------------------------------------
        | Laravel's Http retry pipeline can call $response->throw() on non-2xx
        | responses before they are returned, so MetaAdsService::request() never
        | reaches handleError() and callers only see RequestException.
        |--------------------------------------------------------------------------
        */
        return Http::timeout(30)->acceptJson();
    }

    /*
    |--------------------------------------------------------------------------
    | HANDLE ERROR
    |--------------------------------------------------------------------------
    */
protected function handleError($response, $endpoint, $payload = [])
{
    /*
    |--------------------------------------------------------------------------
    | Parse Response Safely
    |--------------------------------------------------------------------------
    */

    $body = null;

    try {
        $body = $response->json();
    } catch (\Throwable $e) {
        $body = $response->body();
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Error Message Safely
    |--------------------------------------------------------------------------
    */

    $message = 'Meta API Error';

    if (is_array($body) && isset($body['error']['message'])) {
        $message = $body['error']['message'];
        if (! empty($body['error']['error_user_title'])) {
            $message = $body['error']['error_user_title'] . ': ' . $message;
        }
        if (! empty($body['error']['error_user_msg'])) {
            $message .= ' — ' . $body['error']['error_user_msg'];
        }
        if (! empty($body['error']['error_subcode'])) {
            $message .= ' (Meta subcode ' . $body['error']['error_subcode'] . ')';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Log Detailed Meta Error
    |--------------------------------------------------------------------------
    */

    $safePayload = $payload;
    if (isset($safePayload['access_token'])) {
        $safePayload['access_token'] = '[redacted]';
    }

    Log::error('META_API_ERROR', [

        'endpoint' => $endpoint,

        'http_status' => $response->status(),

        'payload' => $safePayload,

        'response' => $body,

        'meta_error_code' => $body['error']['code'] ?? null,

        'meta_error_type' => $body['error']['type'] ?? null

    ]);

    /*
    |--------------------------------------------------------------------------
    | Throw Exception
    |--------------------------------------------------------------------------
    */

    throw new Exception($message);
}

    /*
    |--------------------------------------------------------------------------
    | BASE REQUEST
    |--------------------------------------------------------------------------
    */

    protected function request(string $method,string $endpoint,array $payload=[],bool $asForm=true)
    {
        $payload['access_token'] = $this->accessToken;

        $logPayload = $payload;
        if (isset($logPayload['access_token'])) {
            $logPayload['access_token'] = '[redacted]';
        }

        Log::info("META_API_{$method}",[
            'endpoint'=>$endpoint,
            'payload'=>$logPayload
        ]);

        $client = $this->client();

        if($asForm){
            $client = $client->asForm();
        }

        $response = $client->{$method}(
            "{$this->baseUrl}/{$endpoint}",
            $payload
        );

        if($response->failed()){
            $this->handleError($response,$endpoint,$payload);
        }

        return $response->json();
    }

    /*
    |--------------------------------------------------------------------------
    | GET
    |--------------------------------------------------------------------------
    */

    protected function get(string $endpoint,array $params=[]):array
    {
        return $this->request('get',$endpoint,$params,false);
    }

    /**
     * Follow Meta Graph API paging.next URLs (full URL from insights/cursor responses).
     */
    protected function getByPagingUrl(string $url): array
    {
        $response = $this->client()->get($url, [
            'access_token' => $this->accessToken,
        ]);

        if ($response->failed()) {
            $this->handleError($response, $url, []);
        }

        return $response->json();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function collectPagedData(string $endpoint, array $params): array
    {
        $rows = [];
        $response = $this->get($endpoint, $params);

        while (true) {
            foreach ($response['data'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $next = $response['paging']['next'] ?? null;
            if (! is_string($next) || $next === '') {
                break;
            }

            $response = $this->getByPagingUrl($next);
        }

        return $rows;
    }

    /**
     * Whether ad set targeting still needs FB+IG platforms and placement positions.
     *
     * @param  array<string, mixed>  $targeting
     */
    public function targetingNeedsInstagramRepair(array $targeting): bool
    {
        $platforms = $targeting['publisher_platforms'] ?? [];

        if (! is_array($platforms)) {
            return true;
        }

        $extras = array_intersect($platforms, ['audience_network', 'messenger', 'unknown']);
        if ($extras !== []) {
            return true;
        }

        $normalized = array_values(array_unique($platforms));
        sort($normalized);

        if ($normalized !== ['facebook', 'instagram']) {
            return true;
        }

        if (empty($targeting['instagram_positions']) || empty($targeting['facebook_positions'])) {
            return true;
        }

        if (empty($targeting['device_platforms'])) {
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | POST
    |--------------------------------------------------------------------------
    */

    protected function post(string $endpoint,array $payload=[]):array
    {
        return $this->request('post',$endpoint,$payload,true);
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    protected function delete(string $endpoint): array
    {
        Log::info('META_API_delete', [
            'endpoint' => $endpoint,
            'payload' => ['access_token' => '[redacted]'],
        ]);

        // Meta Graph DELETE requires access_token as a query param, not a JSON body.
        $url = "{$this->baseUrl}/{$endpoint}?" . http_build_query([
            'access_token' => $this->accessToken,
        ]);

        $response = $this->client()->delete($url);

        if ($response->failed()) {
            $this->handleError($response, $endpoint, []);
        }

        return $response->json();
    }

    /*
    |--------------------------------------------------------------------------
    | CONNECTION TEST
    |--------------------------------------------------------------------------
    */

    public function checkConnection():bool
    {
        try{
            $this->get('me');
            return true;
        }
        catch(Exception $e){

            Log::error('META_CONNECTION_FAILED',[
                'error'=>$e->getMessage()
            ]);

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET PAGES
    |--------------------------------------------------------------------------
    */

    public function getPages():array
    {
        $res = $this->get('me/accounts');
        return $res['data'] ?? [];
    }

    /**
     * Normalize and validate a website URL for object_story_spec.link_data (and CTAs).
     * Use https and a real site hostname; Meta 1815520 is often a bad or Page-only link.
     * Do not send the same URL as top-level object_url with object_story_spec (Meta 1487929).
     */
    public function normalizeLandingUrlForMeta(string $url, bool $strict = false): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new Exception('Website URL is empty.');
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }

        $url = preg_replace('#^http://#i', 'https://', $url, 1);

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw new Exception('Website URL must include a hostname.');
        }

        if ($strict) {
            if (! str_contains($host, '.')) {
                throw new Exception('Website URL must be a valid hostname (e.g. https://www.example.com/path).');
            }

            $blocked = ['facebook.com', 'fb.com', 'fb.me', 'messenger.com'];
            foreach ($blocked as $b) {
                if ($host === $b || str_ends_with($host, '.'.$b)) {
                    throw new Exception('Use your own website as the destination, not '.$b.'.');
                }
            }

            if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
                throw new Exception('Website URL must use https://.');
            }
        }

        return rtrim($url, '/');
    }

    /**
     * URL variants to try when Meta rejects a link (1815520).
     *
     * @return list<string>
     */
    public function landingUrlCandidates(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        try {
            $primary = $this->normalizeLandingUrlForMeta($url, false);
        } catch (Exception) {
            return [];
        }

        $candidates = [$primary];
        $parts = parse_url($primary);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        if ($host !== '' && ! str_starts_with($host, 'www.')) {
            $candidates[] = 'https://www.'.$host.$path.$query;
        }

        if (str_starts_with($host, 'www.')) {
            $candidates[] = 'https://'.substr($host, 4).$path.$query;
        }

        return array_values(array_unique(array_map(fn ($u) => rtrim($u, '/'), $candidates)));
    }

    /**
     * Derive conversion_domain (2nd+top-level) from a landing URL.
     * Example: https://www.example.com/path -> example.com
     */
    public function conversionDomainFromUrl(string $url): string
    {
        $url = $this->normalizeLandingUrlForMeta($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./i', '', $host);

        $parts = array_values(array_filter(explode('.', (string) $host)));
        if (count($parts) < 2) {
            throw new Exception('Unable to derive conversion_domain from URL.');
        }

        return $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];
    }

    /**
     * Instagram user id linked to a Facebook Page (object_story_spec.instagram_user_id).
     */
    public function getConnectedInstagramUserId(string $pageId): ?string
    {
        return $this->resolveInstagramUserId($pageId);
    }

    /**
     * Explicit Instagram id from .env (META_INSTAGRAM_USER_ID), when set.
     */
    public function configuredInstagramUserId(): ?string
    {
        $fromEnv = trim((string) config('services.meta.instagram_user_id', ''));

        return $fromEnv !== '' ? $fromEnv : null;
    }

    /**
     * Instagram actor id for ad creatives.
     * META_INSTAGRAM_USER_ID wins when set (so Page-linked wrong IG does not override).
     */
    public function resolveInstagramUserId(?string $pageId = null): ?string
    {
        $fromEnv = $this->configuredInstagramUserId();
        if ($fromEnv !== null) {
            return $fromEnv;
        }

        foreach ($this->instagramPageIdCandidates($pageId) as $candidate) {
            $found = $this->lookupInstagramIdFromPage($candidate);
            if ($found !== null) {
                return $found;
            }
        }

        $fromAccount = $this->lookupInstagramIdFromAdAccount();
        if ($fromAccount !== null) {
            return $fromAccount;
        }

        return null;
    }

    /**
     * Connection diagnostics for CLI / admin checks.
     *
     * @return array<string, mixed>
     */
    public function diagnoseInstagramConnection(?string $pageId = null): array
    {
        $pageId = trim((string) ($pageId ?? config('services.meta.page_id', '')));
        $envIg = $this->configuredInstagramUserId();
        $report = [
            'page_id' => $pageId,
            'ad_account_id' => config('services.meta.ad_account_id'),
            'instagram_user_id' => $envIg,
            'source' => $envIg !== null ? 'env:META_INSTAGRAM_USER_ID (override)' : null,
            'page_connected' => false,
            'page_linked_instagram_id' => null,
            'env_fallback' => $envIg !== null,
            'ready' => $envIg !== null,
            'hints' => [],
        ];

        if ($envIg !== null) {
            $report['hints'][] = 'Using META_INSTAGRAM_USER_ID from .env. Rebuild creatives after changing: php artisan meta:ensure-brand-pages --force-creatives';
        }

        if ($pageId !== '') {
            foreach (['connected_instagram_account{id,username}', 'instagram_business_account{id,username}'] as $fields) {
                try {
                    $res = $this->get($pageId, ['fields' => $fields]);
                    $key = str_contains($fields, 'connected') ? 'connected_instagram_account' : 'instagram_business_account';
                    $account = $res[$key] ?? null;
                    if (is_array($account) && ! empty($account['id'])) {
                        $report['page_connected'] = true;
                        $report['page_linked_instagram_id'] = (string) $account['id'];
                        $report['instagram_username'] = $account['username'] ?? null;
                        if ($envIg === null) {
                            $report['instagram_user_id'] = (string) $account['id'];
                            $report['source'] = 'page:'.$key;
                        }
                        break;
                    }
                } catch (Exception $e) {
                    $report['page_errors'][] = $fields.': '.$e->getMessage();
                }
            }
        }

        if ($report['instagram_user_id'] === null) {
            $fromAccount = $this->lookupInstagramIdFromAdAccount();
            if ($fromAccount !== null) {
                $report['instagram_user_id'] = $fromAccount;
                $report['source'] = 'ad_account:instagram_accounts';
            }
        }

        $report['ready'] = $report['instagram_user_id'] !== null && $report['instagram_user_id'] !== '';

        if ($envIg !== null && $report['page_linked_instagram_id'] !== null && $report['page_linked_instagram_id'] !== $envIg) {
            $report['hints'][] = 'Page '.$pageId.' is linked to a different Instagram ('.$report['page_linked_instagram_id'].'). Ads use .env id '.$envIg.' — run meta:ensure-brand-pages --force-creatives.';
        }

        if (! $report['ready']) {
            $report['hints'] = [
                'Confirm the Page in META_PAGE_ID is linked to Instagram in business.facebook.com → Settings → Accounts.',
                'Assign the Page to your Business portfolio and ensure the system user has access.',
                'Or set META_INSTAGRAM_USER_ID in .env (Instagram account numeric ID).',
            ];
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    protected function instagramPageIdCandidates(?string $pageId): array
    {
        $ids = [
            trim((string) $pageId),
            trim((string) config('services.meta.page_id', '')),
        ];

        return array_values(array_unique(array_filter($ids)));
    }

    protected function lookupInstagramIdFromPage(string $pageId): ?string
    {
        foreach (['connected_instagram_account{id}', 'instagram_business_account{id}'] as $fields) {
            try {
                $res = $this->get($pageId, ['fields' => $fields]);
                $key = str_contains($fields, 'connected') ? 'connected_instagram_account' : 'instagram_business_account';
                $account = $res[$key] ?? null;

                if (is_array($account) && ! empty($account['id'])) {
                    return (string) $account['id'];
                }
            } catch (Exception $e) {
                Log::warning('META_PAGE_INSTAGRAM_LOOKUP_FAILED', [
                    'page_id' => $pageId,
                    'fields' => $fields,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    protected function lookupInstagramIdFromAdAccount(?string $accountId = null): ?string
    {
        try {
            $accountId = $this->formatAccount($accountId ?? config('services.meta.ad_account_id'));
            $res = $this->get("{$accountId}/instagram_accounts", [
                'fields' => 'id,username',
                'limit' => 5,
            ]);

            foreach ($res['data'] ?? [] as $row) {
                if (! empty($row['id'])) {
                    return (string) $row['id'];
                }
            }
        } catch (Exception $e) {
            Log::warning('META_AD_ACCOUNT_INSTAGRAM_LOOKUP_FAILED', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Force Facebook + Instagram placements (required for reliable IG delivery with link ads).
     *
     * @param  array<string, mixed>  $targeting
     * @return array<string, mixed>
     */
    public function applyFacebookInstagramPlacements(array $targeting): array
    {
        $platforms = $targeting['publisher_platforms'] ?? [];

        if (! is_array($platforms)) {
            $platforms = [];
        }

        $targeting['publisher_platforms'] = ['facebook', 'instagram'];

        unset(
            $targeting['messenger_positions'],
            $targeting['audience_network_positions'],
        );

        return $this->enrichPlacementTargeting($targeting);
    }

    /**
     * Patch a live Meta ad set so Instagram is included in publisher_platforms.
     */
    public function ensureAdSetTargetsInstagram(string $adsetMetaId, bool $force = false): bool
    {
        $meta = $this->getAdSet($adsetMetaId);
        $targeting = $meta['targeting'] ?? [];

        if (is_string($targeting)) {
            $decoded = json_decode($targeting, true);
            $targeting = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($targeting)) {
            $targeting = [];
        }

        $patched = $this->applyFacebookInstagramPlacements($targeting);

        if (! $force && ! $this->targetingNeedsInstagramRepair($targeting) && $this->targetingEquivalent($targeting, $patched)) {
            return false;
        }

        $this->updateAdSet($adsetMetaId, [
            'targeting' => json_encode($patched, JSON_THROW_ON_ERROR),
        ]);

        return true;
    }

    /**
     * Ensure the ad set promotes the brand Facebook Page (required for Page + IG ad delivery).
     */
    public function ensureAdSetPromotedPage(string $adsetMetaId, string $pageId): bool
    {
        $pageId = trim($pageId);

        if ($pageId === '') {
            return false;
        }

        $meta = $this->getAdSet($adsetMetaId);
        $promoted = $meta['promoted_object'] ?? [];

        if (is_string($promoted)) {
            $decoded = json_decode($promoted, true);
            $promoted = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($promoted)) {
            $promoted = [];
        }

        if ((string) ($promoted['page_id'] ?? '') === $pageId) {
            return false;
        }

        $promoted['page_id'] = $pageId;

        $this->updateAdSet($adsetMetaId, [
            'promoted_object' => json_encode($promoted, JSON_THROW_ON_ERROR),
        ]);

        return true;
    }

    /**
     * Compare targeting payloads (ignore key order).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function targetingEquivalent(array $a, array $b): bool
    {
        return json_encode($this->normalizeTargetingForCompare($a))
            === json_encode($this->normalizeTargetingForCompare($b));
    }

    /**
     * @param  array<string, mixed>  $targeting
     * @return array<string, mixed>
     */
    protected function normalizeTargetingForCompare(array $targeting): array
    {
        $copy = $targeting;
        ksort($copy);

        if (isset($copy['publisher_platforms']) && is_array($copy['publisher_platforms'])) {
            $copy['publisher_platforms'] = array_values(array_unique($copy['publisher_platforms']));
            sort($copy['publisher_platforms']);
        }

        foreach (['facebook_positions', 'instagram_positions', 'device_platforms'] as $key) {
            if (isset($copy[$key]) && is_array($copy[$key])) {
                $copy[$key] = array_values(array_unique($copy[$key]));
                sort($copy[$key]);
            }
        }

        return $copy;
    }

    /**
     * Swap an ad's creative on Meta (new creative must include instagram_user_id for IG).
     */
    public function attachCreativeToAd(string $adId, string $creativeId): array
    {
        return $this->updateAd($adId, [
            'creative' => json_encode(
                ['creative_id' => (string) $creativeId],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CAMPAIGN
    |--------------------------------------------------------------------------
    */

    public function createCampaign(string $accountId,array $data):array
    {
        $accountId = $this->formatAccount($accountId);

        $payload = [

            'name'=>$data['name'],

            'objective'=>$data['objective'],

            'status'=>$data['status'] ?? 'PAUSED',

            'special_ad_categories'=>json_encode(['NONE']),

            'is_adset_budget_sharing_enabled'=>false
        ];

        Log::info('META_CAMPAIGN_PAYLOAD',$payload);

        return $this->post("{$accountId}/campaigns",$payload);
    }

    public function updateCampaign(string $campaignId,array $data):array
    {
        return $this->post($campaignId,$data);
    }

    public function deleteCampaign(string $campaignId):array
    {
        return $this->delete($campaignId);
    }

    /*
    |--------------------------------------------------------------------------
    | TARGETING BUILDER
    |--------------------------------------------------------------------------
    */

    /**
     * Build geo_locations from country codes and optional city entries.
     * Countries with selected cities are targeted at city level only.
     */
    public function buildGeoLocations(array $selectedCountries, array $selectedCities = []): array
    {
        $countries = array_values(array_unique(array_map(
            fn ($code) => strtoupper(trim((string) $code)),
            $selectedCountries
        )));

        if ($countries === []) {
            throw new Exception('At least one country is required.');
        }

        $citiesByCountry = [];

        foreach ($selectedCities as $city) {
            if (! is_array($city)) {
                continue;
            }

            $key = trim((string) ($city['key'] ?? ''));
            $country = strtoupper(trim((string) ($city['country'] ?? '')));

            if ($key === '' || $country === '') {
                continue;
            }

            $entry = ['key' => $key];

            if (! empty($city['name'])) {
                $entry['name'] = (string) $city['name'];
            }

            if (! empty($city['region'])) {
                $entry['region'] = (string) $city['region'];
            }

            if (! empty($city['region_id'])) {
                $entry['region_id'] = (int) $city['region_id'];
            }

            $entry['country'] = $country;
            $citiesByCountry[$country][] = $entry;
        }

        $geo = [
            'countries' => [],
            'cities' => [],
        ];

        foreach ($countries as $country) {
            if (! empty($citiesByCountry[$country])) {
                $geo['cities'] = array_merge($geo['cities'], $citiesByCountry[$country]);
                continue;
            }

            $geo['countries'][] = $country;
        }

        if ($geo['countries'] === []) {
            unset($geo['countries']);
        }

        if ($geo['cities'] === []) {
            unset($geo['cities']);
        }

        if (! isset($geo['countries']) && ! isset($geo['cities'])) {
            throw new Exception('At least one valid country or city is required.');
        }

        return $geo;
    }

    /**
     * Meta only needs city keys in API payloads; strip extra metadata safely.
     */
    protected function normalizeGeoLocationsForApi(array $geoLocations): array
    {
        if (! empty($geoLocations['countries']) && is_array($geoLocations['countries'])) {
            $geoLocations['countries'] = array_values(array_unique(array_map(
                fn ($code) => strtoupper(trim((string) $code)),
                $geoLocations['countries']
            )));
        }

        if (! empty($geoLocations['cities']) && is_array($geoLocations['cities'])) {
            $geoLocations['cities'] = array_values(array_map(function ($city) {
                $key = is_array($city)
                    ? trim((string) ($city['key'] ?? ''))
                    : trim((string) $city);

                if ($key === '') {
                    return null;
                }

                return ['key' => $key];
            }, $geoLocations['cities']));

            $geoLocations['cities'] = array_values(array_filter($geoLocations['cities']));

            if ($geoLocations['cities'] === []) {
                unset($geoLocations['cities']);
            }
        }

        return $geoLocations;
    }

    /**
     * Search cities, regions, or countries via Meta Targeting Search.
     */
    public function searchGeoLocations(
        string $query,
        string $locationType = 'city',
        ?string $countryCode = null
    ): array {
        $query = trim($query);

        if (strlen($query) < 2) {
            return [];
        }

        $allowedTypes = ['city', 'country', 'region', 'zip'];
        if (! in_array($locationType, $allowedTypes, true)) {
            $locationType = 'city';
        }

        $params = [
            'type' => 'adgeolocation',
            'location_types' => json_encode([$locationType]),
            'q' => $query,
            'limit' => 25,
            'access_token' => $this->accessToken,
        ];

        if ($countryCode) {
            $params['country_code'] = strtoupper(trim($countryCode));
        }

        $response = Http::timeout(10)->get("{$this->baseUrl}/search", $params);

        if ($response->failed()) {
            Log::warning('META_GEO_SEARCH_FAILED', [
                'query' => $query,
                'location_type' => $locationType,
                'country_code' => $countryCode,
                'response' => $response->body(),
            ]);

            return [];
        }

        $items = $response->json()['data'] ?? [];

        return collect($items)->map(function ($item) {
            return [
                'key' => (string) ($item['key'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
                'country_code' => strtoupper((string) ($item['country_code'] ?? $item['country'] ?? '')),
                'country_name' => (string) ($item['country_name'] ?? ''),
                'region' => (string) ($item['region'] ?? ''),
                'region_id' => isset($item['region_id']) ? (int) $item['region_id'] : null,
                'supports_city' => (bool) ($item['supports_city'] ?? true),
            ];
        })->filter(fn ($item) => $item['key'] !== '' && $item['name'] !== '')->values()->all();
    }

protected function buildTargeting(array $targeting): array
{
    /*
    |--------------------------------------------------------------------------
    | Remove locales (Meta rejects them at ad set level)
    |--------------------------------------------------------------------------
    */

    unset($targeting['locales']);

    if (! empty($targeting['geo_locations'])) {
        $targeting['geo_locations'] = $this->normalizeGeoLocationsForApi(
            $targeting['geo_locations']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Manual placements: Meta expects position arrays alongside publisher_platforms.
    |--------------------------------------------------------------------------
    */

    if (! empty($targeting['publisher_platforms'])) {
        $targeting = $this->enrichPlacementTargeting($targeting);
    }

    /*
    |--------------------------------------------------------------------------
    | Advantage audience: omit unless already set (safer across API versions)
    |--------------------------------------------------------------------------
    */

    Log::info('META_TARGETING_FINAL', $targeting);

    return $targeting;
}

    /**
     * Meta subcode 1870247: some interest IDs were merged/deprecated. The error_user_msg
     * includes "Relevant alternative options: [{...}]" — parse and return that list.
     *
     * @return list<array{deprecated_interest_id?:string,alternative_interest_id?:string,...}>
     */
    protected function parseInterestDeprecationAlternativesFromMessage(string $message): array
    {
        $marker = 'Relevant alternative options:';
        $pos = stripos($message, $marker);
        if ($pos === false) {
            return [];
        }

        $start = strpos($message, '[', $pos);
        if ($start === false) {
            return [];
        }

        $depth = 0;
        $len = strlen($message);
        for ($i = $start; $i < $len; $i++) {
            $c = $message[$i];
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
                if ($depth === 0) {
                    $json = substr($message, $start, $i - $start + 1);
                    $decoded = json_decode($json, true);

                    return is_array($decoded) ? $decoded : [];
                }
            }
        }

        return [];
    }

    /**
     * Replace deprecated interest IDs in flexible_spec using Meta's suggested alternatives.
     *
     * @param  list<array<string, mixed>>  $alternatives
     * @return array{0: array, 1: bool}  [patched targeting, whether any id changed]
     */
    protected function applyInterestDeprecationAlternatives(array $targeting, array $alternatives): array
    {
        $map = [];
        foreach ($alternatives as $row) {
            if (! is_array($row)) {
                continue;
            }
            $dep = $row['deprecated_interest_id'] ?? null;
            $alt = $row['alternative_interest_id'] ?? null;
            if ($dep !== null && $dep !== '' && $alt !== null && $alt !== '') {
                $map[(string) $dep] = (string) $alt;
            }
        }

        if ($map === []) {
            return [$targeting, false];
        }

        if (empty($targeting['flexible_spec']) || ! is_array($targeting['flexible_spec'])) {
            return [$targeting, false];
        }

        $changed = false;
        $out = $targeting;
        foreach ($out['flexible_spec'] as &$group) {
            if (! is_array($group) || empty($group['interests']) || ! is_array($group['interests'])) {
                continue;
            }
            foreach ($group['interests'] as &$interest) {
                $id = (string) ($interest['id'] ?? '');
                if ($id !== '' && isset($map[$id])) {
                    $interest['id'] = $map[$id];
                    $changed = true;
                }
            }
        }

        return [$out, $changed];
    }

    /**
     * When createAdSet fails with 1870247, call this with the same targeting and exception message
     * to obtain an updated targeting array, or null if nothing could be applied.
     */
    public function patchTargetingFrom1870247Error(array $targeting, string $message): ?array
    {
        if (! preg_match('/\(Meta subcode\s+1870247\)/i', $message)) {
            return null;
        }

        $alternatives = $this->parseInterestDeprecationAlternativesFromMessage($message);
        [$patched, $changed] = $this->applyInterestDeprecationAlternatives($targeting, $alternatives);

        return $changed ? $patched : null;
    }

    /**
     * Add required placement positions when publisher_platforms is present.
     */
    protected function enrichPlacementTargeting(array $targeting): array
    {
        $platforms = $targeting['publisher_platforms'];

        if (in_array('facebook', $platforms, true)) {
            $targeting['facebook_positions'] = $targeting['facebook_positions'] ?? [
                'feed',
                'story',
                'instream_video',
                'marketplace',
            ];
        }

        if (in_array('instagram', $platforms, true)) {
            $targeting['instagram_positions'] = ['stream', 'story', 'reels', 'explore', 'profile_feed'];
        }

        if (in_array('messenger', $platforms, true)) {
            $targeting['messenger_positions'] = $targeting['messenger_positions'] ?? [
                'messenger_home',
                'story',
            ];
        }

        if (in_array('audience_network', $platforms, true)) {
            $targeting['audience_network_positions'] = $targeting['audience_network_positions'] ?? [
                'classic',
                'instream_video',
            ];
        }

        if (empty($targeting['device_platforms'])) {
            $targeting['device_platforms'] = ['mobile', 'desktop'];
        }

        return $targeting;
    }

    /**
     * Apply placement position defaults (for local targeting JSON or API payloads).
     */
    public function enrichPlacementsForTargeting(array $targeting): array
    {
        if (empty($targeting['publisher_platforms'])) {
            return $targeting;
        }

        return $this->enrichPlacementTargeting($targeting);
    }

    /**
     * @param  array<string, mixed>  $targeting
     * @return array<string, mixed>
     */
    public function targetingWithFacebookAndInstagram(array $targeting): array
    {
        return $this->applyFacebookInstagramPlacements($targeting);
    }

    public function normalizeCampaignObjective(?string $objective): string
    {
        return strtoupper(trim((string) $objective));
    }

    /**
     * Ordered optimization goals to try for a campaign objective (Meta ODAX).
     *
     * @return list<string>
     */
    public function optimizationGoalCandidates(string $objective, ?string $preferred = null): array
    {
        $objective = $this->normalizeCampaignObjective($objective);

        $byObjective = [
            'OUTCOME_TRAFFIC' => ['LANDING_PAGE_VIEWS', 'LINK_CLICKS', 'REACH'],
            'TRAFFIC' => ['LANDING_PAGE_VIEWS', 'LINK_CLICKS', 'REACH'],
            'OUTCOME_LEADS' => ['LEAD_GENERATION'],
            'LEADS' => ['LEAD_GENERATION'],
            'OUTCOME_SALES' => ['OFFSITE_CONVERSIONS', 'LANDING_PAGE_VIEWS', 'LINK_CLICKS'],
            'SALES' => ['OFFSITE_CONVERSIONS', 'LANDING_PAGE_VIEWS', 'LINK_CLICKS'],
            'OUTCOME_AWARENESS' => ['REACH', 'IMPRESSIONS'],
            'AWARENESS' => ['REACH', 'IMPRESSIONS'],
            'OUTCOME_ENGAGEMENT' => ['POST_ENGAGEMENT', 'REACH'],
            'ENGAGEMENT' => ['POST_ENGAGEMENT', 'REACH'],
            'OUTCOME_APP_PROMOTION' => ['APP_INSTALLS', 'LINK_CLICKS'],
            'APP_PROMOTION' => ['APP_INSTALLS', 'LINK_CLICKS'],
        ];

        $candidates = $byObjective[$objective] ?? [
            'LANDING_PAGE_VIEWS',
            'LINK_CLICKS',
            'REACH',
            'IMPRESSIONS',
            'LEAD_GENERATION',
            'OFFSITE_CONVERSIONS',
            'POST_ENGAGEMENT',
        ];

        if ($preferred !== null) {
            $preferred = strtoupper(trim($preferred));
            if ($preferred !== '' && in_array($preferred, $candidates, true)) {
                $candidates = array_values(array_unique(array_merge([$preferred], $candidates)));
            }
        }

        return $candidates;
    }

    public function resolveOptimizationGoal(string $objective, ?string $requested = null): string
    {
        $candidates = $this->optimizationGoalCandidates($objective, $requested);

        return $candidates[0];
    }

    public function isOptimizationGoalMismatchError(string $message): bool
    {
        return str_contains($message, '2490408')
            || str_contains($message, "Performance goal isn't available")
            || (str_contains($message, 'optimization_goal') && str_contains($message, 'Invalid parameter'));
    }

    /**
     * Create an ad set, auto-picking a valid optimization goal and retrying on Meta 2490408.
     *
     * @param  array<string, mixed>  $data
     * @return array{response: array, optimization_goal: string, targeting: array}
     */
    public function createAdSetResilient(string $accountId, array $data, string $campaignObjective): array
    {
        $preferred = isset($data['optimization_goal']) ? (string) $data['optimization_goal'] : null;
        $goals = $this->optimizationGoalCandidates($campaignObjective, $preferred);
        $targeting = $data['targeting'] ?? [];

        if (! is_array($targeting)) {
            throw new Exception('Invalid targeting structure');
        }

        $lastError = null;

        foreach ($goals as $goal) {
            $data['optimization_goal'] = $goal;
            $targetingForMeta = $targeting;

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $data['targeting'] = $targetingForMeta;

                try {
                    $response = $this->createAdSet($accountId, $data);

                    Log::info('META_ADSET_GOAL_RESOLVED', [
                        'campaign_objective' => $this->normalizeCampaignObjective($campaignObjective),
                        'optimization_goal' => $goal,
                        'attempt' => $attempt + 1,
                    ]);

                    return [
                        'response' => $response,
                        'optimization_goal' => $goal,
                        'targeting' => $targetingForMeta,
                    ];
                } catch (Exception $e) {
                    $patched = $this->patchTargetingFrom1870247Error(
                        $targetingForMeta,
                        $e->getMessage()
                    );

                    if ($patched !== null) {
                        $targetingForMeta = $patched;

                        Log::info('META_ADSET_1870247_PATCH_RETRY', [
                            'optimization_goal' => $goal,
                            'attempt' => $attempt + 1,
                        ]);

                        continue;
                    }

                    if ($this->isOptimizationGoalMismatchError($e->getMessage())) {
                        $lastError = $e;

                        Log::warning('META_ADSET_GOAL_RETRY', [
                            'campaign_objective' => $this->normalizeCampaignObjective($campaignObjective),
                            'failed_goal' => $goal,
                            'error' => $e->getMessage(),
                        ]);

                        break;
                    }

                    throw $e;
                }
            }
        }

        throw $lastError ?? new Exception(
            'Unable to find a performance goal compatible with campaign objective '.$campaignObjective.'.'
        );
    }

   /*
|--------------------------------------------------------------------------
| ADSET
|--------------------------------------------------------------------------
*/

public function createAdSet(string $accountId, array $data): array
{
    $accountId = $this->formatAccount($accountId);

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if (empty($data['campaign_id'])) {
        throw new Exception('campaign_id required');
    }

    if (empty($data['name'])) {
        throw new Exception('AdSet name required');
    }

    if (empty($data['targeting'])) {
        throw new Exception('targeting required');
    }

    if (empty($data['daily_budget'])) {
        throw new Exception('daily_budget required');
    }

    /*
    |--------------------------------------------------------------------------
    | TARGETING SAFETY
    |--------------------------------------------------------------------------
    | Targeting may arrive as array OR JSON string
    */

    $targeting = $data['targeting'];

    if (is_string($targeting)) {

        $decoded = json_decode($targeting, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid targeting JSON');
        }

        $targeting = $decoded;
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD TARGETING
    |--------------------------------------------------------------------------
    */

    $targeting = $this->buildTargeting($targeting);

    $targeting = $this->applyFacebookInstagramPlacements($targeting);

/*
|--------------------------------------------------------------------------
| Safety: ensure array
|--------------------------------------------------------------------------
*/

if (!is_array($targeting)) {
    throw new Exception('Invalid targeting structure');
}

    /*
    |--------------------------------------------------------------------------
    | PAYLOAD
    |--------------------------------------------------------------------------
    */

    $payload = [

        'name' => $data['name'],

        'campaign_id' => $data['campaign_id'],

        'daily_budget' => (int) $data['daily_budget'],

        'billing_event' => $data['billing_event'] ?? 'IMPRESSIONS',

        'optimization_goal' => $data['optimization_goal'] ?? $this->resolveOptimizationGoal(
            (string) ($data['campaign_objective'] ?? 'OUTCOME_TRAFFIC'),
            isset($data['optimization_goal']) ? (string) $data['optimization_goal'] : null
        ),

        'bid_strategy' => $data['bid_strategy'] ?? 'LOWEST_COST_WITHOUT_CAP',

        'status' => $data['status'] ?? 'PAUSED',

        'start_time' => $data['start_time'] ?? now()->addMinutes(5)->timestamp,

        'targeting' => json_encode($targeting)
    ];

    /*
    |--------------------------------------------------------------------------
    | PROMOTED OBJECT
    |--------------------------------------------------------------------------
    */

    if (!empty($data['promoted_object'])) {

        $payload['promoted_object'] = is_array($data['promoted_object'])
            ? json_encode($data['promoted_object'])
            : $data['promoted_object'];
    }

    /*
    |--------------------------------------------------------------------------
    | DEBUG LOG
    |--------------------------------------------------------------------------
    */

    Log::info('META_ADSET_PAYLOAD', [
        'endpoint' => "{$accountId}/adsets",
        'payload' => $payload
    ]);

    /*
    |--------------------------------------------------------------------------
    | API REQUEST
    |--------------------------------------------------------------------------
    */

    $response = $this->post("{$accountId}/adsets", $payload);

    /*
    |--------------------------------------------------------------------------
    | RESPONSE LOG
    |--------------------------------------------------------------------------
    */

    Log::info('META_ADSET_CREATED', [
        'response' => $response
    ]);

    return $response;
}
    public function deleteAdSet(string $adsetId):array
    {
        return $this->delete($adsetId);
    }

    /*
    |--------------------------------------------------------------------------
    | IMAGE UPLOAD
    |--------------------------------------------------------------------------
    */

    public function uploadImage(string $accountId, string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new Exception('Image file not found or not readable. Check server storage permissions.');
        }

        $preparedPath = $this->prepareImageForMetaUpload($filePath);
        $cleanupPrepared = $preparedPath !== $filePath;

        try {
            return $this->uploadImageToMetaAccount($accountId, $preparedPath);
        } finally {
            if ($cleanupPrepared && is_file($preparedPath)) {
                @unlink($preparedPath);
            }
        }
    }

    /**
     * Resize/compress large creatives so Meta adimages accepts them.
     */
    public function prepareImageForMetaUpload(string $filePath): string
    {
        $info = @getimagesize($filePath);

        if ($info === false || ! function_exists('imagecreatetruecolor')) {
            return $filePath;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $type = (int) ($info[2] ?? 0);
        $maxDim = 2048;
        $maxBytes = 4 * 1024 * 1024;
        $size = (int) filesize($filePath);

        $scale = min(1.0, $maxDim / max($width, $height, 1));

        if ($scale >= 1.0 && $size <= $maxBytes) {
            return $filePath;
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG => @imagecreatefrompng($filePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : false,
            default => false,
        };

        if ($src === false) {
            return $filePath;
        }

        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'meta_ad_'.uniqid('', true).'.jpg';

        if (! imagejpeg($dst, $tempPath, 85)) {
            imagedestroy($dst);

            return $filePath;
        }

        imagedestroy($dst);

        Log::info('META_IMAGE_PREPARED', [
            'from' => $filePath,
            'to' => $tempPath,
            'original' => "{$width}x{$height}",
            'prepared' => "{$newW}x{$newH}",
            'bytes' => filesize($tempPath),
        ]);

        return $tempPath;
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadImageToMetaAccount(string $accountId, string $filePath): array
    {
        $this->validateImageForMetaUpload($filePath);

        $accountId = $this->formatAccount($accountId);
        $fileName = basename($filePath);
        $timeout = max(120, (int) config('services.meta.http_timeout', 90));
        $connectTimeout = (int) config('services.meta.http_connect_timeout', 45);

        Log::info('META_UPLOAD_IMAGE', [
            'account' => $accountId,
            'file' => $filePath,
            'bytes' => filesize($filePath),
        ]);

        // Prefer bytes (base64) — Meta's documented approach.
        $encoded = base64_encode((string) file_get_contents($filePath));

        $bytesResponse = Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->asForm()
            ->post("{$this->baseUrl}/{$accountId}/adimages", [
                'access_token' => $this->accessToken,
                'bytes' => $encoded,
                'name' => $fileName,
            ]);

        if ($bytesResponse->successful()) {
            $json = $bytesResponse->json();

            if ($this->extractImageHashFromUploadResponse($json) !== null) {
                return $json;
            }
        }

        if ($bytesResponse->failed()) {
            Log::warning('META_UPLOAD_IMAGE_BYTES_FAILED', [
                'status' => $bytesResponse->status(),
                'body' => $bytesResponse->json(),
            ]);
        }

        // Fallback: multipart filename upload
        $mime = mime_content_type($filePath) ?: 'image/jpeg';
        $stream = fopen($filePath, 'rb');

        try {
            $multipartResponse = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->attach('filename', $stream, $fileName, ['Content-Type' => $mime])
                ->post("{$this->baseUrl}/{$accountId}/adimages", [
                    'access_token' => $this->accessToken,
                    'name' => $fileName,
                ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($multipartResponse->failed()) {
            $this->handleError($multipartResponse, 'uploadImage');
        }

        $json = $multipartResponse->json();

        if ($this->extractImageHashFromUploadResponse($json) === null) {
            throw new Exception('Meta image upload failed: no image hash in response.');
        }

        return $json;
    }

    /**
     * Parse image hash from Meta adimages upload response.
     */
    public function extractImageHashFromUploadResponse(?array $response): ?string
    {
        if (! is_array($response) || empty($response['images']) || ! is_array($response['images'])) {
            return null;
        }

        foreach ($response['images'] as $image) {
            if (is_array($image) && ! empty($image['hash'])) {
                return (string) $image['hash'];
            }
        }

        return null;
    }

    protected function validateImageForMetaUpload(string $filePath): void
    {
        $info = @getimagesize($filePath);

        if ($info === false) {
            throw new Exception('Invalid image file. Upload a JPG or PNG.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width < 200 || $height < 200) {
            throw new Exception('Image is too small. Use at least 200×200 pixels.');
        }

        $size = filesize($filePath);

        if ($size === false || $size > 30 * 1024 * 1024) {
            throw new Exception('Image exceeds Meta’s 30MB upload limit.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATIVE
    |--------------------------------------------------------------------------
    */

    public function createCreative(string $accountId, array $data): array
    {
        $accountId = $this->formatAccount($accountId);

        if (empty($data['object_story_spec']) || ! is_array($data['object_story_spec'])) {
            throw new Exception('object_story_spec is required to create a Meta creative.');
        }

        $payload = [
            'name' => $data['name'],
            'object_story_spec' => json_encode(
                $data['object_story_spec'],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];

        Log::info('META_CREATIVE_PAYLOAD', $payload);

        return $this->post("{$accountId}/adcreatives", $payload);
    }

  

 /*
|--------------------------------------------------------------------------
| CREATE AD
|--------------------------------------------------------------------------
*/

public function createAd(string $accountId, array $data): array
{
    $accountId = $this->formatAccount($accountId);

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if (empty($data['name'])) {
        throw new Exception('Ad name is required');
    }

    if (empty($data['adset_id'])) {
        throw new Exception('adset_id is required');
    }

    if (empty($data['creative']['id']) && empty($data['creative']['spec'])) {
        throw new Exception('creative id or creative spec is required');
    }

    /*
    |--------------------------------------------------------------------------
    | BUILD PAYLOAD
    |--------------------------------------------------------------------------
    | Meta requires the creative field to be JSON encoded.
    | It can be either:
    | - {"creative_id":"<ID>"} OR
    | - {"creative": { ...creative spec... }}
    |
    | We support both so we can inline a link creative when Meta rejects an
    | existing creative_id for LINK_CLICKS optimization (subcode 1815520).
    |--------------------------------------------------------------------------
    */

    $creativeParam = null;

    if (! empty($data['creative']['spec']) && is_array($data['creative']['spec'])) {
        $creativeParam = json_encode([
            'creative' => $data['creative']['spec'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } else {
        $creativeParam = json_encode([
            'creative_id' => (string) $data['creative']['id'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    $payload = [

        'name' => $data['name'],

        'adset_id' => $data['adset_id'],

        'status' => $data['status'] ?? 'PAUSED',

        'creative' => $creativeParam,
    ];

    if (! empty($data['conversion_domain'])) {
        $payload['conversion_domain'] = (string) $data['conversion_domain'];
    }

    /*
    |--------------------------------------------------------------------------
    | DEBUG LOG
    |--------------------------------------------------------------------------
    */

    Log::info('META_AD_CREATE_PAYLOAD', [

        'endpoint' => "{$accountId}/ads",

        'payload' => $payload

    ]);

    /*
    |--------------------------------------------------------------------------
    | SEND REQUEST
    |--------------------------------------------------------------------------
    */

    $response = $this->post("{$accountId}/ads", $payload);

    /*
    |--------------------------------------------------------------------------
    | RESPONSE LOG
    |--------------------------------------------------------------------------
    */

    Log::info('META_AD_CREATE_RESPONSE', $response);

    return $response;
}

/*
|--------------------------------------------------------------------------
| UPDATE AD
|--------------------------------------------------------------------------
*/

public function updateAd(string $adId, array $data): array
{
    Log::info('META_AD_UPDATE_PAYLOAD', [
        'ad_id' => $adId,
        'payload' => $data
    ]);

    return $this->post($adId, $data);
}


/*
|--------------------------------------------------------------------------
| DELETE AD
|--------------------------------------------------------------------------
*/

public function deleteAd(string $adId): array
{
    Log::info('META_AD_DELETE', [
        'ad_id' => $adId
    ]);

    return $this->delete($adId);
}
public function getCampaigns(string $accountId): array
{
    $accountId = $this->formatAccount($accountId);

    return $this->get("{$accountId}/campaigns", [
        'fields' => 'id,name,status,objective'
    ]);
}


/*
|--------------------------------------------------------------------------
| GET ADSETS
|--------------------------------------------------------------------------
*/

public function getAdSets(string $accountId): array
{
    $accountId = $this->formatAccount($accountId);

    return $this->get("{$accountId}/adsets", [
        'fields' => 'id,name,campaign_id,status,daily_budget'
    ]);
}

/**
 * Get a single ad set with fields used for validation.
 */
public function getAdSet(string $adsetId): array
{
    return $this->get($adsetId, [
        'fields' => implode(',', [
            'id',
            'name',
            'status',
            'optimization_goal',
            'billing_event',
            'promoted_object',
            'targeting',
            'destination_type',
        ]),
    ]);
}


/*
|--------------------------------------------------------------------------
| GET ADS
|--------------------------------------------------------------------------
*/
public function getAds(?string $accountId = null): array
{
    $accountId = $accountId
        ? $this->formatAccount($accountId)
        : $this->defaultAccount;

    return $this->get("{$accountId}/ads", [

        'fields' => implode(',', [

            'id',
            'name',
            'status',
            'effective_status',
            'adset_id',

            'creative{id,name}',

            'ad_review_feedback'

        ])

    ]);
}

/*
|--------------------------------------------------------------------------
| GET SINGLE AD
|--------------------------------------------------------------------------
*/

public function getAd(string $adId): array
{
    return $this->get($adId, [
        'fields' => 'id,name,status,effective_status,adset_id,campaign_id',
    ]);
}

/**
 * Fetch ad with creative object_story_spec (for IG delivery verification).
 */
public function getAdWithCreativeSpec(string $adId): array
{
    return $this->get($adId, [
        'fields' => 'id,name,status,effective_status,adset_id,creative{id,name,object_story_spec}',
    ]);
}
/*
|--------------------------------------------------------------------------
| GET INSIGHTS
|--------------------------------------------------------------------------
*/
/**
 * Lightweight platform breakdown (avoids heavy insight fields + timeouts).
 *
 * @return list<array<string, mixed>>
 */
public function getAdPlatformBreakdown(string $adId, string $preset = 'today'): array
{
    return $this->collectPagedData("{$adId}/insights", [
        'fields' => 'impressions,clicks,spend',
        'date_preset' => $preset,
        'breakdowns' => 'publisher_platform',
        'limit' => 25,
    ]);
}

public function getInsights(string $objectId, string $preset = 'maximum', array $extra = []): array
{
    /*
    |--------------------------------------------------------------------------
    | Default Fields For Monitoring Dashboard
    |--------------------------------------------------------------------------
    */

    $fields = implode(',', [

        'impressions',
        'clicks',
        'spend',
        'reach',

        'ctr',
        'cpm',
        'cpc',

        'frequency',
        'inline_link_clicks',

        'actions',
        'action_values',

        'video_p25_watched_actions',
        'video_p50_watched_actions',
        'video_p75_watched_actions',
        'video_p100_watched_actions',

        'date_start',
        'date_stop'
    ]);

    /*
    |--------------------------------------------------------------------------
    | Query Parameters
    |--------------------------------------------------------------------------
    */

    $params = array_merge([
        'fields' => $fields,
        'date_preset' => $preset,
        'limit' => 1,
    ], $extra);

    if (isset($extra['breakdowns'])) {
        $params['limit'] = 100;
    }

    Log::info('META_INSIGHTS_REQUEST', [
        'object_id' => $objectId,
        'preset' => $preset,
        'params' => $params,
    ]);

    /*
    |--------------------------------------------------------------------------
    | If breakdown requested → return all platform rows (paginated)
    |--------------------------------------------------------------------------
    */

    if (isset($extra['breakdowns'])) {
        return $this->collectPagedData("{$objectId}/insights", $params);
    }

    $response = $this->get("{$objectId}/insights", $params);

    /*
    |--------------------------------------------------------------------------
    | Normal Dashboard Metrics
    |--------------------------------------------------------------------------
    */

    $data = $response['data'][0] ?? [];

    return [

        'impressions' => (int)($data['impressions'] ?? 0),

        'clicks' => (int)($data['clicks'] ?? 0),

        'spend' => (float)($data['spend'] ?? 0),

        'reach' => (int)($data['reach'] ?? 0),

        'ctr' => (float)($data['ctr'] ?? 0),

        'cpm' => (float)($data['cpm'] ?? 0),

        'cpc' => (float)($data['cpc'] ?? 0),

        'frequency' => (float)($data['frequency'] ?? 0),

        'inline_link_clicks' => (int)($data['inline_link_clicks'] ?? 0),

        'actions' => $data['actions'] ?? [],

        'action_values' => $data['action_values'] ?? [],

        'video_25' => $data['video_p25_watched_actions'] ?? [],
        'video_50' => $data['video_p50_watched_actions'] ?? [],
        'video_75' => $data['video_p75_watched_actions'] ?? [],
        'video_100' => $data['video_p100_watched_actions'] ?? [],

        'date_start' => $data['date_start'] ?? null,
        'date_stop' => $data['date_stop'] ?? null,

        'raw' => $response
    ];
}
/*
|--------------------------------------------------------------------------
| GET CREATIVE
|--------------------------------------------------------------------------
| Fetch minimal Creative info from Meta
*/

public function getCreative(string $creativeId): array
{
    return $this->get($creativeId, [

        'fields' => implode(',', [

            'id',
            'name',
            'status'

        ])
    ]);
}
/*
|--------------------------------------------------------------------------
| GET SINGLE CAMPAIGN
|--------------------------------------------------------------------------
*/

public function getCampaign(string $campaignId): array
{
    return $this->get($campaignId, [
        'fields' => 'id,name,status,objective'
    ]);
}
/*
|--------------------------------------------------------------------------
| UPDATE ADSET
|--------------------------------------------------------------------------
*/

public function updateAdSet(string $adsetId, array $data): array
{
    if (isset($data['targeting'])) {
        $targeting = $data['targeting'];

        if (is_string($targeting)) {
            $decoded = json_decode($targeting, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid targeting JSON');
            }

            $targeting = $decoded;
        }

        if (! is_array($targeting)) {
            throw new Exception('Invalid targeting structure');
        }

        $targeting = $this->buildTargeting($targeting);
        $targeting = $this->applyFacebookInstagramPlacements($targeting);
        $data['targeting'] = json_encode($targeting);
    }

    Log::info('META_ADSET_UPDATE',[
        'adset_id'=>$adsetId,
        'payload'=>$data
    ]);

    return $this->post($adsetId,$data);
}
/*
|--------------------------------------------------------------------------
| ACCESS TOKEN
|--------------------------------------------------------------------------
*/

protected function getAccessToken(): string
{
    return $this->accessToken;
}
public function updateCreative(string $creativeId,array $data):array
{
    return $this->post($creativeId,$data);
}
public function getCreativeInsights(string $creativeId): array
{
    return $this->get("{$creativeId}/insights", [
        'fields' => 'impressions,clicks,spend,ctr',
        'date_preset' => 'maximum'
    ]);
}
public function getBillingInfo(string $accountId)
{
    $accountId = $this->formatAccount($accountId);

    $response = Http::get("{$this->baseUrl}/{$accountId}",[
        'fields' => implode(',',[
            'id',
            'name',
            'account_status',
            'currency',
            'timezone_name',
            'amount_spent',
            'spend_cap',
            'funding_source_details'
        ]),
        'access_token' => $this->accessToken
    ]);

    if(!$response->successful()){
        $this->handleError($response,'billing_info');
    }

    return $response->json();
}
/*
|--------------------------------------------------------------------------
| 🔥 GET INSIGHTS BATCH (CRITICAL FOR SYNC)
|--------------------------------------------------------------------------
| Fetch all ads insights in ONE request (avoids rate limit)
*/

public function getInsightsBatch(string $accountId, string $datePreset = 'today'): array
{
    $accountId = $this->formatAccount($accountId);

    return $this->get("{$accountId}/insights", [

        'level' => 'ad',

        'fields' => implode(',', [
            'ad_id',
            'impressions',
            'clicks',
            'spend'
        ]),

        'date_preset' => $datePreset,

        'limit' => 500
    ]);
}

/**
 * Fetch ad-level insights in one Meta request, keyed by Meta ad id.
 *
 * @return array<string, array<string, mixed>>
 */
public function getAdInsightsMap(?string $accountId = null, string $preset = 'maximum'): array
{
    $accountId = $this->formatAccount($accountId ?? config('services.meta.ad_account_id'));

    $rows = $this->collectPagedData("{$accountId}/insights", [
        'level' => 'ad',
        'fields' => implode(',', [
            'ad_id',
            'impressions',
            'clicks',
            'spend',
            'ctr',
        ]),
        'date_preset' => $preset,
        'limit' => 500,
    ]);

    $map = [];

    foreach ($rows as $row) {
        $adId = (string) ($row['ad_id'] ?? '');

        if ($adId !== '') {
            $map[$adId] = $row;
        }
    }

    return $map;
}

/**
 * All ads in the ad account (paginated).
 *
 * @return list<array<string, mixed>>
 */
public function listAccountAds(?string $accountId = null): array
{
    $accountId = $this->formatAccount($accountId ?? config('services.meta.ad_account_id'));

    return $this->collectPagedData("{$accountId}/ads", [
        'fields' => 'id,name,status,effective_status,adset_id',
        'limit' => 500,
    ]);
}

/**
 * Ad-level insights split by publisher_platform (facebook, instagram, …).
 *
 * @return array<string, array<string, array{impressions: int, clicks: int, spend: float}>>
 */
public function getAdPlacementInsightsMap(?string $accountId = null, string $preset = 'maximum'): array
{
    $accountId = $this->formatAccount($accountId ?? config('services.meta.ad_account_id'));

    $rows = $this->collectPagedData("{$accountId}/insights", [
        'level' => 'ad',
        'breakdowns' => 'publisher_platform',
        'fields' => implode(',', ['ad_id', 'impressions', 'clicks', 'spend']),
        'date_preset' => $preset,
        'limit' => 500,
    ]);

    $map = [];

    foreach ($rows as $row) {
        $adId = (string) ($row['ad_id'] ?? '');
        $platform = strtolower((string) ($row['publisher_platform'] ?? 'unknown'));

        if ($adId === '') {
            continue;
        }

        if (! isset($map[$adId])) {
            $map[$adId] = [];
        }

        $map[$adId][$platform] = [
            'impressions' => (int) ($row['impressions'] ?? 0),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'spend' => (float) ($row['spend'] ?? 0),
        ];
    }

    return $map;
}

public function getAccountStatus($accountId)
{
    $accountId = str_starts_with($accountId, 'act_')
        ? $accountId
        : "act_{$accountId}";

    $response = Http::get("{$this->baseUrl}/{$accountId}", [
        'fields' => 'account_status',
        'access_token' => $this->accessToken
    ]);

    if (!$response->successful()) {
        throw new \Exception($response->body());
    }

    return $response->json();
}
}