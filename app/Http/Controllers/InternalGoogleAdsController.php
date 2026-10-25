<?php

namespace App\Http\Controllers;

use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class InternalGoogleAdsController extends Controller
{
    public function __construct(
        protected GoogleAdsClient $googleAds
    ) {}

    /**
     * GET /internal/google-ads/campaigns
     * List all tracked campaigns with their account ownership.
     */
    public function listCampaigns(Request $request): JsonResponse
    {
        $accountId = $request->query('account_id');
        $query = GoogleAdsCampaign::query()->with('account:id,name,customer_id');

        if ($accountId) {
            $query->where('google_ads_account_id', $accountId);
        }

        $campaigns = $query->latest()->get()->map(fn (GoogleAdsCampaign $c) => [
            'id' => $c->id,
            'campaign_id' => $c->campaign_id,
            'name' => $c->name,
            'status' => $c->status,
            'budget_amount_micros' => $c->budget_amount_micros,
            'bidding_strategy' => $c->bidding_strategy,
            'account_id' => $c->google_ads_account_id,
            'account' => $c->account ? [
                'id' => $c->account->id,
                'name' => $c->account->name,
                'customer_id' => $c->account->customer_id,
            ] : null,
        ]);

        return response()->json([
            'ok' => true,
            'data' => $campaigns,
        ]);
    }

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
            'target_search_network' => ['nullable', 'boolean'],
            'target_content_network' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'language' => ['nullable', 'string', 'max:10'],
            'geos' => ['nullable', 'array'],
            'geos.*' => ['string', 'max:10'],
            'negative_keywords' => ['nullable', 'array'],
            'negative_keywords.*' => ['string', 'max:100'],
            'ad_group_name' => ['nullable', 'string', 'max:255'],
            'cpc_bid_micros' => ['nullable', 'integer', 'min:10000'],
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['string', 'max:100'],
            'keyword_match_type' => ['nullable', 'string', Rule::in(['BROAD', 'PHRASE', 'EXACT'])],
            'final_url' => ['nullable', 'url', 'max:2048'],
            'headlines' => ['nullable', 'array'],
            'headlines.*' => ['string', 'max:30'],
            'descriptions' => ['nullable', 'array'],
            'descriptions.*' => ['string', 'max:90'],
        ]);

        $account = $this->resolveAccount($request);

        try {
            $options = [
                'target_search_network' => (bool) ($validated['target_search_network'] ?? true),
                'target_content_network' => (bool) ($validated['target_content_network'] ?? false),
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
            ];

            $result = $this->googleAds->createSearchCampaign(
                $account,
                $validated['name'],
                (int) $validated['budget_amount_micros'],
                $validated['bidding_strategy'] ?? 'MANUAL_CPC',
                $options
            );

            // Store the campaign and its account ownership
            GoogleAdsCampaign::query()->updateOrCreate(
                [
                    'google_ads_account_id' => $account->id,
                    'campaign_id' => $result['campaign_id'],
                ],
                [
                    'customer_id' => $account->customer_id,
                    'name' => $validated['name'],
                    'status' => 'PAUSED',
                    'budget_amount_micros' => (int) $validated['budget_amount_micros'],
                    'bidding_strategy' => $validated['bidding_strategy'] ?? 'MANUAL_CPC',
                    'resource_name' => $result['campaign_resource'] ?? null,
                ]
            );

            // 1. Add Location Targets (Geos)
            $geosAdded = 0;
            if (! empty($validated['geos'])) {
                try {
                    $created = $this->googleAds->addLocationTargets(
                        $account,
                        (string) $result['campaign_id'],
                        $validated['geos']
                    );
                    $geosAdded = count($created);
                } catch (\Throwable $e) {
                    Log::warning('Failed adding location targets: '.$e->getMessage());
                }
            }

            // 2. Add Language Target
            $languageAdded = false;
            if (! empty($validated['language'])) {
                try {
                    $this->googleAds->addLanguageTarget(
                        $account,
                        (string) $result['campaign_id'],
                        $validated['language']
                    );
                    $languageAdded = true;
                } catch (\Throwable $e) {
                    Log::warning('Failed adding language target: '.$e->getMessage());
                }
            }

            // 3. Add Negative Keywords
            $negativesAdded = 0;
            if (! empty($validated['negative_keywords'])) {
                try {
                    $this->googleAds->addNegativeKeywords(
                        $account,
                        (string) $result['campaign_id'],
                        $validated['negative_keywords']
                    );
                    $negativesAdded = count($validated['negative_keywords']);
                } catch (\Throwable $e) {
                    Log::warning('Failed adding negative keywords during campaign creation: '.$e->getMessage());
                }
            }

            // 4. Create Ad Group (if ad group name, keywords, or headlines are provided)
            $adGroupId = null;
            $keywordsAdded = 0;
            $rsaCreated = false;

            $hasAdGroup = ! empty($validated['ad_group_name']) || ! empty($validated['keywords']) || ! empty($validated['headlines']);
            if ($hasAdGroup) {
                $adGroupName = ! empty($validated['ad_group_name'])
                    ? $validated['ad_group_name']
                    : ($validated['name'].' - Ad Group 1');
                $cpcBid = (int) ($validated['cpc_bid_micros'] ?? 1500000);

                try {
                    $adGroupResult = $this->googleAds->createAdGroup(
                        $account,
                        (string) $result['campaign_id'],
                        $adGroupName,
                        $cpcBid
                    );
                    $adGroupId = $adGroupResult['ad_group_id'];

                    // 5. Add Keywords to Ad Group
                    if (! empty($validated['keywords']) && $adGroupId) {
                        try {
                            $kwResources = $this->googleAds->addKeywords(
                                $account,
                                (string) $adGroupId,
                                $validated['keywords'],
                                $validated['keyword_match_type'] ?? 'EXACT'
                            );
                            $keywordsAdded = count($kwResources);
                        } catch (\Throwable $e) {
                            Log::warning('Failed adding keywords: '.$e->getMessage());
                        }
                    }

                    // 6. Create Responsive Search Ad (RSA)
                    $hasRsa = ! empty($validated['headlines']) && ! empty($validated['descriptions']) && ! empty($validated['final_url']);
                    if ($hasRsa && $adGroupId && count($validated['headlines']) >= 3 && count($validated['descriptions']) >= 2) {
                        try {
                            $this->googleAds->createResponsiveSearchAd(
                                $account,
                                (string) $adGroupId,
                                $validated['headlines'],
                                $validated['descriptions'],
                                $validated['final_url']
                            );
                            $rsaCreated = true;
                        } catch (\Throwable $e) {
                            Log::warning('Failed creating RSA: '.$e->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed creating ad group during campaign creation: '.$e->getMessage());
                }
            }

            return response()->json([
                'ok' => true,
                'data' => [
                    ...$result,
                    'account_id' => $account->id,
                    'customer_id' => $account->customer_id,
                    'account_name' => $account->name,
                    'ad_group_id' => $adGroupId,
                    'geos_added' => $geosAdded,
                    'language_added' => $languageAdded,
                    'negatives_added' => $negativesAdded,
                    'keywords_added' => $keywordsAdded,
                    'rsa_created' => $rsaCreated,
                ],
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

            GoogleAdsCampaign::query()
                ->where('google_ads_account_id', $account->id)
                ->where('campaign_id', $campaign)
                ->update(['status' => $status]);

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
     * Resolve target GoogleAdsAccount from request or fall back to configured default account.
     * Never silently defaults to "the latest" account when multiple accounts exist.
     */
    protected function resolveAccount(Request $request): GoogleAdsAccount
    {
        $accountId = $request->input('account_id');
        $customerId = $request->input('customer_id');

        $query = GoogleAdsAccount::query();

        if ($accountId) {
            $account = $query->find($accountId);
            if (! $account) {
                abort(404, "Google Ads account ID {$accountId} not found.");
            }

            return $account;
        }

        if ($customerId) {
            $digits = preg_replace('/\D/', '', (string) $customerId);
            $account = $query->where('customer_id', $digits)->first();
            if (! $account) {
                abort(404, "Google Ads customer ID {$customerId} not found.");
            }

            return $account;
        }

        // Explicit account wasn't passed: check configured default fallback
        $account = GoogleAdsAccount::getDefault($request->user());

        if (! $account) {
            if (GoogleAdsAccount::query()->count() === 0) {
                abort(422, 'No linked Google Ads account found. Please link an account in Google Ads settings first.');
            }

            abort(422, 'Target Google Ads account must be explicitly selected, or a default account must be configured.');
        }

        return $account;
    }
}
