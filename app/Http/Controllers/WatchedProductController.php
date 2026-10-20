<?php

namespace App\Http\Controllers;

use App\Enums\BrandBiddingStatus;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use App\Models\WatchedProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WatchedProductController extends Controller
{
    /**
     * Display a listing of watched products with filtering and search.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status', 'all'),
            'brand_bidding' => $request->input('brand_bidding', 'all'),
            'true_bof' => $request->input('true_bof', 'all'),
            'platform' => $request->input('platform', 'all'),
        ];

        $query = WatchedProduct::query()->filter($filters);

        $products = $query->latest('updated_at')->get()->map(function (WatchedProduct $product): array {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $product->status->value,
                'status_label' => $product->status->label(),
                'platforms' => $product->platforms ?? [],
                'payout_cad' => $product->payout_cad !== null ? (float) $product->payout_cad : null,
                'payout_notes' => $product->payout_notes,
                'page_type' => $product->page_type,
                'brand_bidding' => $product->brand_bidding->value,
                'brand_bidding_label' => $product->brand_bidding->label(),
                'true_bof' => $product->true_bof->value,
                'true_bof_label' => $product->true_bof->label(),
                'geos' => $product->geos ?? [],
                'negatives' => $product->negatives,
                'approval_required' => (bool) $product->approval_required,
                'demand_notes' => $product->demand_notes,
                'sales_page_url' => $product->sales_page_url,
                'affiliate_link' => $product->affiliate_link,
                'notes' => $product->notes,
                'last_reviewed_at' => $product->last_reviewed_at?->toIso8601String(),
                'last_reviewed_formatted' => $product->last_reviewed_at?->format('M d, Y'),
                'created_at' => $product->created_at->toIso8601String(),
                'updated_at' => $product->updated_at->toIso8601String(),
            ];
        });

        // Summary metrics
        $allProducts = WatchedProduct::query()->get(['id', 'status', 'last_reviewed_at']);
        $metrics = [
            'total' => $allProducts->count(),
            'test' => $allProducts->where('status', WatchedProductStatus::Test)->count(),
            'watch' => $allProducts->where('status', WatchedProductStatus::Watch)->count(),
            'rejected' => $allProducts->where('status', WatchedProductStatus::Rejected)->count(),
            'due_review' => $allProducts->filter(function (WatchedProduct $p): bool {
                return $p->last_reviewed_at === null || $p->last_reviewed_at->lt(now()->subDays(14));
            })->count(),
        ];

        // Gather all distinct platforms in use for filter dropdown
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
                'brand_bidding' => array_map(fn (BrandBiddingStatus $b) => [
                    'value' => $b->value,
                    'label' => $b->label(),
                ], BrandBiddingStatus::cases()),
                'true_bof' => array_map(fn (TrueBofStatus $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                ], TrueBofStatus::cases()),
                'platforms' => $availablePlatforms,
                'page_types' => ['DTC', 'TSL', 'Advertorial', 'VSL', 'Quiz', 'Review', 'Presell', 'Other'],
            ],
        ]);
    }

    /**
     * Store a newly created watched product.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProduct($request);

        $product = WatchedProduct::query()->create($validated);

        return redirect()->route('watching-products.index')
            ->with('success', "Product '{$product->name}' added to watchlist.");
    }

    /**
     * Update the specified watched product.
     */
    public function update(Request $request, WatchedProduct $watchedProduct): RedirectResponse
    {
        $validated = $this->validateProduct($request);

        $watchedProduct->update($validated);

        return redirect()->route('watching-products.index')
            ->with('success', "Product '{$watchedProduct->name}' updated successfully.");
    }

    /**
     * Remove the specified watched product from the database.
     */
    public function destroy(WatchedProduct $watchedProduct): RedirectResponse
    {
        $name = $watchedProduct->name;
        $watchedProduct->delete();

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

        $watchedProduct->update([
            'status' => $validated['status'],
        ]);

        return redirect()->back()
            ->with('success', "Status updated to {$watchedProduct->status->label()}.");
    }

    /**
     * Validate incoming product attributes.
     *
     * @return array<string, mixed>
     */
    protected function validateProduct(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(WatchedProductStatus::class)],
            'platforms' => ['nullable'],
            'payout_cad' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'payout_notes' => ['nullable', 'string', 'max:2000'],
            'page_type' => ['nullable', 'string', 'max:64'],
            'brand_bidding' => ['required', Rule::enum(BrandBiddingStatus::class)],
            'true_bof' => ['required', Rule::enum(TrueBofStatus::class)],
            'geos' => ['nullable'],
            'negatives' => ['nullable', 'string', 'max:5000'],
            'approval_required' => ['boolean'],
            'demand_notes' => ['nullable', 'string', 'max:5000'],
            'sales_page_url' => ['nullable', 'string', 'max:2048'],
            'affiliate_link' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'last_reviewed_at' => ['nullable', 'date'],
        ]);
    }
}
