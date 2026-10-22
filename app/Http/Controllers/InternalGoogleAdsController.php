<?php

namespace App\Http\Controllers;

use App\Models\GoogleAdsAccount;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InternalGoogleAdsController extends Controller
{
    public function __construct(
        protected GoogleAdsClient $googleAds
    ) {}

    /**
     * POST /internal/google-ads/campaigns
     * Create a new Search campaign and budget (always PAUSED).
     */
    public function createCampaign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'budget_amount_micros' => ['required', 'integer', 'min:1000'],
            'bidding_strategy' => ['nullable', 'string', Rule::in(['MANUAL_CPC', 'MAXIMIZE_CLICKS', 'MAXIMIZE_CONVERSIONS', 'TARGET_CPA', 'TARGET_ROAS'])],
            'account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'customer_id' => ['nullable', 'string'],
        ]);

        $account = $this->resolveAccount($request);

        try {
            $result = $this->googleAds->createSearchCampaign(
                $account,
                $validated['name'],
                (int) $validated['budget_amount_micros'],
                $validated['bidding_strategy'] ?? 'MANUAL_CPC'
            );

            return response()->json([
                'ok' => true,
                'data' => $result,
            ], 201);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /internal/google-ads/campaigns/{campaign}/ad-groups
     * Create an ad group within a campaign.
     */
    public function createAdGroup(Request $request, string $campaign): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cpc_bid_micros' => ['required', 'integer', 'min:1000'],
            'account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'customer_id' => ['nullable', 'string'],
        ]);

        $account = $this->resolveAccount($request);

        try {
            $result = $this->googleAds->createAdGroup(
                $account,
                $campaign,
                $validated['name'],
                (int) $validated['cpc_bid_micros']
            );

            return response()->json([
                'ok' => true,
                'data' => $result,
            ], 201);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /internal/google-ads/ad-groups/{adGroup}/keywords
     * Add keywords to an ad group.
     */
    public function addKeywords(Request $request, string $adGroup): JsonResponse
    {
        $validated = $request->validate([
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:100'],
            'match_type' => ['nullable', 'string', Rule::in(['BROAD', 'PHRASE', 'EXACT', 'broad', 'phrase', 'exact'])],
            'account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'customer_id' => ['nullable', 'string'],
        ]);

        $account = $this->resolveAccount($request);
        $matchType = strtoupper($validated['match_type'] ?? 'BROAD');

        try {
            $resources = $this->googleAds->addKeywords(
                $account,
                $adGroup,
                $validated['keywords'],
                $matchType
            );

            return response()->json([
                'ok' => true,
                'data' => [
                    'resource_names' => $resources,
                    'count' => count($resources),
                ],
            ]);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /internal/google-ads/campaigns/{campaign}/negative-keywords
     * Add campaign-level negative keywords.
     */
    public function addNegativeKeywords(Request $request, string $campaign): JsonResponse
    {
        $validated = $request->validate([
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:100'],
            'match_type' => ['nullable', 'string', Rule::in(['BROAD', 'PHRASE', 'EXACT', 'broad', 'phrase', 'exact'])],
            'account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'customer_id' => ['nullable', 'string'],
        ]);

        $account = $this->resolveAccount($request);
        $matchType = strtoupper($validated['match_type'] ?? 'BROAD');

        try {
            $resources = $this->googleAds->addNegativeKeywords(
                $account,
                $campaign,
                $validated['keywords'],
                $matchType
            );

            return response()->json([
                'ok' => true,
                'data' => [
                    'resource_names' => $resources,
                    'count' => count($resources),
                ],
            ]);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /internal/google-ads/ad-groups/{adGroup}/responsive-search-ads
     * Create a Responsive Search Ad (RSA).
     */
    public function createResponsiveSearchAd(Request $request, string $adGroup): JsonResponse
    {
        $validated = $request->validate([
            'headlines' => ['required', 'array', 'min:3', 'max:15'],
            'headlines.*' => ['required', 'string', 'max:30'],
            'descriptions' => ['required', 'array', 'min:2', 'max:4'],
            'descriptions.*' => ['required', 'string', 'max:90'],
            'final_url' => ['required', 'url'],
            'path1' => ['nullable', 'string', 'max:15'],
            'path2' => ['nullable', 'string', 'max:15'],
            'account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'customer_id' => ['nullable', 'string'],
        ]);

        $account = $this->resolveAccount($request);

        try {
            $result = $this->googleAds->createResponsiveSearchAd(
                $account,
                $adGroup,
                $validated['headlines'],
                $validated['descriptions'],
                $validated['final_url'],
                $validated['path1'] ?? null,
                $validated['path2'] ?? null
            );

            return response()->json([
                'ok' => true,
                'data' => $result,
            ], 201);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * PATCH /internal/google-ads/campaigns/{campaign}/status
     * Update campaign status. REMOVED requires confirm: true.
     */
    public function updateCampaignStatus(Request $request, string $campaign): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['ENABLED', 'PAUSED', 'REMOVED', 'enabled', 'paused', 'removed'])],
            'confirm' => ['nullable', 'boolean'],
            'account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'customer_id' => ['nullable', 'string'],
        ]);

        $status = strtoupper($validated['status']);
        $confirm = (bool) ($validated['confirm'] ?? false);

        if ($status === 'REMOVED' && ! $confirm) {
            return response()->json([
                'ok' => false,
                'error' => 'Confirming REMOVED status requires confirm: true.',
            ], 422);
        }

        $account = $this->resolveAccount($request);

        try {
            $this->googleAds->updateCampaignStatus($account, $campaign, $status, $confirm);

            return response()->json([
                'ok' => true,
                'message' => "Campaign status updated to {$status}.",
            ]);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * PATCH /internal/google-ads/budgets/{budget}
     * Update campaign budget amount.
     */
    public function updateCampaignBudget(Request $request, string $budget): JsonResponse
    {
        $validated = $request->validate([
            'amount_micros' => ['required', 'integer', 'min:1000'],
            'account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'customer_id' => ['nullable', 'string'],
        ]);

        $account = $this->resolveAccount($request);

        try {
            $this->googleAds->updateCampaignBudget($account, $budget, (int) $validated['amount_micros']);

            return response()->json([
                'ok' => true,
                'message' => 'Campaign budget updated.',
            ]);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /internal/google-ads/campaigns/{campaign}/ad-groups
     */
    public function listAdGroups(Request $request, string $campaign): JsonResponse
    {
        $account = $this->resolveAccount($request);

        try {
            $adGroups = $this->googleAds->listAdGroups($account, $campaign);

            return response()->json([
                'ok' => true,
                'data' => $adGroups,
            ]);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /internal/google-ads/ad-groups/{adGroup}/keywords
     */
    public function listKeywords(Request $request, string $adGroup): JsonResponse
    {
        $account = $this->resolveAccount($request);

        try {
            $keywords = $this->googleAds->listKeywords($account, $adGroup);

            return response()->json([
                'ok' => true,
                'data' => $keywords,
            ]);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /internal/google-ads/ad-groups/{adGroup}/ads
     */
    public function listAds(Request $request, string $adGroup): JsonResponse
    {
        $account = $this->resolveAccount($request);

        try {
            $ads = $this->googleAds->listAds($account, $adGroup);

            return response()->json([
                'ok' => true,
                'data' => $ads,
            ]);
        } catch (GoogleRequestException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Resolve target GoogleAdsAccount from request or fall back to default account.
     */
    protected function resolveAccount(Request $request): GoogleAdsAccount
    {
        $accountId = $request->input('account_id');
        $customerId = $request->input('customer_id');

        $query = GoogleAdsAccount::query();

        if ($accountId) {
            $account = $query->find($accountId);
        } elseif ($customerId) {
            $digits = preg_replace('/\D/', '', (string) $customerId);
            $account = $query->where('customer_id', $digits)->first();
        } else {
            $account = $query->latest()->first();
        }

        if (! $account) {
            abort(404, 'No Google Ads account found. Link an account first.');
        }

        return $account;
    }
}
