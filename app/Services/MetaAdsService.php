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
        $version = config('services.meta.graph_version','v19.0');

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

    protected function delete(string $endpoint):array
    {
        return $this->request('delete',$endpoint,[],false);
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
        $pageId = trim($pageId);
        if ($pageId === '') {
            return null;
        }

        try {
            $res = $this->get($pageId, [
                'fields' => 'connected_instagram_account',
            ]);

            $account = $res['connected_instagram_account'] ?? null;

            if (is_array($account) && ! empty($account['id'])) {
                return (string) $account['id'];
            }
        } catch (Exception $e) {
            Log::warning('META_PAGE_INSTAGRAM_LOOKUP_FAILED', [
                'page_id' => $pageId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
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

protected function buildTargeting(array $targeting): array
{
    /*
    |--------------------------------------------------------------------------
    | Remove locales (Meta rejects them at ad set level)
    |--------------------------------------------------------------------------
    */

    unset($targeting['locales']);

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
            $targeting['instagram_positions'] = $targeting['instagram_positions'] ?? [
                'stream',
                'story',
                'reels',
            ];
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

        'optimization_goal' => $data['optimization_goal'] ?? 'LINK_CLICKS',

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
        'fields' => 'id,name,status,effective_status,adset_id,campaign_id'
    ]);
}
/*
|--------------------------------------------------------------------------
| GET INSIGHTS
|--------------------------------------------------------------------------
*/
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
        'limit' => 1
    ], $extra);

    Log::info('META_INSIGHTS_REQUEST', [
        'object_id' => $objectId,
        'preset' => $preset,
        'params' => $params
    ]);

    /*
    |--------------------------------------------------------------------------
    | Call Meta API
    |--------------------------------------------------------------------------
    */

    $response = $this->get("{$objectId}/insights", $params);

    /*
    |--------------------------------------------------------------------------
    | If breakdown requested → return raw rows (for audience/device tables)
    |--------------------------------------------------------------------------
    */

    if (isset($extra['breakdowns'])) {
        return $response['data'] ?? [];
    }

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

public function getInsightsBatch(string $accountId): array
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

        'date_preset' => 'today',

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

    $response = $this->get("{$accountId}/insights", [
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

    foreach ($response['data'] ?? [] as $row) {
        $adId = (string) ($row['ad_id'] ?? '');

        if ($adId !== '') {
            $map[$adId] = $row;
        }
    }

    return $map;
}

/**
 * Ad-level insights split by publisher_platform (facebook, instagram, …).
 *
 * @return array<string, array<string, array{impressions: int, clicks: int, spend: float}>>
 */
public function getAdPlacementInsightsMap(?string $accountId = null, string $preset = 'maximum'): array
{
    $accountId = $this->formatAccount($accountId ?? config('services.meta.ad_account_id'));

    $response = $this->get("{$accountId}/insights", [
        'level' => 'ad',
        'breakdowns' => 'publisher_platform',
        'fields' => implode(',', ['ad_id', 'impressions', 'clicks', 'spend']),
        'date_preset' => $preset,
        'limit' => 500,
    ]);

    $map = [];

    foreach ($response['data'] ?? [] as $row) {
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