<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\Campaign;
use App\Models\AdSet;
use App\Services\MetaAdsService;

use Throwable;
use Exception;

class AdSetController extends Controller
{
    protected $meta;

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
        $adsets = AdSet::with('campaign')
            ->latest()
            ->paginate(20);

        return view('admin.adsets.index', [
            'adsets' => $adsets,
            'adsetStats' => [
                'total' => AdSet::count(),
                'active' => AdSet::where('status', 'ACTIVE')->count(),
                'paused' => AdSet::where('status', 'PAUSED')->count(),
                'draft' => AdSet::where('status', 'DRAFT')->count(),
            ],
        ]);
    }

    /**
     * Ad sets for a single campaign (route: admin.campaigns.adsets.index).
     */
    public function indexByCampaign(Campaign $campaign)
    {
        $campaignId = $campaign->id;

        $adsets = AdSet::query()
            ->where('campaign_id', $campaignId)
            ->latest()
            ->paginate(20);

        $stats = [
            'total' => AdSet::where('campaign_id', $campaignId)->count(),
            'active' => AdSet::where('campaign_id', $campaignId)->where('status', 'ACTIVE')->count(),
            'paused' => AdSet::where('campaign_id', $campaignId)->where('status', 'PAUSED')->count(),
        ];

        $totalBudgetCents = AdSet::where('campaign_id', $campaignId)->sum('daily_budget');
        $totalBudget = ((float) $totalBudgetCents) / 100;

        return view('admin.adsets.by-campaign', [
            'campaign' => $campaign,
            'adsets' => $adsets,
            'stats' => $stats,
            'totalBudget' => $totalBudget,
        ]);
    }

    /**
     * Resource route adsets.show — no dedicated page; send users to edit.
     */
    public function show(AdSet $adset)
    {
        return redirect()->route('admin.adsets.edit', $adset);
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE FORM
    |--------------------------------------------------------------------------
    */

    public function create($campaignId = null)
    {
        return view('admin.adsets.create', [

            'campaigns' => Campaign::latest()->get(),

            'selectedCampaign' => $campaignId,

            'countries' => config('meta.countries'),

            'languages' => config('meta.languages'),

            'pages' => $this->meta->getPages()

        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        Log::info('META_ADSET_REQUEST', $request->all());

        $data = $request->validate([

            'campaign_id' => 'required|exists:campaigns,id',

            'name' => 'required|string|max:255',

            'daily_budget' => 'required|numeric|min:5',

            'optimization_goal' => 'nullable|string|in:LINK_CLICKS,LANDING_PAGE_VIEWS,REACH,IMPRESSIONS,LEAD_GENERATION,OFFSITE_CONVERSIONS,POST_ENGAGEMENT',

            'bid_strategy' => 'required|string|in:LOWEST_COST_WITHOUT_CAP',

            'page_id' => 'required|string',

            'age_min' => 'required|integer|min:18|max:65',

            'age_max' => 'required|integer|min:18|max:65',

            'geo_mode' => 'required|in:countries_only,countries_and_cities',

            'countries' => 'required|array|min:1',

            'cities_json' => 'nullable|string',

            'genders' => 'nullable|array',

            'languages' => 'nullable|array',

            'interests' => 'nullable|array|max:5',

            'interests_json' => 'nullable|string',

            'placement_type' => 'required|in:automatic,manual',

            'publisher_platforms' => 'nullable|array'

        ]);

        if ($data['age_min'] >= $data['age_max']) {
            return back()->withErrors([
                'age' => 'Maximum age must be greater than minimum age'
            ])->withInput();
        }

        DB::beginTransaction();

        try {

            $campaign = Campaign::with('adAccount')
                ->findOrFail($data['campaign_id']);

            if (!$campaign->meta_id) {
                throw new Exception('Campaign not synced with Meta');
            }

            if (!$campaign->adAccount || !$campaign->adAccount->meta_id) {
                throw new Exception('Ad Account not connected');
            }

            $accountId = $campaign->adAccount->meta_id;

            if (!str_starts_with($accountId, 'act_')) {
                $accountId = 'act_' . $accountId;
            }

            /*
            |--------------------------------------------------------------------------
            | OPTIMIZATION SETTINGS (sync objective from Meta, auto-pick goal)
            |--------------------------------------------------------------------------
            */

            $metaCampaign = $this->meta->getCampaign($campaign->meta_id);
            $metaObjective = $this->meta->normalizeCampaignObjective(
                (string) ($metaCampaign['objective'] ?? $campaign->objective)
            );

            if ($metaObjective === '') {
                throw new Exception('Campaign objective missing on Meta and locally.');
            }

            if ($metaObjective !== $this->meta->normalizeCampaignObjective((string) $campaign->objective)) {
                $campaign->update(['objective' => $metaObjective]);

                Log::info('CAMPAIGN_OBJECTIVE_SYNCED', [
                    'campaign_id' => $campaign->id,
                    'meta_id' => $campaign->meta_id,
                    'objective' => $metaObjective,
                ]);
            }

            $requestedGoal = $data['optimization_goal'] ?? null;
            $optimizationGoal = $this->meta->resolveOptimizationGoal($metaObjective, $requestedGoal);

            $billingEvent = 'IMPRESSIONS';

            /*
            |--------------------------------------------------------------------------
            | TARGETING
            |--------------------------------------------------------------------------
            */

            $targeting = $this->buildAdSetTargeting($data);

            $languages = array_map('intval', $data['languages'] ?? []);
            unset($targeting['locales']);

            /*
            |--------------------------------------------------------------------------
            | PAYLOAD
            |--------------------------------------------------------------------------
            */

            $payload = [

                'name' => $data['name'],

                'campaign_id' => $campaign->meta_id,

                'daily_budget' => (int)$data['daily_budget'] * 100,

                'billing_event' => $billingEvent,

                'optimization_goal' => $optimizationGoal,

                'campaign_objective' => $metaObjective,

                'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',

                'status' => 'PAUSED',

                // IMPORTANT: must be timestamp
                'start_time' => now()->addMinutes(5)->timestamp,

                'promoted_object' => [
                    'page_id' => $data['page_id']
                ],

                // DO NOT JSON encode here
                'targeting' => $targeting
            ];

            Log::info('META_ADSET_PAYLOAD', $payload);

            $created = $this->meta->createAdSetResilient(
                $accountId,
                $payload,
                $metaObjective
            );

            $response = $created['response'];
            $optimizationGoal = $created['optimization_goal'];
            $targeting = $created['targeting'];

            Log::info('META_ADSET_RESPONSE', $response);

            if (!isset($response['id'])) {

                throw new Exception(
                    $response['error']['message']
                        ?? 'Meta failed to create AdSet'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | SAVE LOCAL
            |--------------------------------------------------------------------------
            */
$adset = AdSet::create([

    'campaign_id' => $campaign->id,

    'meta_id' => $response['id'],

    'name' => $data['name'],

    'daily_budget' => $payload['daily_budget'],

    'billing_event' => $billingEvent,

    'optimization_goal' => $optimizationGoal,

    'targeting' => array_merge($targeting, [
        'locales' => $languages ?? [],
    ]),

    'status' => 'PAUSED'

]);

            DB::commit();

            return redirect()
                ->route('admin.campaigns.index')
                ->with('success', 'Ad Set created successfully');

        }

        catch (Throwable $e) {

            DB::rollBack();

            Log::error('META_ADSET_FAILED', [
                'error' => $e->getMessage()
            ]);

            return back()
                ->withInput()
                ->withErrors([
                    'meta' => $e->getMessage()
                ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    public function destroy($id)
    {
        $adset = AdSet::findOrFail($id);

        try {

            if ($adset->meta_id) {
                $this->meta->deleteAdSet($adset->meta_id);
            }

            $adset->delete();

            return back()->with(
                'success',
                'AdSet deleted successfully'
            );

        } catch (Throwable $e) {

            Log::error('ADSET_DELETE_FAILED', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return back()->withErrors([
                'delete' => 'Failed to delete AdSet'
            ]);
        }
    }
    /*
|--------------------------------------------------------------------------
| ACTIVATE ADSET
|--------------------------------------------------------------------------
*/

public function activate(AdSet $adset)
{
    try {

        if($adset->meta_id){

            $this->meta->updateAdSet(
                $adset->meta_id,
                ['status'=>'ACTIVE']
            );

        }

        $adset->update([
            'status' => 'ACTIVE'
        ]);

        return back()->with('success','Ad Set activated.');

    } catch(\Throwable $e){

        return back()->withErrors([
            'meta'=>'Unable to activate Ad Set: '.$e->getMessage()
        ]);
    }
}
/*
|--------------------------------------------------------------------------
| PAUSE ADSET
|--------------------------------------------------------------------------
*/

public function pause(AdSet $adset)
{
    try {

        if($adset->meta_id){

            $this->meta->updateAdSet(
                $adset->meta_id,
                ['status'=>'PAUSED']
            );

        }

        $adset->update([
            'status'=>'PAUSED'
        ]);

        return back()->with('success','Ad Set paused.');

    } catch(\Throwable $e){

        return back()->withErrors([
            'meta'=>'Unable to pause Ad Set.'
        ]);
    }
}
/*
|--------------------------------------------------------------------------
| SYNC ADSET FROM META
|--------------------------------------------------------------------------
*/

public function sync(AdSet $adset)
{
    try {

        if(!$adset->meta_id){

            return back()->withErrors([
                'meta' => 'Ad Set not connected to Meta.'
            ]);

        }

        $metaAdSets = $this->meta->getAdSets(
            $adset->campaign->adAccount->meta_id
        );

        $metaData = collect($metaAdSets['data'] ?? [])
            ->firstWhere('id',$adset->meta_id);

        if(!$metaData){

            return back()->withErrors([
                'meta'=>'Ad Set not found on Meta.'
            ]);

        }

        $adset->update([

            'status' => $metaData['status'] ?? $adset->status,
            'daily_budget' => isset($metaData['daily_budget'])
                ? (int) $metaData['daily_budget']
                : $adset->daily_budget,

        ]);

        return back()->with('success','Ad Set synced from Meta.');

    } catch(\Throwable $e){

        return back()->withErrors([
            'meta'=>'Unable to sync Ad Set: '.$e->getMessage()
        ]);
    }
}
public function edit(AdSet $adset)
{
    $adset->load('campaign');

    $campaigns = Campaign::latest()->get();

    $countries = config('meta.countries', []);
    $languages = config('meta.languages', []);

    /*
    |--------------------------------------------------------------------------
    | META PAGES
    |--------------------------------------------------------------------------
    */

    try {

        $pages = $this->meta->getPages();

    } catch (\Throwable $e) {

        Log::error('META_PAGES_FETCH_FAILED',[
            'error'=>$e->getMessage()
        ]);

        $pages = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Decode Targeting JSON
    |--------------------------------------------------------------------------
    */

    $rawTargeting = $adset->targeting;
    $targeting = is_array($rawTargeting)
        ? $rawTargeting
        : json_decode($rawTargeting ?? '{}', true);

    $adset->countries = array_values(array_unique(array_merge(
        $targeting['geo_locations']['countries'] ?? [],
        collect($targeting['geo_locations']['cities'] ?? [])
            ->map(fn ($city) => is_array($city) ? strtoupper((string) ($city['country'] ?? '')) : '')
            ->filter()
            ->all()
    )));

    $adset->cities =
        $targeting['geo_locations']['cities'] ?? [];

    $adset->geo_mode = ! empty($adset->cities)
        ? 'countries_and_cities'
        : 'countries_only';

    $adset->age_min =
        $targeting['age_min'] ?? 18;

    $adset->age_max =
        $targeting['age_max'] ?? 65;

    $adset->genders =
        $targeting['genders'] ?? [];

    $adset->languages =
        $targeting['locales'] ?? [];

    /*
    |--------------------------------------------------------------------------
    | Interests
    |--------------------------------------------------------------------------
    */

    $interests = [];
    $interestOptions = [];

    if (! empty($targeting['flexible_spec'][0]['interests'])) {
        foreach ($targeting['flexible_spec'][0]['interests'] as $interest) {
            $id = (string) ($interest['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $interests[] = $id;
            $interestOptions[] = [
                'id' => $id,
                'name' => (string) ($interest['name'] ?? $id),
            ];
        }
    }

    $adset->interests = $interests;

    /*
    |--------------------------------------------------------------------------
    | Placements
    |--------------------------------------------------------------------------
    */

    $adset->publisher_platforms =
        $targeting['publisher_platforms'] ?? [];

    $adset->placement_type =
        !empty($adset->publisher_platforms)
        ? 'manual'
        : 'automatic';


    return view('admin.adsets.edit', [
        'adset' => $adset,
        'campaigns' => $campaigns,
        'countries' => $countries,
        'languages' => $languages,
        'pages' => $pages,
        'interestOptions' => $interestOptions,
    ]);
}
public function update(Request $request, AdSet $adset)
{
    $data = $request->validate([

        'name' => 'required|string|max:255',

        'daily_budget' => 'required|numeric|min:5',

        'status' => 'required|in:ACTIVE,PAUSED',

        'page_id' => 'required|string',

        'age_min' => 'required|integer|min:18|max:65',
        'age_max' => 'required|integer|min:18|max:65',

        'geo_mode' => 'required|in:countries_only,countries_and_cities',

        'countries' => 'required|array|min:1',
        'cities_json' => 'nullable|string',
        'genders' => 'nullable|array',
        'languages' => 'nullable|array',
        'interests' => 'nullable|array|max:5',
        'interests_json' => 'nullable|string',

        'placement_type' => 'required|in:automatic,manual',
        'publisher_platforms' => 'nullable|array'
    ]);

    if ($data['age_min'] >= $data['age_max']) {

        return back()->withErrors([
            'age' => 'Maximum age must be greater than minimum age'
        ])->withInput();
    }

    try {

        /*
        |--------------------------------------------------------------------------
        | Rebuild Targeting
        |--------------------------------------------------------------------------
        */

        $targeting = $this->buildAdSetTargeting($data);
        $languages = array_map('intval', $data['languages'] ?? []);

        $metaTargeting = $targeting;
        unset($metaTargeting['locales']);

        /*
        |--------------------------------------------------------------------------
        | UPDATE META (including targeting so delivery matches local settings)
        |--------------------------------------------------------------------------
        */

        if ($adset->meta_id) {

            $payload = [

                'name' => $data['name'],

                'daily_budget' => (int)$data['daily_budget'] * 100,

                'status' => $data['status'],

                'targeting' => $metaTargeting,
            ];

            Log::info('META_ADSET_UPDATE_PAYLOAD', [
                'adset_id' => $adset->meta_id,
                'payload' => $payload
            ]);

            $this->meta->updateAdSet(
                $adset->meta_id,
                $payload
            );
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE LOCAL DATABASE
        |--------------------------------------------------------------------------
        */

        $adset->update([

            'name' => $data['name'],

            'daily_budget' => (int)$data['daily_budget'] * 100,

            'status' => $data['status'],

            'targeting' => array_merge($targeting, [
                'locales' => $languages,
            ]),
        ]);

        return redirect()
            ->route('admin.adsets.index')
            ->with('success', 'Ad Set updated successfully');
    }

    catch (\Throwable $e) {

        Log::error('ADSET_UPDATE_FAILED', [

            'adset_id' => $adset->id,

            'meta_id' => $adset->meta_id,

            'error' => $e->getMessage()
        ]);

        return back()
            ->withInput()
            ->withErrors([
                'meta' => $e->getMessage()
            ]);
    }
}

    /**
     * Build a Meta-compatible targeting spec from validated form data.
     */
    private function buildAdSetTargeting(array $data): array
    {
        $cities = [];

        if (($data['geo_mode'] ?? 'countries_only') === 'countries_and_cities'
            && ! empty($data['cities_json'])) {
            $decoded = json_decode($data['cities_json'], true);

            if (is_array($decoded)) {
                $cities = $decoded;
            }
        }

        $targeting = [
            'geo_locations' => $this->meta->buildGeoLocations(
                $data['countries'],
                $cities
            ),
            'age_min' => (int) $data['age_min'],
            'age_max' => (int) $data['age_max'],
        ];

        if (! empty($data['genders'])) {
            $targeting['genders'] = array_map('intval', $data['genders']);
        }

        if (! empty($data['languages'])) {
            $targeting['locales'] = array_map('intval', $data['languages']);
        }

        if (! empty($data['interests'])) {
            $interestList = [];
            $interestNames = [];

            if (! empty($data['interests_json'])) {
                $decodedInterests = json_decode($data['interests_json'], true);
                if (is_array($decodedInterests)) {
                    foreach ($decodedInterests as $interest) {
                        if (! is_array($interest)) {
                            continue;
                        }

                        $id = (string) ($interest['id'] ?? '');
                        if ($id !== '') {
                            $interestNames[$id] = (string) ($interest['name'] ?? $id);
                        }
                    }
                }
            }

            foreach ($data['interests'] as $interestId) {
                $id = (string) $interestId;
                $entry = ['id' => $id];

                if (! empty($interestNames[$id])) {
                    $entry['name'] = $interestNames[$id];
                }

                $interestList[] = $entry;
            }

            $targeting['flexible_spec'] = [[
                'interests' => $interestList,
            ]];
        }

        if (($data['placement_type'] ?? 'automatic') === 'manual') {
            if (empty($data['publisher_platforms'])) {
                throw new Exception('Select at least one placement');
            }

            $targeting['publisher_platforms'] = array_values(array_diff(
                $data['publisher_platforms'],
                ['audience_network', 'messenger']
            ));

            if (! empty($targeting['publisher_platforms'])) {
                $targeting = $this->meta->enrichPlacementsForTargeting($targeting);
            }
        } else {
            $targeting = $this->meta->targetingWithFacebookAndInstagram($targeting);
        }

        return $targeting;
    }
}