<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\Reporting\DashboardReport;
use App\Support\Options;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardReport $report): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'track_id' => ['nullable', 'integer'],
            'strategy' => ['nullable', 'string'],
            'page_type' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
            'conversion_status' => ['nullable', 'string'],
        ]);

        return Inertia::render('Dashboard', [
            'filters' => [
                'from' => $filters['from'] ?? now()->subDays(29)->toDateString(),
                'to' => $filters['to'] ?? now()->toDateString(),
                'track_id' => $filters['track_id'] ?? '',
                'strategy' => $filters['strategy'] ?? '',
                'page_type' => $filters['page_type'] ?? '',
                'platform' => $filters['platform'] ?? '',
                'conversion_status' => $filters['conversion_status'] ?? '',
            ],
            'report' => $report->build($filters),
            'trackOptions' => Track::query()->orderBy('product_name')->get(['id', 'product_name']),
            ...Options::forForms(),
            'conversionStatuses' => [
                'received' => 'Received',
                'matched' => 'Matched',
                'uploaded' => 'Uploaded',
                'failed' => 'Failed',
                'unmatched' => 'Unmatched',
                'refunded' => 'Refunded',
                'chargeback' => 'Chargeback',
            ],
        ]);
    }
}
