<?php

namespace App\Services\Seo;

use App\Models\WatchedProduct;

interface SeoClientContract
{
    /**
     * Build a deterministic cache key for a query.
     */
    public function buildCacheKey(
        string $type,
        string $query,
        ?string $gl = null,
        ?string $hl = null,
        ?string $device = null
    ): string;

    /**
     * Fetch Trends and Search data for a product.
     *
     * @return array{trends: array<string, mixed>, serp: array<string, mixed>, bof: array{bof_vpn_serp: bool, bof_image_match_pct: int, bof_translation: bool}, success: bool, error: ?string}
     */
    public function fetchProductData(WatchedProduct $product, bool $forceFresh = false, bool $isManual = false): array;

    /**
     * Query Google Trends time series.
     *
     * @return array<string, mixed>
     */
    public function fetchGoogleTrends(
        string $query,
        ?string $apiKey = null,
        ?string $gl = null,
        ?string $hl = null,
        ?string $device = null,
        bool $forceFresh = false,
        bool $isManual = false
    ): array;

    /**
     * Query Google Search engine for SERP data.
     *
     * @return array<string, mixed>
     */
    public function fetchGoogleSerp(
        string $query,
        ?string $apiKey = null,
        ?string $gl = null,
        ?string $hl = null,
        ?string $device = null,
        bool $forceFresh = false,
        bool $isManual = false
    ): array;
}
