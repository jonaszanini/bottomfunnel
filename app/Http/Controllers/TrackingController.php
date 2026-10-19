<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyIpBlockJob;
use App\Models\IpBlock;
use App\Models\Page;
use App\Models\Track;
use App\Models\Visit;
use App\Services\Tracking\FraudScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrackingController extends Controller
{
    public function __invoke(Request $request, FraudScorer $fraud): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['key' => 'Send a JSON object.']);
        }
        $allowed = ['key', 'session_id', 'page_id', 'time_on_page', 'gclid', 'gbraid', 'wbraid', 'referrer', 'engagement'];
        $extra = array_diff(array_keys($payload), $allowed);
        if ($extra !== []) {
            throw ValidationException::withMessages(['key' => 'The tracker payload has unknown fields.']);
        }
        $engagement = $payload['engagement'] ?? [];
        if (! is_array($engagement)) {
            throw ValidationException::withMessages(['engagement' => 'Engagement must be an object.']);
        }
        $engagementExtra = array_diff(array_keys($engagement), ['scroll_depth', 'cta_clicks', 'button_clicks', 'form_interactions', 'exit_intent']);
        if ($engagementExtra !== []) {
            throw ValidationException::withMessages(['engagement' => 'Engagement has unknown fields.']);
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'session_id' => ['nullable', 'uuid'],
            'page_id' => ['nullable', 'integer'],
            'time_on_page' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'gclid' => ['nullable', 'string', 'max:255'],
            'gbraid' => ['nullable', 'string', 'max:255'],
            'wbraid' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            'engagement.scroll_depth' => ['nullable', 'integer', 'min:0', 'max:100'],
            'engagement.cta_clicks' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'engagement.button_clicks' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'engagement.form_interactions' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'engagement.exit_intent' => ['nullable', 'boolean'],
        ]);

        $track = Track::query()->where('public_script_key', $data['key'])->first();
        if (! $track) {
            return response()->json(['message' => 'Unknown Track.'], 404);
        }

        $page = null;
        if (! empty($data['page_id'])) {
            $page = Page::query()->where('track_id', $track->id)->find($data['page_id']);
        }

        $sessionId = $data['session_id'] ?? null;
        $visit = $sessionId
            ? Visit::query()->where('session_id', $sessionId)->where('track_id', $track->id)->first()
            : null;

        if (! $visit) {
            $visit = new Visit([
                'session_id' => (string) Str::uuid(),
                'track_id' => $track->id,
                'landed_at' => now(),
            ]);
        }

        $visit->fill([
            'page_id' => $page?->id,
            'page_type' => $page?->page_type ?: $track->page_type,
            'gclid' => $data['gclid'] ?? $visit->gclid,
            'gbraid' => $data['gbraid'] ?? $visit->gbraid,
            'wbraid' => $data['wbraid'] ?? $visit->wbraid,
            'ip_address' => (string) $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'referrer' => $data['referrer'] ?? $visit->referrer,
            'time_on_page_seconds' => max((int) $visit->time_on_page_seconds, (int) ($data['time_on_page'] ?? 0)),
            'engagement' => [
                'scroll_depth' => (int) ($engagement['scroll_depth'] ?? 0),
                'cta_clicks' => (int) ($engagement['cta_clicks'] ?? 0),
                'button_clicks' => (int) ($engagement['button_clicks'] ?? 0),
                'form_interactions' => (int) ($engagement['form_interactions'] ?? 0),
                'exit_intent' => (bool) ($engagement['exit_intent'] ?? false),
            ],
            'last_ping_at' => now(),
        ]);
        $visit->save();

        $score = $fraud->score($visit);
        $visit->update([
            'fraud_score' => $score['score'],
            'fraud_reason' => $score['reason'],
            'flagged' => $score['flagged'],
            'auto_blocked' => $visit->auto_blocked || $score['block'],
        ]);

        if ($score['block']) {
            $already = IpBlock::query()
                ->where('track_id', $track->id)
                ->where('ip_address', $visit->ip_address)
                ->whereIn('status', ['applied', 'whitelisted'])
                ->exists();
            if (! $already) {
                ApplyIpBlockJob::dispatch($track->id, $visit->ip_address, (string) $score['reason']);
            }
        }

        return response()->json([
            'session_id' => $visit->session_id,
            'affiliate_url' => $track->affiliateUrlFor($visit->session_id),
            'click_param' => $track->clickParam(),
            'page_type' => $visit->page_type,
        ]);
    }
}
