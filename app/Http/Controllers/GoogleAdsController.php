<?php

namespace App\Http\Controllers;

use App\Models\ConversionAction;
use App\Models\GoogleAdsAccount;
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
        return Inertia::render('Google/Index', [
            'accounts' => GoogleAdsAccount::query()->latest()->get(['id', 'name', 'customer_id', 'login_customer_id', 'status']),
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

        GoogleAdsAccount::query()->updateOrCreate(
            ['customer_id' => $customerId],
            [
                'login_customer_id' => $loginId !== '' ? $loginId : null,
                'refresh_token' => $refresh,
                'name' => $name,
                'status' => 'linked',
            ],
        );
        $request->session()->forget(['google_oauth_refresh', 'google_oauth_access', 'google_oauth_customers']);

        return redirect()->route('google.index')->with('success', 'Google Ads account linked.');
    }

    public function destroy(GoogleAdsAccount $account): RedirectResponse
    {
        $account->delete();

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

        return response()->json([
            'conversion_actions' => $account->conversionActions()->orderBy('name')->get(['id', 'name', 'external_id']),
            'campaigns' => $campaigns,
        ]);
    }
}
