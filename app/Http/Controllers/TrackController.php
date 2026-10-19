<?php

namespace App\Http\Controllers;

use App\Jobs\UploadConversionJob;
use App\Models\AdSpend;
use App\Models\Conversion;
use App\Models\ConversionAction;
use App\Models\GoogleAdsAccount;
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
            'tracks' => Track::query()->with('account:id,name')->latest()->get()->map(fn (Track $track): array => [
                'id' => $track->id,
                'product_name' => $track->product_name,
                'platform' => config('flowhub.platforms.'.$track->platform, $track->platform),
                'page_type' => config('flowhub.page_types.'.$track->page_type, $track->page_type),
                'strategy' => config('flowhub.strategies.'.$track->strategy, $track->strategy),
                'account' => $track->account?->name,
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tracks/Form', [
            'track' => null,
            'accounts' => $this->accounts(),
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
                'account' => $track->account?->name,
                'conversion_action' => $track->conversionAction?->name,
                'campaign_ids' => $track->campaign_ids ?? [],
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
            'visits' => $track->visits()->latest('landed_at')->limit(50)->get(['id', 'session_id', 'gclid', 'ip_address', 'landed_at', 'converted'])
                ->map(fn (Visit $visit): array => [
                    'id' => $visit->id,
                    'session_id' => $visit->session_id,
                    'gclid' => $visit->gclid,
                    'ip_address' => $visit->ip_address,
                    'landed_at' => $visit->landed_at?->toDateTimeString(),
                    'converted' => $visit->converted,
                ]),
            'spend' => $track->adSpend()->latest()->limit(20)->get()->map(fn (AdSpend $spend): array => [
                'id' => $spend->id,
                'start_date' => $spend->start_date?->toDateString(),
                'end_date' => $spend->end_date?->toDateString(),
                'amount_cad' => $spend->amount_cad,
                'note' => $spend->note,
            ]),
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
     * @return list<array{id: int, name: string, customer_id: string}>
     */
    private function accounts(): array
    {
        return GoogleAdsAccount::query()->orderBy('name')->get(['id', 'name', 'customer_id'])
            ->map(fn (GoogleAdsAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'customer_id' => $account->customer_id,
            ])->all();
    }
}
