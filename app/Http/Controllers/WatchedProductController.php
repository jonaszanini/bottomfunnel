<?php

namespace App\Http\Controllers;

use App\Enums\BrandBiddingStatus;
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
use App\Models\ProductNegativeKeyword;
use App\Models\ProductScoreHistory;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\CampaignGuidance;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\Money;
use App\Services\WatchingProducts\NegativeKeywordGenerator;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\RankService;
use App\Services\WatchingProducts\SerpApiClient;
use App\Services\WatchingProducts\StrategyRecommender;
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
        protected RankService $rankService = new RankService
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
        ];

        $query = WatchedProduct::query()->filter($filters);

        $products = $query->get()->map(function (WatchedProduct $product): array {
            $recommendation = $this->strategyRecommender->recommend($product);
            $payoutMoney = Money::make($product->payout_amount ?? $product->payout_cad, $product->payout_currency ?? 'USD');

            return [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $product->status->value,
                'status_label' => $product->status->label(),
                'rank' => $product->rank,
                'score' => $product->score,
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
                'trends_trend' => $product->trends_trend?->value,
                'trends_trend_label' => $product->trends_trend?->label(),
                'trends_30d' => $product->trends_30d !== null ? (float) $product->trends_30d : null,
                'brand_bidding' => $product->brand_bidding->value,
                'brand_bidding_label' => $product->brand_bidding->label(),
                'true_bof' => $product->true_bof->value,
                'true_bof_label' => $product->true_bof->label(),
                'geos' => $product->geos ?? [],
                'platforms' => $product->platforms ?? [],
                'platform' => $product->platform,
                'approval_required' => (bool) $product->approval_required,
                'demand_notes' => $product->demand_notes,
                'sales_page_url' => $product->sales_page_url,
                'affiliate_link' => $product->affiliate_link,
                'notes' => $product->notes,
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
            'serpapi_used' => $this->budget->callsUsedThisMonth(),
            'serpapi_cap' => (int) config('watching.serpapi_monthly_cap', 230),
            'serpapi_limit' => (int) config('watching.serpapi_total_limit', 250),
        ];

        // Gather all distinct platforms
        $existingPlatforms = WatchedProduct::query()
            ->whereNotNull('platforms')
            ->pluck('platforms')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        $defaultPlatforms = ['SmartAdv', 'ClickBank', 'BuyGoods', 'MaxWeb', 'Digistore24'];
        $availablePlatforms = array_values(array_unique(array_merge($defaultPlatforms, $existingPlatforms)));
        sort($availablePlatforms);

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
            ],
        ]);
    }

    /**
     * Display detailed product page with breakdown, guidance, score history, and negative keywords.
     */
    public function show(WatchedProduct $watchedProduct): Response
    {
        $recommendation = $this->strategyRecommender->recommend($watchedProduct);
        $guidance = $this->guidance->forProduct($watchedProduct);
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
            'trends_trend' => $watchedProduct->trends_trend?->value,
            'trends_trend_label' => $watchedProduct->trends_trend?->label(),
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
            'approval_required' => (bool) $watchedProduct->approval_required,
            'demand_notes' => $watchedProduct->demand_notes,
            'sales_page_url' => $watchedProduct->sales_page_url,
            'affiliate_link' => $watchedProduct->affiliate_link,
            'notes' => $watchedProduct->notes,
        ];

        return Inertia::render('WatchingProducts/Show', [
            'product' => $productData,
            'score_history' => $history,
            'negative_keywords' => $negatives,
            'campaign_guidance' => $guidance,
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
            ],
        ]);
    }

    /**
     * Store a newly created watched product.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);

        // Compute CAD cache
        if (isset($validated['payout_amount']) && $validated['payout_amount'] !== null) {
            $currency = $validated['payout_currency'] ?? 'USD';
            $validated['payout_cad'] = $this->fx->toCad((float) $validated['payout_amount'], $currency);
        }

        $product = WatchedProduct::query()->create($validated);

        return redirect()->route('watching-products.index')
            ->with('success', "Product '{$product->name}' added to watchlist with rank #{$product->rank}.");
    }

    /**
     * Update the specified watched product.
     */
    public function update(Request $request, WatchedProduct $watchedProduct): RedirectResponse
    {
        $validated = $this->validateProduct($request, $watchedProduct);

        // Update CAD computed cache if payout changed
        if (isset($validated['payout_amount']) && $validated['payout_amount'] !== null) {
            $currency = $validated['payout_currency'] ?? $watchedProduct->payout_currency ?? 'USD';
            $validated['payout_cad'] = $this->fx->toCad((float) $validated['payout_amount'], $currency);
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
    public function refreshNow(WatchedProduct $watchedProduct, SerpApiClient $serpClient): RedirectResponse
    {
        if (! $this->budget->canSpendManual(2)) {
            return redirect()->back()->with('error', 'SerpApi monthly quota limit (250 calls) has been reached.');
        }

        $data = $serpClient->fetchProductData($watchedProduct);

        if ($data['success']) {
            if (! empty($data['trends'])) {
                $watchedProduct->trends_7d = $data['trends']['trends_7d'] ?? $watchedProduct->trends_7d;
                $watchedProduct->trends_30d = $data['trends']['trends_30d'] ?? $watchedProduct->trends_30d;
                $watchedProduct->trends_90d = $data['trends']['trends_90d'] ?? $watchedProduct->trends_90d;
                if (! empty($data['trends']['trends_trend'])) {
                    $watchedProduct->trends_trend = $data['trends']['trends_trend'];
                }
            }

            if (! empty($data['serp'])) {
                $watchedProduct->advertiser_count = $data['serp']['advertiser_count'] ?? $watchedProduct->advertiser_count;
                $this->negativeGenerator->syncSerpData($watchedProduct, $data['serp']);
            }
        }

        $watchedProduct->last_refreshed_at = now();
        $watchedProduct->save();

        // Rescore and record score history
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
            ],
            'created_at' => now(),
        ]);

        $this->rankService->recalculateRanks();

        $used = $this->budget->callsUsedThisMonth();

        return redirect()->back()
            ->with('success', "Product data refreshed via SerpApi. Total calls used this month: {$used}/250.");
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
            'platform' => ['nullable', 'string', 'max:64'],
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
            'negatives' => ['nullable', 'string', 'max:5000'],
            'approval_required' => ['boolean'],
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
}
