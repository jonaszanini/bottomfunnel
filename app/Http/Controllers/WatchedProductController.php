<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\BrandBiddingStatus;
use App\Enums\ComplianceRisk;
use App\Enums\FlowspyTrafficTrend;
use App\Enums\ListingStatus;
use App\Enums\NegativeKeywordMatchType;
use App\Enums\NegativeKeywordSource;
use App\Enums\PayoutModel;
use App\Enums\ProductKind;
use App\Enums\Strategy;
use App\Enums\StrategySource;
use App\Enums\TrendsTrend;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use App\Jobs\RefreshSingleWatchedProductJob;
use App\Models\GoogleAdsAccount;
use App\Models\ProductNegativeKeyword;
use App\Models\ProductScoreHistory;
use App\Models\Track;
use App\Models\WatchedProduct;
use App\Services\Google\GoogleAdsQuotaException;
use App\Services\WatchingProducts\BofChecker;
use App\Services\WatchingProducts\CampaignGuidance;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\KeywordVolumeService;
use App\Services\WatchingProducts\Money;
use App\Services\WatchingProducts\NegativeKeywordGenerator;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\RankService;
use App\Services\WatchingProducts\StrategyRecommender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WatchedProductController extends Controller
{
    public function __construct(
        protected FrankfurterFx $fx = new FrankfurterFx,
        protected PotentialScoreService $scoreService = new PotentialScoreService,
        protected StrategyRecommender $strategyRecommender = new StrategyRecommender,
        protected CampaignGuidance $guidance = new CampaignGuidance,
        protected QuotaBudget $budget = new QuotaBudget,
        protected NegativeKeywordGenerator $negativeGenerator = new NegativeKeywordGenerator,
        protected RankService $rankService = new RankService,
        protected BofChecker $bofChecker = new BofChecker
    ) {}

    /**
     * Display a listing of watched products with filtering, search, scores, and rankings.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
            'strategy' => $request->input('strategy', 'all'),
            'product_kind' => $request->input('product_kind', 'all'),
            'brand_bidding' => $request->input('brand_bidding', 'all'),
            'true_bof' => $request->input('true_bof', 'all'),
            'platform' => $request->input('platform', 'all'),
            'recheck_overdue' => $request->boolean('recheck_overdue'),
            'volume' => $request->input('volume', 'all'),
            'sort' => $request->input('sort'),
            'direction' => $request->input('direction'),
        ];

        $query = WatchedProduct::query()
            ->filter($filters)
            ->with([
                'tracks' => fn ($q) => $q->select(['id', 'watched_product_id', 'product_name', 'platform', 'page_type', 'strategy', 'google_ads_account_id', 'campaign_ids'])
                    ->with('account:id,name,customer_id,status'),
            ]);
        $productsCollection = $query->get();
        $productIds = $productsCollection->pluck('id');

        $scoreHistories = ProductScoreHistory::query()
            ->whereIn('product_id', $productIds)
            ->orderByDesc('created_at')
            ->get(['product_id', 'score', 'created_at'])
            ->groupBy('product_id');

        $products = $productsCollection->map(function (WatchedProduct $product) use ($scoreHistories): array {
            $recommendation = $this->strategyRecommender->recommend($product);
            $payoutMoney = Money::make($product->payout_amount ?? $product->payout_cad, $product->payout_currency ?? 'USD');

            $history = $scoreHistories->get($product->id);
            $scoreDelta = null;
            if ($history && $history->count() >= 2) {
                $scoreDelta = (int) $history[0]->score - (int) $history[1]->score;
            } elseif ($history && $history->count() === 1 && $product->score !== null) {
                $diff = (int) $product->score - (int) $history[0]->score;
                if ($diff !== 0) {
                    $scoreDelta = $diff;
                }
            }

            return [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $product->status->value,
                'status_label' => $product->status->label(),
                'rank' => $product->rank,
                'score' => $product->score,
                'score_delta' => $scoreDelta,
                'score_breakdown' => $product->score_breakdown ?? [],
                'score_flags' => $product->score_flags ?? [],
                'payout_amount' => $product->payout_amount !== null ? (float) $product->payout_amount : null,
                'payout_currency' => $product->payout_currency ?? 'USD',
                'payout_cad' => $product->payout_cad !== null ? (float) $product->payout_cad : $payoutMoney->toCad(),
                'payout_usd' => $payoutMoney->toUsd(),
                'payout_formatted' => $payoutMoney->format(true),
                'payout_model' => $product->payout_model?->value,
                'payout_notes' => $product->payout_notes,
                'page_type' => $product->page_type,
                'product_kind' => $product->product_kind?->value ?? 'nutra',
                'product_kind_label' => $product->product_kind?->label() ?? 'Nutra',
                'esteira' => (bool) $product->esteira,
                'restricted_category' => (bool) $product->restricted_category,
                'strategy' => $product->strategy?->value ?? $recommendation['strategy']->value,
                'strategy_label' => $product->strategy?->label() ?? $recommendation['strategy']->label(),
                'strategy_source' => $product->strategy_source?->value ?? 'auto',
                'strategy_reason' => $recommendation['reason'],
                'trends_trend' => $product->effectiveTrendsTrend()->value,
                'trends_trend_label' => $product->trendsTrendLabel(),
                'trends_30d' => $product->trends_30d !== null ? (float) $product->trends_30d : null,
                'flowspy_traffic_trend' => $product->flowspy_traffic_trend?->value,
                'brand_bidding' => $product->brand_bidding->value,
                'brand_bidding_label' => $product->brand_bidding->label(),
                'true_bof' => $product->true_bof->value,
                'true_bof_label' => $product->true_bof->label(),
                'bof_translation' => (bool) $product->bof_translation,
                'bof_vpn_serp' => (bool) $product->bof_vpn_serp,
                'bof_image_match_pct' => (int) ($product->bof_image_match_pct ?? 0),
                'bof_qualifier' => $product->bof_qualifier,
                'bof_homonym' => (bool) $product->bof_homonym,
                'geos' => $product->geos ?? [],
                'allowed_geos' => $product->allowed_geos ?? [],
                'compliance_risk' => $product->compliance_risk?->value ?? 'not_assessed',
                'compliance_risk_label' => $product->compliance_risk?->label() ?? 'Not Assessed',
                'compliance_notes' => $product->compliance_notes,
                'network_approval_required' => (bool) $product->network_approval_required,
                'network_approval_status' => $product->network_approval_status?->value ?? ($product->network_approval_required ? 'yes' : 'unknown'),
                'network_approval_status_label' => $product->network_approval_status?->label() ?? ($product->network_approval_required ? 'Requires Approval' : 'Approval Unknown'),
                'approval_status' => $product->approval_status?->value ?? ($product->approval_required ? 'yes' : 'unknown'),
                'approval_status_label' => $product->approval_status?->label() ?? ($product->approval_required ? 'Requires Approval' : 'Approval Unknown'),
                'strategy_phase2' => $product->strategy_phase2?->value,
                'strategy_phase2_label' => $product->strategy_phase2?->label(),
                'strategy_switch_trigger' => $product->strategy_switch_trigger,
                'recheck_at' => $product->recheck_at?->format('Y-m-d'),
                'recheck_at_formatted' => $product->recheck_at?->format('M d, Y'),
                'is_recheck_overdue' => $product->recheck_at !== null && $product->recheck_at->lte(today()),
                'promotion_criteria' => $product->promotion_criteria,
                'payouts_by_platform' => $product->payouts_by_platform ?? [],
                'search_volume_total' => $product->search_volume_total,
                'search_volume_by_geo' => $product->search_volume_by_geo ?? [],
                'search_volume_is_range' => (bool) $product->search_volume_is_range,
                'search_volume_keyword' => $product->search_volume_keyword,
                'search_volume_checked_at' => $product->search_volume_checked_at?->toIso8601String(),
                'search_volume_checked_formatted' => $product->search_volume_checked_at?->format('M d, Y'),
                'search_volume_error' => $product->search_volume_error,
                'search_volume_error_at' => $product->search_volume_error_at?->toIso8601String(),
                'platforms' => $product->platforms ?? [],
                'platform' => $product->platform,
                'approval_required' => (bool) $product->approval_required,
                'demand_notes' => $product->demand_notes,
                'sales_page_url' => $product->sales_page_url,
                'affiliate_link' => $product->affiliate_link,
                'notes' => $product->notes,
                'tracks_count' => $product->tracks->count(),
                'tracks' => $product->tracks->map(fn (Track $track): array => [
                    'id' => $track->id,
                    'name' => $track->product_name,
                    'status' => $track->account?->status ?? 'active',
                    'platform' => $track->platform,
                    'strategy' => $track->strategy,
                    'account_name' => $track->account?->name,
                    'account_customer_id' => $track->account?->customer_id,
                    'campaign_count' => count((array) ($track->campaign_ids ?? [])),
                    'postback_url' => $track->postbackUrl(),
                    'pages_count' => $track->pages()->count(),
                    'conversion_action_id' => $track->conversion_action_id,
                    'conversion_action_name' => $track->conversionAction?->name,
                ])->all(),
                'last_reviewed_at' => $product->last_reviewed_at?->toIso8601String(),
                'last_reviewed_formatted' => $product->last_reviewed_at?->format('M d, Y'),
                'last_refreshed_at' => $product->last_refreshed_at?->toIso8601String(),
                'last_refreshed_formatted' => $product->last_refreshed_at?->format('M d, Y H:i'),
                'created_at' => $product->created_at->toIso8601String(),
                'updated_at' => $product->updated_at->toIso8601String(),
            ];
        });

        // Summary metrics & Portfolio split
        $allProducts = WatchedProduct::query()->get(['id', 'status', 'last_reviewed_at', 'product_kind']);
        $totalCount = $allProducts->count();
        $nutraCount = $allProducts->where('product_kind', ProductKind::Nutra)->count();
        $ecomCount = $allProducts->where('product_kind', ProductKind::Ecom)->count();

        $metrics = [
            'total' => $totalCount,
            'test' => $allProducts->where('status', WatchedProductStatus::Test)->count(),
            'watch' => $allProducts->where('status', WatchedProductStatus::Watch)->count(),
            'rejected' => $allProducts->where('status', WatchedProductStatus::Rejected)->count(),
            'due_review' => $allProducts->filter(fn (WatchedProduct $p): bool => $p->last_reviewed_at === null || $p->last_reviewed_at->lt(now()->subDays(14)))->count(),
            'nutra_count' => $nutraCount,
            'ecom_count' => $ecomCount,
            'nutra_pct' => $totalCount > 0 ? (int) round(($nutraCount / $totalCount) * 100) : 60,
            'ecom_pct' => $totalCount > 0 ? (int) round(($ecomCount / $totalCount) * 100) : 40,
            'overdue_recheck_count' => WatchedProduct::query()->whereNotNull('recheck_at')->where('recheck_at', '<=', now()->toDateString())->count(),
            'serpapi_used' => $this->budget->callsUsedThisMonth(),
            'serpapi_cap' => (int) config('watching.serpapi_monthly_cap', 230),
            'serpapi_limit' => (int) config('watching.serpapi_total_limit', 250),
        ];

        // Gather centralized platforms merged with distinct existing values
        $availablePlatforms = $this->getAvailablePlatforms();

        return Inertia::render('WatchingProducts/Index', [
            'products' => $products,
            'filters' => $filters,
            'metrics' => $metrics,
            'options' => [
                'statuses' => array_map(fn (WatchedProductStatus $s) => [
                    'value' => $s->value,
                    'label' => $s->label(),
                ], WatchedProductStatus::cases()),
                'strategies' => array_map(fn (Strategy $st) => [
                    'value' => $st->value,
                    'label' => $st->label(),
                ], Strategy::cases()),
                'product_kinds' => array_map(fn (ProductKind $pk) => [
                    'value' => $pk->value,
                    'label' => $pk->label(),
                ], ProductKind::cases()),
                'brand_bidding' => array_map(fn (BrandBiddingStatus $b) => [
                    'value' => $b->value,
                    'label' => $b->label(),
                ], BrandBiddingStatus::cases()),
                'true_bof' => array_map(fn (TrueBofStatus $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                ], TrueBofStatus::cases()),
                'platforms' => $availablePlatforms,
                'page_types' => ['DTC', 'TSL', 'Advertorial', 'VSL', 'Quiz', 'Review', 'Presell', 'Launch', 'Other'],
                'currencies' => ['USD', 'CAD', 'EUR', 'GBP', 'BRL'],
                'payout_models' => array_map(fn (PayoutModel $m) => [
                    'value' => $m->value,
                    'label' => $m->label(),
                ], PayoutModel::cases()),
                'compliance_risks' => array_map(fn (ComplianceRisk $c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                ], ComplianceRisk::cases()),
                'approval_statuses' => array_map(fn (ApprovalStatus $a) => [
                    'value' => $a->value,
                    'label' => $a->label(),
                    'short_label' => $a->shortLabel(),
                ], ApprovalStatus::cases()),
            ],
        ]);
    }

    /**
     * Display detailed product page with breakdown, guidance, score history, and negative keywords.
     */
    public function show(Request $request, WatchedProduct $watchedProduct): Response
    {
        $recommendation = $this->strategyRecommender->recommend($watchedProduct);
        $guidance = $this->guidance->forProduct($watchedProduct);
        $watchedProduct->load([
            'tracks' => fn ($q) => $q->select(['id', 'watched_product_id', 'product_name', 'platform', 'page_type', 'strategy', 'google_ads_account_id', 'campaign_ids', 'created_at'])
                ->with('account:id,name,customer_id,status'),
        ]);

        $payoutMoney = Money::make($watchedProduct->payout_amount ?? $watchedProduct->payout_cad, $watchedProduct->payout_currency ?? 'USD');
        $spendMoney = Money::make($watchedProduct->spend_amount, $watchedProduct->spend_currency ?? 'USD');
        $commissionMoney = Money::make($watchedProduct->commission_earned, $watchedProduct->payout_currency ?? 'USD');

        // Score history ordered chronological for charts
        $history = $watchedProduct->scoreHistory()
            ->orderBy('created_at')
            ->get()
            ->map(fn (ProductScoreHistory $h) => [
                'id' => $h->id,
                'score' => $h->score,
                'breakdown' => $h->breakdown ?? [],
                'flags' => $h->flags ?? [],
                'date' => $h->created_at->format('M d H:i'),
                'created_at' => $h->created_at->toIso8601String(),
            ]);

        // Ensure base seeds are populated if missing
        if ($watchedProduct->negativeKeywords()->where('source', NegativeKeywordSource::Base)->doesntExist()) {
            $this->negativeGenerator->generateBase($watchedProduct);
        }

        // Negative keywords
        $negatives = $watchedProduct->negativeKeywords()
            ->get()
            ->map(fn (ProductNegativeKeyword $kw) => [
                'id' => $kw->id,
                'term' => $kw->term,
                'match_type' => $kw->match_type->value,
                'match_type_label' => $kw->match_type->label(),
                'source' => $kw->source->value,
                'source_label' => $kw->source->label(),
                'enabled' => (bool) $kw->enabled,
                'manually_overridden' => (bool) $kw->manually_overridden,
                'formatted_term' => $kw->formattedTerm(),
                'strategy_rules' => $kw->strategy_rules,
            ]);

        $productData = [
            'id' => $watchedProduct->id,
            'name' => $watchedProduct->name,
            'status' => $watchedProduct->status->value,
            'status_label' => $watchedProduct->status->label(),
            'rank' => $watchedProduct->rank,
            'score' => $watchedProduct->score,
            'score_breakdown' => $watchedProduct->score_breakdown ?? [],
            'score_flags' => $watchedProduct->score_flags ?? [],
            'scored_at' => $watchedProduct->scored_at?->toIso8601String(),
            'last_refreshed_at' => $watchedProduct->last_refreshed_at?->toIso8601String(),
            'last_refreshed_formatted' => $watchedProduct->last_refreshed_at?->format('M d, Y H:i'),
            'last_reviewed_at' => $watchedProduct->last_reviewed_at?->toIso8601String(),
            'last_reviewed_formatted' => $watchedProduct->last_reviewed_at?->format('M d, Y'),

            // Payout
            'payout_amount' => $watchedProduct->payout_amount !== null ? (float) $watchedProduct->payout_amount : null,
            'payout_currency' => $watchedProduct->payout_currency ?? 'USD',
            'payout_cad' => $watchedProduct->payout_cad !== null ? (float) $watchedProduct->payout_cad : $payoutMoney->toCad(),
            'payout_usd' => $payoutMoney->toUsd(),
            'payout_formatted' => $payoutMoney->format(true),
            'payout_model' => $watchedProduct->payout_model?->value,
            'payout_notes' => $watchedProduct->payout_notes,

            // Listing
            'gravity' => $watchedProduct->gravity !== null ? (float) $watchedProduct->gravity : null,
            'platform' => $watchedProduct->platform,
            'platforms' => $watchedProduct->platforms ?? [],
            'listing_url' => $watchedProduct->listing_url,
            'listing_status' => $watchedProduct->listing_status?->value ?? 'unknown',
            'listing_status_label' => $watchedProduct->listing_status?->label() ?? 'Unknown',
            'cancel_rate' => $watchedProduct->cancel_rate !== null ? (float) $watchedProduct->cancel_rate : null,

            // Funnel & Category
            'page_type' => $watchedProduct->page_type,
            'funnel_flags' => $watchedProduct->funnel_flags ?? [],
            'product_kind' => $watchedProduct->product_kind?->value ?? 'nutra',
            'product_kind_label' => $watchedProduct->product_kind?->label() ?? 'Nutra',
            'esteira' => (bool) $watchedProduct->esteira,
            'restricted_category' => (bool) $watchedProduct->restricted_category,
            'strategy' => $watchedProduct->strategy?->value ?? $recommendation['strategy']->value,
            'strategy_label' => $watchedProduct->strategy?->label() ?? $recommendation['strategy']->label(),
            'strategy_source' => $watchedProduct->strategy_source?->value ?? 'auto',
            'strategy_reason' => $recommendation['reason'],

            // BoF Triad
            'brand_bidding' => $watchedProduct->brand_bidding->value,
            'brand_bidding_label' => $watchedProduct->brand_bidding->label(),
            'true_bof' => $watchedProduct->true_bof->value,
            'true_bof_label' => $watchedProduct->true_bof->label(),
            'bof_translation' => (bool) $watchedProduct->bof_translation,
            'bof_vpn_serp' => (bool) $watchedProduct->bof_vpn_serp,
            'bof_image_match_pct' => (int) ($watchedProduct->bof_image_match_pct ?? 0),
            'bof_qualifier' => $watchedProduct->bof_qualifier,
            'bof_homonym' => (bool) $watchedProduct->bof_homonym,

            // Demand
            'trends_7d' => $watchedProduct->trends_7d !== null ? (float) $watchedProduct->trends_7d : null,
            'trends_30d' => $watchedProduct->trends_30d !== null ? (float) $watchedProduct->trends_30d : null,
            'trends_90d' => $watchedProduct->trends_90d !== null ? (float) $watchedProduct->trends_90d : null,
            'trends_trend' => $watchedProduct->effectiveTrendsTrend()->value,
            'trends_trend_label' => $watchedProduct->trendsTrendLabel(),
            'flowspy_traffic_trend' => $watchedProduct->flowspy_traffic_trend?->value,
            'kp_volume' => $watchedProduct->kp_volume,
            'kp_yoy' => $watchedProduct->kp_yoy !== null ? (float) $watchedProduct->kp_yoy : null,
            'kp_3m' => $watchedProduct->kp_3m !== null ? (float) $watchedProduct->kp_3m : null,
            'kp_cpc_amount' => $watchedProduct->kp_cpc_amount !== null ? (float) $watchedProduct->kp_cpc_amount : null,
            'kp_cpc_currency' => $watchedProduct->kp_cpc_currency ?? 'USD',

            // Competition
            'brand_cpc_amount' => $watchedProduct->brand_cpc_amount !== null ? (float) $watchedProduct->brand_cpc_amount : null,
            'brand_cpc_currency' => $watchedProduct->brand_cpc_currency ?? 'USD',
            'est_cvr' => $watchedProduct->est_cvr !== null ? (float) $watchedProduct->est_cvr : 0.02,
            'advertiser_count' => (int) ($watchedProduct->advertiser_count ?? 0),
            'vendor_bids_brand' => (bool) $watchedProduct->vendor_bids_brand,

            // Tracking
            'spend_amount' => (float) ($watchedProduct->spend_amount ?? 0),
            'spend_currency' => $watchedProduct->spend_currency ?? 'USD',
            'sales' => (int) ($watchedProduct->sales ?? 0),
            'checkouts' => (int) ($watchedProduct->checkouts ?? 0),
            'commission_earned' => (float) ($watchedProduct->commission_earned ?? 0),

            // Metadata
            'geos' => $watchedProduct->geos ?? [],
            'allowed_geos' => $watchedProduct->allowed_geos ?? [],
            'compliance_risk' => $watchedProduct->compliance_risk?->value ?? 'not_assessed',
            'compliance_risk_label' => $watchedProduct->compliance_risk?->label() ?? 'Not Assessed',
            'compliance_notes' => $watchedProduct->compliance_notes,
            'network_approval_required' => (bool) $watchedProduct->network_approval_required,
            'network_approval_status' => $watchedProduct->network_approval_status?->value ?? ($watchedProduct->network_approval_required ? 'yes' : 'unknown'),
            'network_approval_status_label' => $watchedProduct->network_approval_status?->label() ?? ($watchedProduct->network_approval_required ? 'Requires Approval' : 'Approval Unknown'),
            'approval_status' => $watchedProduct->approval_status?->value ?? ($watchedProduct->approval_required ? 'yes' : 'unknown'),
            'approval_status_label' => $watchedProduct->approval_status?->label() ?? ($watchedProduct->approval_required ? 'Requires Approval' : 'Approval Unknown'),
            'strategy_phase2' => $watchedProduct->strategy_phase2?->value,
            'strategy_phase2_label' => $watchedProduct->strategy_phase2?->label(),
            'strategy_switch_trigger' => $watchedProduct->strategy_switch_trigger,
            'recheck_at' => $watchedProduct->recheck_at?->format('Y-m-d'),
            'recheck_at_formatted' => $watchedProduct->recheck_at?->format('M d, Y'),
            'is_recheck_overdue' => $watchedProduct->recheck_at !== null && $watchedProduct->recheck_at->lte(today()),
            'promotion_criteria' => $watchedProduct->promotion_criteria,
            'payouts_by_platform' => $watchedProduct->payouts_by_platform ?? [],
            'search_volume_total' => $watchedProduct->search_volume_total,
            'search_volume_by_geo' => $watchedProduct->search_volume_by_geo ?? [],
            'search_volume_is_range' => (bool) $watchedProduct->search_volume_is_range,
            'search_volume_keyword' => $watchedProduct->search_volume_keyword,
            'search_volume_checked_at' => $watchedProduct->search_volume_checked_at?->toIso8601String(),
            'search_volume_checked_formatted' => $watchedProduct->search_volume_checked_at?->format('M d, Y'),
            'search_volume_error' => $watchedProduct->search_volume_error,
            'search_volume_error_at' => $watchedProduct->search_volume_error_at?->toIso8601String(),
            'approval_required' => (bool) $watchedProduct->approval_required,
            'demand_notes' => $watchedProduct->demand_notes,
            'sales_page_url' => $watchedProduct->sales_page_url,
            'affiliate_link' => $watchedProduct->affiliate_link,
            'notes' => $watchedProduct->notes,
            'tracks_count' => $watchedProduct->tracks->count(),
            'tracks' => $watchedProduct->tracks->map(fn (Track $track): array => [
                'id' => $track->id,
                'name' => $track->product_name,
                'status' => $track->account?->status ?? 'active',
                'platform' => $track->platform,
                'strategy' => $track->strategy,
                'page_type' => $track->page_type,
                'google_ads_account_id' => $track->google_ads_account_id,
                'account' => $track->account ? [
                    'id' => $track->account->id,
                    'name' => $track->account->name,
                    'customer_id' => $track->account->customer_id,
                    'status' => $track->account->status,
                ] : null,
                'account_name' => $track->account?->name,
                'account_customer_id' => $track->account?->customer_id,
                'campaign_ids' => $track->campaign_ids ?? [],
                'campaign_count' => count((array) ($track->campaign_ids ?? [])),
                'created_at' => $track->created_at?->toIso8601String(),
                'created_at_formatted' => $track->created_at?->format('M d, Y'),
            ])->all(),
        ];

        return Inertia::render('WatchingProducts/Show', [
            'product' => $productData,
            'tracks' => $productData['tracks'],
            'score_history' => $history,
            'negative_keywords' => $negatives,
            'campaign_guidance' => $guidance,
            'google_ads_accounts' => GoogleAdsAccount::query()
                ->orderBy('name')
                ->get(['id', 'name', 'customer_id', 'is_default', 'status'])
                ->map(fn (GoogleAdsAccount $acc) => [
                    'id' => $acc->id,
                    'name' => $acc->name,
                    'customer_id' => $acc->customer_id,
                    'is_default' => (bool) $acc->is_default,
                    'status' => $acc->status,
                ])
                ->all(),
            'default_account_id' => GoogleAdsAccount::getDefault($request->user())?->id,
            'serpapi_usage' => [
                'used_this_month' => $this->budget->callsUsedThisMonth(),
                'monthly_cap' => (int) config('watching.serpapi_monthly_cap', 230),
                'total_limit' => (int) config('watching.serpapi_total_limit', 250),
            ],
            'options' => [
                'statuses' => array_map(fn (WatchedProductStatus $s) => ['value' => $s->value, 'label' => $s->label()], WatchedProductStatus::cases()),
                'strategies' => array_map(fn (Strategy $st) => ['value' => $st->value, 'label' => $st->label()], Strategy::cases()),
                'product_kinds' => array_map(fn (ProductKind $pk) => ['value' => $pk->value, 'label' => $pk->label()], ProductKind::cases()),
                'brand_bidding' => array_map(fn (BrandBiddingStatus $b) => ['value' => $b->value, 'label' => $b->label()], BrandBiddingStatus::cases()),
                'true_bof' => array_map(fn (TrueBofStatus $t) => ['value' => $t->value, 'label' => $t->label()], TrueBofStatus::cases()),
                'match_types' => array_map(fn (NegativeKeywordMatchType $m) => ['value' => $m->value, 'label' => $m->label()], NegativeKeywordMatchType::cases()),
                'sources' => array_map(fn (NegativeKeywordSource $src) => ['value' => $src->value, 'label' => $src->label()], NegativeKeywordSource::cases()),
                'currencies' => ['USD', 'CAD', 'EUR', 'GBP', 'BRL'],
                'payout_models' => array_map(fn (PayoutModel $m) => ['value' => $m->value, 'label' => $m->label()], PayoutModel::cases()),
                'trends_trends' => array_map(fn (TrendsTrend $t) => ['value' => $t->value, 'label' => $t->label()], TrendsTrend::cases()),
                'compliance_risks' => array_map(fn (ComplianceRisk $c) => ['value' => $c->value, 'label' => $c->label()], ComplianceRisk::cases()),
                'approval_statuses' => array_map(fn (ApprovalStatus $a) => ['value' => $a->value, 'label' => $a->label(), 'short_label' => $a->shortLabel()], ApprovalStatus::cases()),
                'platforms' => $this->getAvailablePlatforms(),
            ],
        ]);
    }

    /**
     * Store a newly created watched product.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);
        $validated = $this->syncPlatforms($validated);

        if (isset($validated['approval_status'])) {
            $validated['approval_required'] = ($validated['approval_status'] === 'yes');
        } elseif (isset($validated['approval_required'])) {
            $validated['approval_status'] = $validated['approval_required'] ? 'yes' : 'unknown';
        }

        if (isset($validated['network_approval_status'])) {
            $validated['network_approval_required'] = ($validated['network_approval_status'] === 'yes');
        } elseif (isset($validated['network_approval_required'])) {
            $validated['network_approval_status'] = $validated['network_approval_required'] ? 'yes' : 'unknown';
        }

        // Compute CAD cache
        if (isset($validated['payout_amount']) && $validated['payout_amount'] !== null) {
            $currency = $validated['payout_currency'] ?? 'USD';
            $validated['payout_cad'] = Money::make($validated['payout_amount'], $currency)->toCad($this->fx);
        }

        $product = WatchedProduct::query()->create($validated);

        return redirect()->route('watching-products.index')
            ->with('success', "Product '{$product->name}' added to watchlist.");
    }

    /**
     * Update the specified watched product.
     */
    public function update(Request $request, WatchedProduct $watchedProduct): RedirectResponse
    {
        $validated = $this->validateProduct($request, $watchedProduct);
        $validated = $this->syncPlatforms($validated, $watchedProduct);

        if (isset($validated['approval_status'])) {
            $validated['approval_required'] = ($validated['approval_status'] === 'yes');
        } elseif (isset($validated['approval_required'])) {
            $validated['approval_status'] = $validated['approval_required'] ? 'yes' : 'unknown';
        }

        if (isset($validated['network_approval_status'])) {
            $validated['network_approval_required'] = ($validated['network_approval_status'] === 'yes');
        } elseif (isset($validated['network_approval_required'])) {
            $validated['network_approval_status'] = $validated['network_approval_required'] ? 'yes' : 'unknown';
        }

        // Update CAD computed cache if payout changed
        if (isset($validated['payout_amount']) && $validated['payout_amount'] !== null) {
            $currency = $validated['payout_currency'] ?? $watchedProduct->payout_currency ?? 'USD';
            $validated['payout_cad'] = Money::make($validated['payout_amount'], $currency)->toCad($this->fx);
        }

        $watchedProduct->update($validated);

        // Rescore product
        $scoreResult = $this->scoreService->score($watchedProduct);
        $watchedProduct->update([
            'score' => $scoreResult['score'],
            'score_breakdown' => $scoreResult['breakdown'],
            'score_flags' => $scoreResult['flags'],
            'scored_at' => now(),
        ]);

        ProductScoreHistory::query()->create([
            'product_id' => $watchedProduct->id,
            'score' => $scoreResult['score'],
            'breakdown' => $scoreResult['breakdown'],
            'flags' => $scoreResult['flags'],
            'inputs_snapshot' => [
                'name' => $watchedProduct->name,
                'payout_amount' => $watchedProduct->payout_amount,
                'payout_currency' => $watchedProduct->payout_currency,
                'page_type' => $watchedProduct->page_type,
                'strategy' => $watchedProduct->strategy?->value,
                'bof_translation' => (bool) $watchedProduct->bof_translation,
                'bof_vpn_serp' => (bool) $watchedProduct->bof_vpn_serp,
                'bof_image_match_pct' => (int) ($watchedProduct->bof_image_match_pct ?? 0),
            ],
            'created_at' => now(),
        ]);

        $this->rankService->recalculateRanks();

        if ($request->header('Referer') && str_contains((string) $request->header('Referer'), '/watching-products/')) {
            return redirect()->back()
                ->with('success', "Product '{$watchedProduct->name}' updated successfully.");
        }

        return redirect()->route('watching-products.index')
            ->with('success', "Product '{$watchedProduct->name}' updated successfully.");
    }

    /**
     * Remove the specified watched product.
     */
    public function destroy(WatchedProduct $watchedProduct): RedirectResponse
    {
        $name = $watchedProduct->name;
        $watchedProduct->delete();

        $this->rankService->recalculateRanks();

        return redirect()->route('watching-products.index')
            ->with('success', "Product '{$name}' removed from watchlist.");
    }

    /**
     * Mark the watched product as reviewed.
     */
    public function review(WatchedProduct $watchedProduct): RedirectResponse
    {
        $watchedProduct->markReviewed();

        return redirect()->back()
            ->with('success', "Product '{$watchedProduct->name}' marked as reviewed.");
    }

    /**
     * Fast status update.
     */
    public function updateStatus(Request $request, WatchedProduct $watchedProduct): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(WatchedProductStatus::class)],
        ]);

        $watchedProduct->update(['status' => $validated['status']]);
        $this->rankService->recalculateRanks();

        return redirect()->back()
            ->with('success', "Status updated to {$watchedProduct->status->label()}.");
    }

    /**
     * Manual "Refresh now" button on detail page.
     */
    public function refreshNow(WatchedProduct $watchedProduct): RedirectResponse
    {
        if (! $this->budget->canSpendManual(2)) {
            return redirect()->back()->with('error', 'SerpApi monthly quota limit (250 calls) has been reached.');
        }

        RefreshSingleWatchedProductJob::dispatch($watchedProduct->id);

        $used = $this->budget->callsUsedThisMonth();

        return redirect()->back()
            ->with('success', "Product data refreshed via SerpApi. Total calls used this month: {$used}/250.");
    }

    /**
     * Manual "Refresh volume now" button on detail page.
     */
    public function refreshVolume(Request $request, WatchedProduct $watchedProduct, KeywordVolumeService $volumeService): RedirectResponse
    {
        try {
            $result = $volumeService->refreshProduct($watchedProduct);
        } catch (GoogleAdsQuotaException $e) {
            report($e);

            return redirect()->back()
                ->with('error', 'Google Ads quota exhausted. Partial results (if any) were saved; please try again later.');
        } catch (\Throwable $e) {
            report($e);

            return redirect()->back()
                ->with('error', 'Failed to refresh search volume: '.$e->getMessage());
        }

        if (! $result['success']) {
            return redirect()->back()
                ->with('error', $result['error'] ?? 'Failed to refresh Google Keyword Planner search volume.');
        }

        $watchedProduct->refresh();
        $totalFormatted = $watchedProduct->search_volume_total !== null
            ? number_format($watchedProduct->search_volume_total)
            : 'N/A';

        return redirect()->back()
            ->with('success', "Search volume refreshed: {$totalFormatted} monthly searches.")
            ->with('toast', 'Search volume refreshed successfully.');
    }

    /**
     * Change product strategy.
     */
    public function updateStrategy(Request $request, WatchedProduct $watchedProduct): RedirectResponse
    {
        $validated = $request->validate([
            'strategy' => ['required', Rule::enum(Strategy::class)],
        ]);

        $newStrategy = Strategy::from($validated['strategy']);

        $watchedProduct->update([
            'strategy' => $newStrategy,
            'strategy_source' => StrategySource::Manual,
        ]);

        // Recompute negative keywords according to strategy rules, respecting manual overrides
        $this->negativeGenerator->syncStrategyRules($watchedProduct, $newStrategy);

        // Rescore
        $scoreResult = $this->scoreService->score($watchedProduct);
        $watchedProduct->update([
            'score' => $scoreResult['score'],
            'score_breakdown' => $scoreResult['breakdown'],
            'score_flags' => $scoreResult['flags'],
            'scored_at' => now(),
        ]);

        $this->rankService->recalculateRanks();

        return redirect()->back()
            ->with('success', "Strategy changed to {$newStrategy->label()}. Negative keyword rules recomputed.");
    }

    /**
     * Add a single negative keyword.
     */
    public function storeNegative(Request $request, WatchedProduct $watchedProduct): RedirectResponse
    {
        $validated = $request->validate([
            'term' => ['required', 'string', 'max:255'],
            'match_type' => ['required', Rule::enum(NegativeKeywordMatchType::class)],
        ]);

        $term = strtolower(trim($validated['term']));

        ProductNegativeKeyword::query()->updateOrCreate(
            [
                'product_id' => $watchedProduct->id,
                'term' => $term,
                'match_type' => $validated['match_type'],
            ],
            [
                'source' => NegativeKeywordSource::Manual,
                'enabled' => true,
                'manually_overridden' => true,
            ]
        );

        return redirect()->back()
            ->with('success', "Negative keyword '{$term}' added.");
    }

    /**
     * Toggle or edit a negative keyword.
     */
    public function updateNegative(Request $request, WatchedProduct $watchedProduct, ProductNegativeKeyword $negativeKeyword): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'match_type' => ['sometimes', Rule::enum(NegativeKeywordMatchType::class)],
        ]);

        $updates = $validated;
        $updates['manually_overridden'] = true;

        $negativeKeyword->update($updates);

        return redirect()->back()
            ->with('success', "Negative keyword '{$negativeKeyword->term}' updated.");
    }

    /**
     * Delete a negative keyword.
     */
    public function destroyNegative(WatchedProduct $watchedProduct, ProductNegativeKeyword $negativeKeyword): RedirectResponse
    {
        $term = $negativeKeyword->term;
        $negativeKeyword->delete();

        return redirect()->back()
            ->with('success', "Negative keyword '{$term}' removed.");
    }

    /**
     * Export negative keywords in Google Ads CSV format.
     */
    public function exportNegatives(WatchedProduct $watchedProduct): HttpResponse
    {
        $csvContent = $this->negativeGenerator->exportCsv($watchedProduct);
        $filename = 'negatives_'.str()->slug($watchedProduct->name).'_'.now()->format('Ymd_His').'.csv';

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Generate full campaign negative keywords (SERP competitor, homonym, and intruder extraction).
     */
    public function generateCampaignNegatives(Request $request, WatchedProduct $watchedProduct): RedirectResponse|JsonResponse
    {
        $added = $this->negativeGenerator->generateCampaign($watchedProduct);

        $message = $added > 0
            ? "Generated {$added} new campaign negative keyword(s) from SERP analysis."
            : 'Campaign negative generation complete. No new competitor terms found.';

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'success' => true,
                'added' => $added,
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Validate incoming product attributes.
     *
     * @return array<string, mixed>
     */
    protected function validateProduct(Request $request, ?WatchedProduct $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(WatchedProductStatus::class)],
            'platforms' => ['nullable'],
            'platform' => ['nullable', 'string', Rule::in((array) config('watching.platforms', []))],
            'payout_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'payout_currency' => ['nullable', 'string', 'size:3'],
            'payout_model' => ['nullable', Rule::enum(PayoutModel::class)],
            'payout_cad' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'payout_notes' => ['nullable', 'string', 'max:2000'],
            'gravity' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'listing_url' => ['nullable', 'string', 'max:2048'],
            'listing_status' => ['nullable', Rule::enum(ListingStatus::class)],
            'cancel_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'page_type' => ['nullable', 'string', 'max:64'],
            'funnel_flags' => ['nullable', 'array'],
            'product_kind' => ['nullable', Rule::enum(ProductKind::class)],
            'esteira' => ['boolean'],
            'restricted_category' => ['boolean'],
            'strategy' => ['nullable', Rule::enum(Strategy::class)],
            'strategy_source' => ['nullable', Rule::enum(StrategySource::class)],
            'brand_bidding' => ['required', Rule::enum(BrandBiddingStatus::class)],
            'true_bof' => ['required', Rule::enum(TrueBofStatus::class)],
            'bof_translation' => ['boolean'],
            'bof_vpn_serp' => ['boolean'],
            'bof_image_match_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'bof_qualifier' => ['nullable', 'string', 'max:255'],
            'bof_homonym' => ['boolean'],
            'geos' => ['nullable'],
            'allowed_geos' => ['nullable'],
            'compliance_risk' => ['nullable', Rule::enum(ComplianceRisk::class)],
            'compliance_notes' => ['nullable', 'string', 'max:5000'],
            'network_approval_required' => ['nullable', 'boolean'],
            'approval_required' => ['nullable', 'boolean'],
            'approval_status' => ['nullable', Rule::enum(ApprovalStatus::class)],
            'network_approval_status' => ['nullable', Rule::enum(ApprovalStatus::class)],
            'strategy_phase2' => ['nullable', Rule::enum(Strategy::class)],
            'strategy_switch_trigger' => ['nullable', 'string', 'max:5000'],
            'recheck_at' => ['nullable', 'date'],
            'promotion_criteria' => ['nullable', 'string', 'max:5000'],
            'payouts_by_platform' => ['nullable', 'array'],
            'search_volume_keyword' => ['nullable', 'string', 'max:191'],
            'negatives' => ['nullable', 'string', 'max:5000'],
            'demand_notes' => ['nullable', 'string', 'max:5000'],
            'trends_7d' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'trends_30d' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'trends_90d' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'trends_trend' => ['nullable', Rule::enum(TrendsTrend::class)],
            'flowspy_traffic_trend' => ['nullable', Rule::enum(FlowspyTrafficTrend::class)],
            'kp_volume' => ['nullable', 'integer', 'min:0'],
            'kp_yoy' => ['nullable', 'numeric'],
            'kp_3m' => ['nullable', 'numeric'],
            'kp_cpc_amount' => ['nullable', 'numeric', 'min:0'],
            'kp_cpc_currency' => ['nullable', 'string', 'size:3'],
            'brand_cpc_amount' => ['nullable', 'numeric', 'min:0'],
            'brand_cpc_currency' => ['nullable', 'string', 'size:3'],
            'est_cvr' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'advertiser_count' => ['nullable', 'integer', 'min:0'],
            'vendor_bids_brand' => ['boolean'],
            'spend_amount' => ['nullable', 'numeric', 'min:0'],
            'spend_currency' => ['nullable', 'string', 'size:3'],
            'sales' => ['nullable', 'integer', 'min:0'],
            'checkouts' => ['nullable', 'integer', 'min:0'],
            'commission_earned' => ['nullable', 'numeric', 'min:0'],
            'sales_page_url' => ['nullable', 'string', 'max:2048'],
            'affiliate_link' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'last_reviewed_at' => ['nullable', 'date'],
        ]);
    }

    /**
     * Get centralized available platforms list merged with distinct existing values.
     *
     * @return array<int, string>
     */
    protected function getAvailablePlatforms(): array
    {
        $configPlatforms = (array) config('watching.platforms', [
            'Affplus',
            'Affplus (ClickHunts)',
            'Affplus (WW)',
            'BuyGoods',
            'ClickBank',
            'Digistore24',
            'MaxWeb',
            'SmartAdv',
            'Other',
            'Unknown',
        ]);

        $existingPlatforms = WatchedProduct::query()
            ->whereNotNull('platforms')
            ->pluck('platforms')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        $extra = array_values(array_diff($existingPlatforms, $configPlatforms));

        return array_values(array_merge($configPlatforms, $extra));
    }

    /**
     * Synchronize primary platform and platforms JSON array on save.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function syncPlatforms(array $validated, ?WatchedProduct $existing = null): array
    {
        $platform = array_key_exists('platform', $validated)
            ? ($validated['platform'] !== null && trim((string) $validated['platform']) !== '' ? trim((string) $validated['platform']) : null)
            : ($existing?->platform);

        // Determine current platforms array
        $platforms = [];
        if (array_key_exists('platforms', $validated) && $validated['platforms'] !== null) {
            $raw = $validated['platforms'];
            if (is_string($raw)) {
                $platforms = array_values(array_filter(array_map('trim', explode(',', $raw))));
            } elseif (is_array($raw)) {
                $platforms = array_values(array_filter(array_map(fn ($item) => trim((string) $item), $raw)));
            }
        } elseif ($existing) {
            $platforms = $existing->platforms ?? [];
        }

        if ($platform !== null && $platform !== '') {
            if (empty($platforms)) {
                $platforms = [$platform];
            } elseif (! in_array($platform, $platforms, true)) {
                $platforms[] = $platform;
            }
            $validated['platform'] = $platform;
            $validated['platforms'] = array_values(array_unique($platforms));
        }

        return $validated;
    }
}
