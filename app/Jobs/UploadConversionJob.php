<?php

namespace App\Jobs;

use App\Models\Conversion;
use App\Services\Google\DataManagerClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class UploadConversionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600, 900];

    /**
     * @param  int|list<int>  $conversionId  Single conversion ID or list of IDs
     */
    public function __construct(public int|array $conversionId) {}

    public function handle(DataManagerClient $dataManager): void
    {
        $ids = is_array($this->conversionId) ? $this->conversionId : [$this->conversionId];

        foreach ($ids as $id) {
            $conversion = Conversion::query()->find($id);
            if (! $conversion || $conversion->status === 'unmatched') {
                continue;
            }
            if (in_array($conversion->status, ['refunded', 'chargeback'], true) && $conversion->uploaded_at === null) {
                continue;
            }

            $conversion->increment('upload_attempts');

            try {
                $requestId = $dataManager->ingest($conversion->fresh());
                $status = in_array($conversion->status, ['refunded', 'chargeback'], true)
                    ? $conversion->status
                    : 'uploaded';
                $conversion->update([
                    'request_id' => $requestId,
                    'status' => $status,
                    'last_error' => null,
                    'uploaded_at' => now(),
                ]);
            } catch (GoogleRequestException $exception) {
                $conversion->update([
                    'status' => in_array($conversion->status, ['refunded', 'chargeback'], true) ? $conversion->status : 'failed',
                    'last_error' => $exception->getMessage(),
                ]);

                // For single job retry if transient
                if (! is_array($this->conversionId) && $exception->transient && $this->attempts() < $this->tries) {
                    throw $exception;
                }

                if (! is_array($this->conversionId)) {
                    $this->fail($exception);
                }
            } catch (Throwable $exception) {
                $conversion->update([
                    'status' => in_array($conversion->status, ['refunded', 'chargeback'], true) ? $conversion->status : 'failed',
                    'last_error' => $exception->getMessage(),
                ]);

                if (! is_array($this->conversionId)) {
                    $this->fail($exception);
                }
            }
        }
    }
}
