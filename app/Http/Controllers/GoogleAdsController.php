<?php

namespace App\Http\Controllers;

use App\Models\ConversionAction;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GoogleAdsController extends Controller
{
    public function index(Request $request): Response
    {
        $defaultAccount = GoogleAdsAccount::getDefault($request->user());
        $defaultId = $defaultAccount?->id;

        $accounts = GoogleAdsAccount::query()
            ->withCount(['tracks', 'campaigns'])
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
                'tracks_count' => $account->tracks_count,
                'campaigns_count' => $account->campaigns_count,
            ]);

        $campaigns = GoogleAdsCampaign::query()
            ->with('account:id,name,customer_id')
            ->latest()
            ->get()
            ->map(fn (GoogleAdsCampaign $camp): array => [
                'id' => $camp->id,
                'campaign_id' => $camp->campaign_id,
                'name' => $camp->name,
                'status' => $camp->status,
                'budget_amount_micros' => $camp->budget_amount_micros,
                'bidding_strategy' => $camp->bidding_strategy,
                'account_id' => $camp->google_ads_account_id,
                'account' => $camp->account ? [
                    'id' => $camp->account->id,
                    'name' => $camp->account->name,
                    'customer_id' => $camp->account->customer_id,
                ] : null,
            ]);

        return Inertia::render('Google/Index', [
            'accounts' => $accounts,
            'campaigns' => $campaigns,
            'default_account_id' => $defaultId,
            'pending' => $request->session()->get('google_oauth_customers', []),
        ]);
    }

    public function connect(Request $request, GoogleAdsClient $googleAds): RedirectResponse
    {
        try {
            $googleAds->assertOauthConfigured();
        } catch (GoogleRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($googleAds->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleAdsClient $googleAds): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('google.index')->with('error', 'Google declined the link: '.$request->string('error'));
        }
        if (! hash_equals((string) $request->session()->pull('google_oauth_state'), (string) $request->query('state'))) {
            return redirect()->route('google.index')->with('error', 'The Google link expired. Start it again.');
        }

        try {
            $token = $googleAds->exchangeCode((string) $request->query('code'));
            $customers = $googleAds->listAccessibleCustomers($token['access_token']);
        } catch (GoogleRequestException $exception) {
            return redirect()->route('google.index')->with('error', $exception->getMessage());
        }

        $request->session()->put('google_oauth_refresh', $token['refresh_token']);
        $request->session()->put('google_oauth_access', $token['access_token']);
        $request->session()->put('google_oauth_customers', $customers);

        return redirect()->route('google.index')->with('success', 'Choose the Google Ads account this login can use.');
    }

    public function store(Request $request, GoogleAdsClient $googleAds): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'login_customer_id' => ['nullable', 'string'],
        ]);
        $refresh = (string) $request->session()->get('google_oauth_refresh');
        $access = (string) $request->session()->get('google_oauth_access');
        if ($refresh === '' || $access === '') {
            return back()->with('error', 'The Google link expired. Start it again.');
        }

        $customerId = $googleAds->digits($data['customer_id']);
        $loginId = $googleAds->digits((string) ($data['login_customer_id'] ?? ''));
        if (strlen($customerId) !== 10) {
            return back()->with('error', 'Enter the 10 digit Google Ads customer id.');
        }

        try {
            $name = $googleAds->customerName($access, $customerId, $loginId !== '' ? $loginId : null);
        } catch (GoogleRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $isFirst = GoogleAdsAccount::query()->count() === 0;
        $account = GoogleAdsAccount::query()->updateOrCreate(
            ['customer_id' => $customerId],
            [
                'login_customer_id' => $loginId !== '' ? $loginId : null,
                'refresh_token' => $refresh,
                'name' => $name,
                'status' => 'linked',
            ],
        );

        if ($isFirst || ! GoogleAdsAccount::query()->where('is_default', true)->exists()) {
            $account->makeDefault($request->user());
        }

        $request->session()->forget(['google_oauth_refresh', 'google_oauth_access', 'google_oauth_customers']);

        return redirect()->route('google.index')->with('success', 'Google Ads account linked.');
    }

    public function setDefault(Request $request, GoogleAdsAccount $account): RedirectResponse
    {
        $account->makeDefault($request->user());

        return back()->with('success', "Default Google Ads account set to {$account->name} ({$account->customer_id}).");
    }

    public function destroy(Request $request, GoogleAdsAccount $account): RedirectResponse
    {
        $wasDefault = (bool) $account->is_default;
        $account->delete();

        if ($wasDefault) {
            $remaining = GoogleAdsAccount::query()->latest()->first();
            $remaining?->makeDefault($request->user());
        }

        return back()->with('success', 'Google Ads account removed.');
    }

    public function options(GoogleAdsAccount $account, GoogleAdsClient $googleAds): JsonResponse
    {
        try {
            $actions = $googleAds->listConversionActions($account);
            $campaigns = $googleAds->listCampaigns($account);
        } catch (GoogleRequestException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        foreach ($actions as $action) {
            if ($action['id'] === '') {
                continue;
            }
            ConversionAction::query()->updateOrCreate(
                ['google_ads_account_id' => $account->id, 'external_id' => $action['id']],
                ['name' => $action['name'], 'resource_name' => $action['resource_name']],
            );
        }

        foreach ($campaigns as $camp) {
            if ($camp['id'] === '') {
                continue;
            }
            GoogleAdsCampaign::query()->updateOrCreate(
                ['google_ads_account_id' => $account->id, 'campaign_id' => (string) $camp['id']],
                [
                    'customer_id' => $account->customer_id,
                    'name' => $camp['name'],
                    'status' => 'PAUSED',
                ],
            );
        }

        return response()->json([
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'customer_id' => $account->customer_id,
            ],
            'conversion_actions' => $account->conversionActions()->orderBy('name')->get(['id', 'name', 'external_id']),
            'campaigns' => array_map(fn ($camp) => [
                'id' => (string) $camp['id'],
                'name' => $camp['name'],
                'account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'account_name' => $account->name,
            ], $campaigns),
        ]);
    }

    public function conversionTag(GoogleAdsAccount $account, string $action, GoogleAdsClient $googleAds): JsonResponse
    {
        $conversionAction = ConversionAction::query()
            ->where('google_ads_account_id', $account->id)
            ->where(function ($query) use ($action) {
                $query->where('id', $action)
                    ->orWhere('external_id', $action)
                    ->orWhere('resource_name', $action);
            })
            ->first();

        $resourceName = $conversionAction?->resource_name;
        if (! $resourceName) {
            $customerId = $googleAds->digits($account->customer_id);
            $resourceName = str_starts_with($action, 'customers/')
                ? $action
                : "customers/{$customerId}/conversionActions/{$action}";
        }

        try {
            $tag = $googleAds->getConversionTag($account, $resourceName);

            return response()->json([
                'ok' => true,
                'global_site_tag' => $tag['global_site_tag'],
                'event_snippet' => $tag['event_snippet'],
                'type' => $tag['type'] ?? 'WEBPAGE',
                'has_tag' => (bool) ($tag['has_tag'] ?? false),
                'action_name' => $conversionAction?->name,
            ]);
        } catch (GoogleRequestException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
