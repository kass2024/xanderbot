<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Creative;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\AdAccount;
use App\Services\MetaAdsService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Throwable;
use Exception;

class CreativeController extends Controller
{
    protected MetaAdsService $meta;

    public function __construct(MetaAdsService $meta)
    {
        $this->meta = $meta;
    }

    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        $creatives = Creative::with(['campaign','adset'])
            ->latest()
            ->paginate(20);

        return view('admin.creatives.index', [
            'creatives' => $creatives,
            'creativeStats' => [
                'total' => Creative::count(),
                // Match creatives/index.blade.php "Approved" badge: APPROVED or ACTIVE delivery, and not disapproved.
                'approved' => Creative::query()
                    ->where(function ($q) {
                        $q->where('review_status', 'APPROVED')
                            ->orWhere('effective_status', 'ACTIVE');
                    })
                    ->where(function ($q) {
                        $q->whereNull('review_status')
                            ->orWhere('review_status', '<>', 'DISAPPROVED');
                    })
                    ->count(),
                'pending' => Creative::where('review_status', 'PENDING_REVIEW')->count(),
                'rejected' => Creative::where('review_status', 'DISAPPROVED')->count(),
                'active' => Creative::where('effective_status', 'ACTIVE')->count(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function create()
    {
        $campaigns = Campaign::latest()->get();

        $adsets = AdSet::latest()->get();

        $pages = $this->meta->getPages();

        return view('admin.creatives.create', [
            'campaigns' => $campaigns,
            'adsets' => $adsets,
            'pages' => $pages,
            'selectedCampaign' => request('campaign'),
            'selectedAdset' => request('adset'),
            'selectedPage' => request('page'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        Log::info('CREATIVE_STORE_REQUEST', $request->except(['image', '_token']));

        if (! $request->hasFile('image')) {
            return back()
                ->withInput()
                ->withErrors(['image' => 'Please choose an image file (JPG or PNG).']);
        }

        $uploadedFile = $request->file('image');

        if (! $uploadedFile->isValid()) {
            return back()
                ->withInput()
                ->withErrors(['image' => $this->uploadedImageErrorMessage($uploadedFile)]);
        }

        Log::info('CREATIVE_STORE_IMAGE', [
            'size_kb' => round($uploadedFile->getSize() / 1024, 1),
            'mime' => $uploadedFile->getMimeType(),
            'name' => $uploadedFile->getClientOriginalName(),
        ]);

        $data = $request->validate([

            'campaign_id' => 'required|exists:campaigns,id',

            'adset_id' => 'required|exists:ad_sets,id',

            'page_id' => 'required|string',

            'name' => 'required|string|max:255',

            'headline' => 'nullable|string|max:255',

            'body' => 'nullable|string',

            'destination_url' => 'nullable|string|max:2048',

            'call_to_action' => 'nullable|string|max:50',

            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            'sync_meta' => 'nullable|boolean',

            'status' => 'nullable|string',

        ]);

        if ($request->boolean('sync_meta')) {
            $request->validate([
                'destination_url' => ['required', 'string', 'max:2048'],
            ]);
        }


        DB::beginTransaction();

        try {

            $campaign = Campaign::with('adAccount')->findOrFail($data['campaign_id']);

            $adset = AdSet::findOrFail($data['adset_id']);

            $account = $campaign->adAccount ?? AdAccount::whereNotNull('meta_id')->first();

            if (! $account || ! $account->meta_id) {
                throw new Exception('Meta Ad Account not connected for this campaign.');
            }

            $accountId = $account->meta_id;

            if (!str_starts_with($accountId, 'act_')) {
                $accountId = 'act_' . $accountId;
            }


            /*
            |--------------------------------------------------------------------------
            | STORE IMAGE LOCALLY
            |--------------------------------------------------------------------------
            */

            $imagePath = $request->file('image')->store(
                'creatives',
                'public'
            );

            $imageFullPath = storage_path('app/public/'.$imagePath);

            if (! is_file($imageFullPath)) {
                throw new Exception('Stored image missing at '.$imageFullPath.'. Run php artisan storage:link on the server.');
            }

            /*
            |--------------------------------------------------------------------------
            | META IMAGE UPLOAD
            |--------------------------------------------------------------------------
            */

            $imageHash = null;

            if ($request->boolean('sync_meta')) {

                $imageResponse = $this->meta->uploadImage(
                    $accountId,
                    $imageFullPath
                );

                Log::info('META_IMAGE_UPLOAD', $imageResponse);

                $imageHash = $this->meta->extractImageHashFromUploadResponse($imageResponse);

                if (! $imageHash) {
                    throw new Exception('Meta image upload failed: no image hash returned.');
                }
            }


            /*
            |--------------------------------------------------------------------------
            | BUILD LINK DATA
            |--------------------------------------------------------------------------
            | Meta rejects creatives used with LINK_CLICKS / LANDING_PAGE_VIEWS
            | ad sets when link is missing, localhost, or not a real website (1815520).
            |--------------------------------------------------------------------------
            */

            $landingUrl = $data['destination_url'] ?? null;

            $normalizedLanding = null;

            if ($request->boolean('sync_meta')) {
                if (empty($landingUrl)) {
                    throw new Exception('Destination URL is required when syncing a creative to Meta.');
                }
                $normalizedLanding = $this->meta->normalizeLandingUrlForMeta($landingUrl);
            }

            $linkData = [

                'link' => $normalizedLanding ?? ($landingUrl ?? config('app.url')),

                'message' => $data['body'] ?? '',

                'name' => $data['headline'] ?? $data['name'],

                'image_hash' => $imageHash,

            ];


            if (!empty($data['call_to_action'])) {

                $linkData['call_to_action'] = [

                    'type' => $data['call_to_action'],

                    'value' => [
                        'link' => $normalizedLanding ?? ($landingUrl ?? $linkData['link']),
                    ],

                ];
            }


            /*
            |--------------------------------------------------------------------------
            | CREATIVE PAYLOAD
            |--------------------------------------------------------------------------
            */

            $payload = [

                'name' => $data['name'],

                'object_story_spec' => [

                    'page_id' => $data['page_id'],

                    'link_data' => $linkData,

                ],

            ];

            $instagramUserId = $this->meta->resolveInstagramUserId($data['page_id']);
            if ($instagramUserId !== null && $instagramUserId !== '') {
                $payload['object_story_spec']['instagram_user_id'] = $instagramUserId;
            } elseif ($request->boolean('sync_meta')) {
                throw new Exception(
                    'Instagram is not available for this Page. Link Page ↔ Instagram in Meta Business Suite, '
                    .'or set META_INSTAGRAM_USER_ID in .env, then try again.'
                );
            }

            Log::info('META_CREATIVE_PAYLOAD', $payload);


            /*
            |--------------------------------------------------------------------------
            | CREATE META CREATIVE
            |--------------------------------------------------------------------------
            */

            $metaCreativeId = null;

            if ($request->boolean('sync_meta')) {

                $response = $this->meta->createCreative(
                    $accountId,
                    $payload
                );

                Log::info('META_CREATIVE_RESPONSE', $response);

                if (!isset($response['id'])) {

                    throw new Exception(
                        $response['error']['message']
                        ?? 'Meta creative creation failed.'
                    );
                }

                $metaCreativeId = $response['id'];
            }


            /*
            |--------------------------------------------------------------------------
            | SAVE LOCAL CREATIVE
            |--------------------------------------------------------------------------
            */

            $creative = Creative::create([

                'campaign_id' => $campaign->id,

                'adset_id' => $adset->id,

                'name' => $data['name'],

                'headline' => $data['headline'] ?? null,

                'body' => $data['body'] ?? null,

                'destination_url' => $data['destination_url'] ?? null,

                'call_to_action' => $data['call_to_action'] ?? null,
'image_url' => Storage::url($imagePath),

                'image_hash' => $imageHash,

                'meta_id' => $metaCreativeId,

                'json_payload' => $payload,

                'status' => $data['status'] ?? Creative::STATUS_DRAFT

            ]);


            DB::commit();


            Log::info('CREATIVE_CREATED', [

                'creative_id' => $creative->id,

                'meta_id' => $metaCreativeId

            ]);


            return redirect()
                ->route('admin.ads.create', [
                    'adset' => $creative->adset_id,
                    'creative' => $creative->id,
                    'name' => $creative->name,
                ])
                ->with('success', 'Creative created. Finish by creating your ad.');

        } catch (Throwable $e) {

            DB::rollBack();

            Log::error('CREATIVE_STORE_FAILED', [

                'error' => $e->getMessage()

            ]);

            $message = $e->getMessage();
            $field = $this->creativeStoreErrorField($message);

            return back()
                ->withInput()
                ->withErrors([
                    $field => $message,
                ]);
        }
    }

    private function uploadedImageErrorMessage(\Illuminate\Http\UploadedFile $file): string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Image is too large for PHP on this server (upload_max_filesize / post_max_size). Use an image under 5MB or set upload_max_filesize=5M in php.ini.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No image file was received by the server.',
            default => $file->getErrorMessage() ?: 'The image failed to upload before it reached the app.',
        };
    }

    private function creativeStoreErrorField(string $message): string
    {
        $lower = strtolower($message);

        if (
            str_contains($lower, 'image')
            || str_contains($lower, 'upload')
            || str_contains($lower, 'hash')
            || str_contains($lower, 'resize')
        ) {
            return 'image';
        }

        return 'meta';
    }


    /*
    |--------------------------------------------------------------------------
    | EDIT
    |--------------------------------------------------------------------------
    */

    public function edit(Creative $creative)
    {
        return view('admin.creatives.edit', compact('creative'));
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(Request $request, Creative $creative)
    {
        $data = $request->validate([

            'name' => 'required|string|max:255',

            'headline' => 'nullable|string|max:255',

            'body' => 'nullable|string',

            'destination_url' => 'nullable|url',

            'call_to_action' => 'nullable|string|max:50',

            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

        ]);

        try {

            if ($request->hasFile('image')) {

                if ($creative->image_url) {
                    Storage::disk('public')->delete($creative->image_url);
                }

                $creative->image_url = $request->file('image')
                    ->store('creatives','public');
            }

            $creative->update($data);

            return redirect()
                ->route('admin.creatives.index')
                ->with('success','Creative updated.');

        } catch (Throwable $e) {

            Log::error('CREATIVE_UPDATE_FAILED', [

                'error' => $e->getMessage()

            ]);

            return back()->withErrors([
                'meta' => 'Unable to update creative'
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    public function destroy(Creative $creative)
    {
        try {

            if ($creative->image_url) {
                Storage::disk('public')->delete($creative->image_url);
            }

            $creative->delete();

            return back()->with('success','Creative deleted.');

        } catch (Throwable $e) {

            Log::error('CREATIVE_DELETE_FAILED', [

                'error' => $e->getMessage()

            ]);

            return back()->withErrors([
                'meta' => 'Unable to delete creative.'
            ]);
        }
    }
public function sync($id)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | Find Creative
        |--------------------------------------------------------------------------
        */

        $creative = Creative::findOrFail($id);

        if (!$creative->meta_id) {
            return back()->with('error', 'Creative is not connected to Meta.');
        }


        /*
        |--------------------------------------------------------------------------
        | Fetch Creative From Meta
        |--------------------------------------------------------------------------
        */

        $meta = $this->meta->getCreative($creative->meta_id);

        if (!$meta || isset($meta['error'])) {

            Log::error('META_CREATIVE_SYNC_ERROR', [
                'creative_id' => $creative->id,
                'meta_id' => $creative->meta_id,
                'response' => $meta
            ]);

            return back()->with('error','Unable to fetch creative from Meta.');
        }


        /*
        |--------------------------------------------------------------------------
        | Extract Base Status
        |--------------------------------------------------------------------------
        */

        $status = $meta['status'] ?? $creative->status;
        $effectiveStatus = $meta['effective_status'] ?? $creative->effective_status;

        $reviewStatus = null;
        $reviewFeedback = null;


        /*
        |--------------------------------------------------------------------------
        | Extract Review Feedback From Creative (if present)
        |--------------------------------------------------------------------------
        */

        if (!empty($meta['ad_review_feedback'])) {

            $reviewStatus = $meta['ad_review_feedback']['global']['review_status'] ?? null;

            $reviewFeedback = $meta['ad_review_feedback']['global']['message'] ?? null;
        }


        /*
        |--------------------------------------------------------------------------
        | If no review info → Check Ads Using This Creative
        |--------------------------------------------------------------------------
        */

        if (!$reviewStatus) {

            $accountId =
                config('services.meta.ad_account_id')
                ?? env('META_AD_ACCOUNT_ID');

            $ads = $this->meta->getAds($accountId);

            if (!empty($ads['data'])) {

                foreach ($ads['data'] as $ad) {

                    if (
                        isset($ad['creative']['id']) &&
                        $ad['creative']['id'] == $creative->meta_id
                    ) {

                        $effectiveStatus = $ad['effective_status'] ?? $effectiveStatus;

                        $reviewStatus = match ($effectiveStatus) {

                            'ACTIVE' => 'APPROVED',
                            'PAUSED' => 'APPROVED',
                            'DISAPPROVED' => 'DISAPPROVED',

                            default => 'PENDING_REVIEW'
                        };

                        Log::info('META_AD_MATCHED_CREATIVE', [
                            'creative_meta_id' => $creative->meta_id,
                            'ad_id' => $ad['id'] ?? null,
                            'ad_status' => $effectiveStatus
                        ]);

                        break;
                    }
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Final Fallback
        |--------------------------------------------------------------------------
        */

        if (!$reviewStatus) {

            $reviewStatus = match ($effectiveStatus) {

                'ACTIVE' => 'APPROVED',

                'DISAPPROVED' => 'DISAPPROVED',

                'PAUSED',
                'IN_PROCESS',
                'PENDING_REVIEW' => 'PENDING_REVIEW',

                default => 'PENDING_REVIEW'
            };
        }


        /*
        |--------------------------------------------------------------------------
        | Update Local Database
        |--------------------------------------------------------------------------
        */

        $creative->update([
            'status' => $status,
            'effective_status' => $effectiveStatus,
            'review_status' => $reviewStatus,
            'review_feedback' => $reviewFeedback,
            'last_synced_at' => now()
        ]);


        return back()->with('success','Creative synced successfully.');

    } catch (\Throwable $e) {

        Log::error('CREATIVE_SYNC_FAILED', [
            'creative_id' => $id,
            'error' => $e->getMessage()
        ]);

        return back()->with('error','Sync failed: '.$e->getMessage());
    }
}
    /*
    |--------------------------------------------------------------------------
    | PREVIEW
    |--------------------------------------------------------------------------
    */

    public function preview(Creative $creative)
    {
        return view('admin.creatives.preview', compact('creative'));
    }
    /*
|--------------------------------------------------------------------------
| ACTIVATE CREATIVE
|--------------------------------------------------------------------------
*/

public function activate(Creative $creative)
{
    try {

        if(!$creative->meta_id){
            return back()->withErrors([
                'meta' => 'Creative not synced with Meta.'
            ]);
        }

        $this->meta->updateCreative(
            $creative->meta_id,
            ['status' => 'ACTIVE']
        );

        $creative->update([
            'effective_status' => 'ACTIVE'
        ]);

        return back()->with('success','Creative activated.');

    } catch(Throwable $e){

        Log::error('CREATIVE_ACTIVATE_FAILED',[
            'error'=>$e->getMessage()
        ]);

        return back()->withErrors([
            'meta'=>'Unable to activate creative.'
        ]);
    }
}
/*
|--------------------------------------------------------------------------
| PAUSE CREATIVE
|--------------------------------------------------------------------------
*/

public function pause(Creative $creative)
{
    try {

        if(!$creative->meta_id){
            return back()->withErrors([
                'meta'=>'Creative not synced with Meta.'
            ]);
        }

        $this->meta->updateCreative(
            $creative->meta_id,
            ['status'=>'PAUSED']
        );

        $creative->update([
            'effective_status'=>'PAUSED'
        ]);

        return back()->with('success','Creative paused.');

    } catch(Throwable $e){

        Log::error('CREATIVE_PAUSE_FAILED',[
            'error'=>$e->getMessage()
        ]);

        return back()->withErrors([
            'meta'=>'Unable to pause creative.'
        ]);
    }
}

}