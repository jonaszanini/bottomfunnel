<?php

namespace App\Jobs;

use App\Models\Conversion;
use App\Services\Google\DataManagerClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UploadConversionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600, 900];

    public function __construct(public int $conversionId) {}

    public function handle(DataManagerClient $dataManager): void
    {
        $conversion = Conversion::query()->find($this->conversionId);
        if (! $conversion || $conversion->status === 'unmatched') {
            return;
        }
        if (in_array($conversion->status, ['refunded', 'chargeback'], true) && $conversion->uploaded_at === null) {
            return;
        }

        $conversion->increment('upload_attempts');

        try {
            $dataManager->ingest($conversion->fresh());
            $status = in_array($conversion->status, ['refunded', 'chargeback'], true)
                ? $conversion->status
                : 'uploaded';
            $conversion->update([
                'status' => $status,
                'last_error' => null,
                'uploaded_at' => now(),
            ]);
        } catch (GoogleRequestException $exception) {
            $conversion->update([
                'status' => in_array($conversion->status, ['refunded', 'chargeback'], true) ? $conversion->status : 'failed',
                'last_error' => $exception->getMessage(),
            ]);
            if ($exception->transient && $this->attempts() < $this->tries) {
                throw $exception;
            }
            $this->fail($exception);
        }
    }
}
