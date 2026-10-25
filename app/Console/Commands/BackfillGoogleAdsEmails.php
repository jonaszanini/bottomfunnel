<?php

namespace App\Console\Commands;

use App\Models\GoogleAdsAccount;
use App\Services\Google\GoogleAdsClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-time command. Safe to delete after all existing accounts have emails.
 */
class BackfillGoogleAdsEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'google-ads:backfill-emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing Google account emails for linked Google Ads accounts';

    /**
     * Execute the console command.
     */
    public function handle(GoogleAdsClient $googleAds): int
    {
        $accounts = GoogleAdsAccount::query()->whereNull('email')->get();

        if ($accounts->isEmpty()) {
            $this->info('No Google Ads accounts with missing emails found.');
            $this->info('Summary: updated 0, failed 0');

            return self::SUCCESS;
        }

        $this->info("Found {$accounts->count()} Google Ads account(s) missing email.");

        $updated = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                if (empty($account->refresh_token)) {
                    $this->error("Account {$account->name} ({$account->customer_id}): failed - no refresh token available.");
                    $failed++;

                    continue;
                }

                $accessToken = $googleAds->accessToken($account);
                $email = $googleAds->fetchUserEmail($accessToken);

                if (! empty($email)) {
                    $account->update(['email' => $email]);
                    $this->info("Account {$account->name} ({$account->customer_id}): successfully updated email to {$email}.");
                    $updated++;
                } else {
                    $this->error("Account {$account->name} ({$account->customer_id}): failed - userinfo did not return an email.");
                    $failed++;
                }
            } catch (Throwable $e) {
                $this->error("Account {$account->name} ({$account->customer_id}): failed - {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Summary: updated {$updated}, failed {$failed}");

        return self::SUCCESS;
    }
}
