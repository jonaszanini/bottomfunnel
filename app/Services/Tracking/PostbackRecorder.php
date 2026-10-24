<?php

namespace App\Services\Tracking;

use App\Jobs\UploadConversionJob;
use App\Models\Conversion;
use App\Models\Track;
use App\Models\Visit;
use App\Services\Fx\BankOfCanadaFx;
use RuntimeException;

class PostbackRecorder
{
    public function __construct(
        private readonly ConversionMatcher $matcher,
        private readonly BankOfCanadaFx $fx,
    ) {}

    public function record(Track $track, string $rawPayload, ParsedPostback $parsed): Conversion
    {
        $matched = $this->matcher->match($track, $parsed);
        $existing = Conversion::query()
            ->where('track_id', $track->id)
            ->where('order_id', $parsed->orderId)
            ->first();

        $fx = $this->money($parsed);
        $status = $fx['error'] ? 'failed' : $this->status($parsed, $matched !== null, $existing);
        $conversion = $existing ?? new Conversion([
            'track_id' => $track->id,
            'order_id' => $parsed->orderId,
        ]);

        $wasUploaded = $conversion->uploaded_at !== null;
        $conversion->fill([
            'visit_id' => $matched['visit']->id ?? $conversion->visit_id,
            'gclid' => $parsed->gclid ?: ($matched['visit']->gclid ?? $conversion->gclid),
            'amount' => $parsed->eventType === 'sale' ? $fx['amount'] : 0,
            'currency' => $fx['currency'],
            'fx_rate' => $fx['rate'],
            'commission_value_cad' => $parsed->eventType === 'sale' ? $fx['cad'] : 0,
            'status' => $status,
            'match_source' => $matched['source'] ?? $conversion->match_source,
            'raw_payload' => $rawPayload,
            'last_error' => $fx['error'] ?? (str_starts_with($parsed->orderId, 'missing-') ? 'Order id was not in the payload.' : null),
        ]);
        $conversion->save();

        if ($matched) {
            $matched['visit']->update(['converted' => $parsed->eventType === 'sale']);
        }

        $shouldUpload = $matched && $fx['error'] === null && ! str_starts_with($parsed->orderId, 'missing-');
        if ($shouldUpload && $parsed->eventType === 'sale') {
            UploadConversionJob::dispatch($conversion->id);
        }
        if ($shouldUpload && $parsed->eventType !== 'sale' && ($wasUploaded || $existing?->status === 'uploaded')) {
            UploadConversionJob::dispatch($conversion->id);
        }

        return $conversion;
    }

    public function attach(Conversion $conversion, Visit $visit): Conversion
    {
        if ($visit->track_id !== $conversion->track_id) {
            throw new RuntimeException('That session belongs to another Track.');
        }

        $conversion->fill([
            'visit_id' => $visit->id,
            'gclid' => $conversion->gclid ?: $visit->gclid,
            'match_source' => 'manual',
            'status' => in_array($conversion->status, ['refunded', 'chargeback'], true) ? $conversion->status : 'matched',
        ])->save();
        $visit->update(['converted' => ! in_array($conversion->status, ['refunded', 'chargeback'], true)]);

        if (! str_starts_with($conversion->order_id, 'missing-') && (float) $conversion->fx_rate > 0) {
            UploadConversionJob::dispatch($conversion->id);
        }

        return $conversion;
    }

    /**
     * @return array{amount: float, currency: string, rate: string, cad: string, error: ?string}
     */
    private function money(ParsedPostback $parsed): array
    {
        try {
            $converted = $this->fx->toCad($parsed->amount, $parsed->currency);

            return [
                'amount' => $parsed->amount,
                'currency' => $converted['currency'],
                'rate' => $converted['rate'],
                'cad' => $converted['cad'],
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'amount' => $parsed->amount,
                'currency' => $parsed->currency,
                'rate' => '0',
                'cad' => '0.00',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{visit: Visit, source: string}|null  $matched
     */
    private function status(ParsedPostback $parsed, bool $matched, ?Conversion $existing): string
    {
        if ($parsed->eventType === 'refund') {
            return 'refunded';
        }
        if ($parsed->eventType === 'chargeback') {
            return 'chargeback';
        }
        if (! $matched && ! $existing?->visit_id) {
            return 'unmatched';
        }

        return 'matched';
    }
}
