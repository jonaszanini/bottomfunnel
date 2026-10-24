<?php

namespace App\Http\Controllers;

use App\Jobs\UploadConversionJob;
use App\Models\AdSpend;
use App\Models\Conversion;
use App\Models\ConversionAction;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\IpBlock;
use App\Models\Page;
use App\Models\Track;
use App\Models\Visit;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use App\Services\Tracking\PostbackRecorder;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TrackController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tracks/Index', [
            'tracks' => Track::query()->with('account:id,name,customer_id')->latest()->get()->map(fn (Track $track): array => [
                'id' => $track->id,
                'product_name' => $track->product_name,
                'platform' => config('flowhub.platforms.'.$track->platform, $track->platform),
                'page_type' => config('flowhub.page_types.'.$track->page_type, $track->page_type),
                'strategy' => config('flowhub.strategies.'.$track->strategy, $track->strategy),
                'google_ads_account_id' => $track->google_ads_account_id,
                'account' => $track->account ? [
                    'id' => $track->account->id,
                    'name' => $track->account->name,
                    'customer_id' => $track->account->customer_id,
                ] : null,
                'account_name' => $track->account?->name,
            ]),
        ]);
    }

    public function create(): Response
    {
        $defaultAccount = GoogleAdsAccount::getDefault(request()->user());

        return Inertia::render('Tracks/Form', [
            'track' => null,
            'accounts' => $this->accounts(),
            'default_account_id' => $defaultAccount?->id,
            ...Options::forForms(),
        ]);
    }

    public function store(Request $request, GoogleAdsClient $googleAds): RedirectResponse
    {
        $data = $this->validated($request);
        $action = $this->resolveAction($request, $googleAds, (int) $data['google_ads_account_id']);
        $track = Track::query()->create([
            ...$data,
            'conversion_action_id' => $action->id,
            'campaign_ids' => array_values($request->input('campaign_ids', [])),
        ]);

        return redirect()->route('tracks.show', $track)->with('success', 'Track created. The postback URL and script are ready.');
    }

    public function show(Track $track): Response
    {
        $track->load('account:id,name,customer_id', 'conversionAction:id,name');

        $activeBlocks = IpBlock::query()
            ->where('track_id', $track->id)
            ->whereIn('status', ['applied', 'pending', 'whitelisted'])
            ->pluck('status', 'ip_address')
            ->all();

        $totalVisits = $track->visits()->count();
        $hopVisits = $track->visits()->where('presell_hop', true)->count();
        $hopCtr = $totalVisits > 0 ? round(($hopVisits / $totalVisits) * 100, 2) : 0;

        return Inertia::render('Tracks/Show', [
            'track' => [
                'id' => $track->id,
                'product_name' => $track->product_name,
                'platform' => $track->platform,
                'platform_label' => config('flowhub.platforms.'.$track->platform, $track->platform),
                'page_type' => config('flowhub.page_types.'.$track->page_type, $track->page_type),
                'strategy' => config('flowhub.strategies.'.$track->strategy, $track->strategy),
                'language' => $track->language,
                'typical_commission_cad' => $track->typical_commission_cad,
                'affiliate_url' => $track->affiliate_url,
                'postback_url' => $track->postbackUrl(),
                'script' => $track->scriptTag(),
                'click_param' => $track->clickParam(),
                'google_ads_account_id' => $track->google_ads_account_id,
                'account' => $track->account ? [
                    'id' => $track->account->id,
                    'name' => $track->account->name,
                    'customer_id' => $track->account->customer_id,
                ] : null,
                'account_name' => $track->account?->name,
                'account_customer_id' => $track->account?->customer_id,
                'conversion_action' => $track->conversionAction?->name,
                'campaign_ids' => $track->campaign_ids ?? [],
                'total_visits' => $totalVisits,
                'hop_visits' => $hopVisits,
                'presell_hop_ctr' => $hopCtr,
            ],
            'conversions' => $track->conversions()->latest()->limit(100)->get()->map(fn (Conversion $conversion): array => [
                'id' => $conversion->id,
                'order_id' => $conversion->order_id,
                'status' => $conversion->status,
                'match_source' => $conversion->match_source,
                'commission_value_cad' => $conversion->commission_value_cad,
                'currency' => $conversion->currency,
                'amount' => $conversion->amount,
                'upload_attempts' => $conversion->upload_attempts,
                'last_error' => $conversion->last_error,
                'raw_payload' => $conversion->raw_payload,
                'visit_id' => $conversion->visit_id,
                'created_at' => $conversion->created_at?->toDateTimeString(),
            ]),
            'visits' => $track->visits()->latest('landed_at')->limit(100)
                ->get([
                    'id',
                    'session_id',
                    'gclid',
                    'ip_address',
                    'landed_at',
                    'time_on_page_seconds',
                    'converted',
                    'presell_hop',
                    'hopped_at',
                    'fraud_score',
                    'fraud_reason',
                    'flagged',
                    'auto_blocked',
                ])
                ->map(fn (Visit $visit): array => [
                    'id' => $visit->id,
                    'session_id' => $visit->session_id,
                    'gclid' => $visit->gclid,
                    'ip_address' => $visit->ip_address,
                    'landed_at' => $visit->landed_at?->toDateTimeString(),
                    'time_on_page_seconds' => (int) ($visit->time_on_page_seconds ?? 0),
                    'converted' => (bool) $visit->converted,
                    'presell_hop' => (bool) $visit->presell_hop,
                    'hopped_at' => $visit->hopped_at?->toDateTimeString(),
                    'fraud_score' => (int) ($visit->fraud_score ?? 0),
                    'fraud_reason' => $visit->fraud_reason,
                    'flagged' => (bool) $visit->flagged,
                    'auto_blocked' => (bool) $visit->auto_blocked,
                    'is_blocked' => isset($activeBlocks[$visit->ip_address]),
                    'block_status' => $activeBlocks[$visit->ip_address] ?? null,
                ]),
            'spend' => $track->adSpend()->latest()->limit(20)->get()->map(fn (AdSpend $spend): array => [
                'id' => $spend->id,
                'start_date' => $spend->start_date?->toDateString(),
                'end_date' => $spend->end_date?->toDateString(),
                'amount_cad' => $spend->amount_cad,
                'note' => $spend->note,
            ]),
            'pages' => $track->pages()
                ->with(['campaign.account'])
                ->latest()
                ->get()
                ->map(fn (Page $page): array => [
                    'id' => $page->id,
                    'name' => $page->name,
                    'page_type' => config('flowhub.page_types.'.$page->page_type, $page->page_type),
                    'campaign_id' => $page->campaign_id,
                    'campaign_name' => $page->campaign?->name,
                    'campaign_status' => $page->campaign?->status,
                    'campaign_account_name' => $page->campaign?->account?->name ?? $track->account?->name,
                    'campaign' => $page->campaign ? [
                        'id' => $page->campaign->id,
                        'campaign_id' => $page->campaign->campaign_id,
                        'name' => $page->campaign->name,
                        'status' => $page->campaign->status,
                        'account_name' => $page->campaign->account?->name ?? $track->account?->name,
                    ] : null,
                    'created_at' => $page->created_at?->toDateTimeString(),
                    'updated_at' => $page->updated_at?->toDateTimeString(),
                ]),
            'track_campaigns' => (function () use ($track) {
                $campaignIds = array_filter((array) ($track->campaign_ids ?? []));
                $campaigns = GoogleAdsCampaign::query()
                    ->when($track->google_ads_account_id, fn ($q) => $q->where('google_ads_account_id', $track->google_ads_account_id))
                    ->when(! empty($campaignIds) && ! $track->google_ads_account_id, fn ($q) => $q->whereIn('campaign_id', $campaignIds))
                    ->with('account:id,name,customer_id')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (GoogleAdsCampaign $camp): array => [
                        'campaign_id' => $camp->campaign_id,
                        'name' => $camp->name,
                        'status' => $camp->status,
                        'account_id' => $camp->google_ads_account_id,
                        'account_name' => $camp->account?->name ?? $track->account?->name,
                        'customer_id' => $camp->account?->customer_id ?? $track->account?->customer_id,
                        'is_assigned_to_track' => in_array($camp->campaign_id, $campaignIds, true),
                    ]);

                foreach ($campaignIds as $cid) {
                    if (! $campaigns->contains('campaign_id', (string) $cid)) {
                        $campaigns->push([
                            'campaign_id' => (string) $cid,
                            'name' => "Campaign #{$cid}",
                            'status' => 'ACTIVE',
                            'account_id' => $track->google_ads_account_id,
                            'account_name' => $track->account?->name,
                            'customer_id' => $track->account?->customer_id,
                            'is_assigned_to_track' => true,
                        ]);
                    }
                }

                return $campaigns->values();
            })(),
        ]);
    }

    public function edit(Track $track): Response
    {
        return Inertia::render('Tracks/Form', [
            'track' => [
                'id' => $track->id,
                'product_name' => $track->product_name,
                'platform' => $track->platform,
                'affiliate_url' => $track->affiliate_url,
                'page_type' => $track->page_type,
                'strategy' => $track->strategy,
                'language' => $track->language,
                'typical_commission_cad' => $track->typical_commission_cad,
                'google_ads_account_id' => $track->google_ads_account_id,
                'conversion_action_id' => $track->conversion_action_id,
                'campaign_ids' => $track->campaign_ids ?? [],
            ],
            'accounts' => $this->accounts(),
            'default_account_id' => GoogleAdsAccount::getDefault(request()->user())?->id,
            ...Options::forForms(),
        ]);
    }

    public function update(Request $request, Track $track, GoogleAdsClient $googleAds): RedirectResponse
    {
        $data = $this->validated($request);
        $actionId = $track->conversion_action_id;
        if ($request->input('conversion_mode') === 'new' || $request->filled('conversion_action_id')) {
            $actionId = $this->resolveAction($request, $googleAds, (int) $data['google_ads_account_id'])->id;
        }
        $track->update([
            ...$data,
            'conversion_action_id' => $actionId,
            'campaign_ids' => array_values($request->input('campaign_ids', [])),
        ]);

        return redirect()->route('tracks.show', $track)->with('success', 'Track saved.');
    }

    public function destroy(Track $track): RedirectResponse
    {
        $track->delete();

        return redirect()->route('tracks.index')->with('success', 'Track removed.');
    }

    public function spend(Request $request, Track $track): RedirectResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'amount_cad' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $track->adSpend()->create($data);

        return back()->with('success', 'Ad spend saved in CAD$.');
    }

    public function match(Request $request, Conversion $conversion, PostbackRecorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'visit_id' => ['required', 'integer'],
        ]);
        $visit = Visit::query()->findOrFail($data['visit_id']);
        try {
            $recorder->attach($conversion, $visit);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Conversion attached to the session.');
    }

    public function retry(Conversion $conversion): RedirectResponse
    {
        if ($conversion->status === 'unmatched' || ! $conversion->visit_id) {
            return back()->with('error', 'Match this conversion to a session before upload.');
        }
        UploadConversionJob::dispatch($conversion->id);

        return back()->with('success', 'Upload queued again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'product_name' => ['required', 'string', 'max:160'],
            'platform' => ['required', Rule::in(array_keys(config('flowhub.platforms')))],
            'affiliate_url' => ['required', 'url', 'max:2000'],
            'page_type' => ['required', Rule::in(array_keys(config('flowhub.page_types')))],
            'strategy' => ['required', Rule::in(array_keys(config('flowhub.strategies')))],
            'language' => ['nullable', Rule::in(array_keys(config('flowhub.languages')))],
            'typical_commission_cad' => ['nullable', 'numeric', 'min:0'],
            'google_ads_account_id' => ['required', 'integer', 'exists:google_ads_accounts,id'],
            'campaign_ids' => ['nullable', 'array'],
            'campaign_ids.*' => ['string', 'max:32'],
        ]);
    }

    private function resolveAction(Request $request, GoogleAdsClient $googleAds, int $accountId): ConversionAction
    {
        $account = GoogleAdsAccount::query()->findOrFail($accountId);
        if ($request->input('conversion_mode') === 'new') {
            $name = $request->validate([
                'conversion_action_name' => ['required', 'string', 'max:120'],
            ])['conversion_action_name'];
            try {
                $created = $googleAds->createConversionAction($account, $name);
            } catch (GoogleRequestException $exception) {
                throw ValidationException::withMessages([
                    'conversion_action_name' => $exception->getMessage(),
                ]);
            }

            return $account->conversionActions()->updateOrCreate(
                ['external_id' => $created['id']],
                ['name' => $created['name'], 'resource_name' => $created['resource_name']],
            );
        }

        $id = $request->validate([
            'conversion_action_id' => ['required', 'integer', 'exists:conversion_actions,id'],
        ])['conversion_action_id'];
        $action = ConversionAction::query()->findOrFail($id);
        if ($action->google_ads_account_id !== $account->id) {
            throw ValidationException::withMessages([
                'conversion_action_id' => 'Choose a conversion action from the linked account.',
            ]);
        }

        return $action;
    }

    /**
     * @return list<array{id: int, name: string, customer_id: string, login_customer_id: ?string, is_default: bool, status: string}>
     */
    private function accounts(): array
    {
        $defaultAccount = GoogleAdsAccount::getDefault(request()->user());
        $defaultId = $defaultAccount?->id;

        return GoogleAdsAccount::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'customer_id', 'login_customer_id', 'status', 'is_default'])
            ->map(fn (GoogleAdsAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'customer_id' => $account->customer_id,
                'login_customer_id' => $account->login_customer_id,
                'status' => $account->status,
                'is_default' => $account->id === $defaultId || (bool) $account->is_default,
            ])->all();
    }
}
