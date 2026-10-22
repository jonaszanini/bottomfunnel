<?php

namespace App\Console\Commands;

use App\Models\GoogleAdsAccount;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Console\Command;
use Throwable;

class GoogleAdsCampaignCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'google-ads:campaign
                            {action : create|ad-group|keywords|negative-keywords|rsa|status|budget|list-ad-groups|list-keywords|list-ads}
                            {--account= : GoogleAdsAccount ID}
                            {--customer= : Google Ads 10-digit customer ID}
                            {--campaign= : Campaign ID}
                            {--ad-group= : Ad Group ID}
                            {--name= : Name for campaign or ad group}
                            {--budget=10000000 : Budget amount in micros (default 10 units)}
                            {--bid=1500000 : Max CPC bid in micros (default 1.5 units)}
                            {--strategy=MANUAL_CPC : Bidding strategy (MANUAL_CPC, MAXIMIZE_CLICKS, etc.)}
                            {--keywords= : Comma-separated list of keywords}
                            {--match=BROAD : Keyword match type (BROAD, PHRASE, EXACT)}
                            {--headlines= : Pipe- or comma-separated list of 3-15 headlines (max 30 chars)}
                            {--descriptions= : Pipe- or comma-separated list of 2-4 descriptions (max 90 chars)}
                            {--url= : Final URL for Responsive Search Ad}
                            {--path1= : Path 1 for Responsive Search Ad (max 15 chars)}
                            {--path2= : Path 2 for Responsive Search Ad (max 15 chars)}
                            {--status= : Campaign status (ENABLED, PAUSED, REMOVED)}
                            {--confirm : Required confirmation flag when setting status to REMOVED}';

    /**
     * The console command description.
     */
    protected $description = 'Manage Google Ads Search campaigns, Ad Groups, Keywords, and Responsive Search Ads';

    public function handle(GoogleAdsClient $googleAds): int
    {
        $account = $this->resolveAccount();
        if (! $account) {
            $this->error('No Google Ads account found. Link an account or pass --account=ID / --customer=ID.');

            return self::FAILURE;
        }

        $action = strtolower(trim((string) $this->argument('action')));

        try {
            return match ($action) {
                'create' => $this->handleCreateCampaign($googleAds, $account),
                'ad-group' => $this->handleCreateAdGroup($googleAds, $account),
                'keywords' => $this->handleAddKeywords($googleAds, $account),
                'negative-keywords' => $this->handleAddNegativeKeywords($googleAds, $account),
                'rsa' => $this->handleCreateRsa($googleAds, $account),
                'status' => $this->handleUpdateStatus($googleAds, $account),
                'budget' => $this->handleUpdateBudget($googleAds, $account),
                'list-ad-groups' => $this->handleListAdGroups($googleAds, $account),
                'list-keywords' => $this->handleListKeywords($googleAds, $account),
                'list-ads' => $this->handleListAds($googleAds, $account),
                default => $this->handleUnknownAction($action),
            };
        } catch (GoogleRequestException $e) {
            $this->error("Google Ads API error: {$e->getMessage()}");

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function handleCreateCampaign(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $name = (string) ($this->option('name') ?: 'Search Campaign '.now()->format('Y-m-d H:i'));
        $budget = (int) $this->option('budget');
        $strategy = (string) $this->option('strategy');

        $this->info("Creating Search campaign '{$name}' with budget {$budget} micros...");
        $result = $googleAds->createSearchCampaign($account, $name, $budget, $strategy);

        $this->info("Campaign created: ID {$result['campaign_id']} (Status: PAUSED)");
        $this->line("Resource: {$result['campaign_resource']}");
        $this->line("Budget: {$result['budget_resource']}");

        $this->outputJson($result);

        return self::SUCCESS;
    }

    protected function handleCreateAdGroup(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $campaignId = (string) $this->option('campaign');
        if ($campaignId === '') {
            $this->error('--campaign=ID is required to create an ad group.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: 'Ad Group '.now()->format('Y-m-d H:i'));
        $bid = (int) $this->option('bid');

        $this->info("Creating ad group '{$name}' in campaign {$campaignId} with CPC bid {$bid} micros...");
        $result = $googleAds->createAdGroup($account, $campaignId, $name, $bid);

        $this->info("Ad Group created: ID {$result['ad_group_id']}");
        $this->line("Resource: {$result['resource_name']}");

        $this->outputJson($result);

        return self::SUCCESS;
    }

    protected function handleAddKeywords(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $adGroupId = (string) $this->option('ad-group');
        if ($adGroupId === '') {
            $this->error('--ad-group=ID is required to add keywords.');

            return self::FAILURE;
        }

        $keywords = $this->parseList((string) $this->option('keywords'));
        if (empty($keywords)) {
            $this->error('--keywords="kw1,kw2" is required.');

            return self::FAILURE;
        }

        $matchType = (string) ($this->option('match') ?: 'BROAD');
        $this->info('Adding '.count($keywords)." keywords to ad group {$adGroupId} ({$matchType})...");

        $resources = $googleAds->addKeywords($account, $adGroupId, $keywords, $matchType);
        $this->info('Added '.count($resources).' keyword criteria.');

        $this->outputJson(['resource_names' => $resources]);

        return self::SUCCESS;
    }

    protected function handleAddNegativeKeywords(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $campaignId = (string) $this->option('campaign');
        if ($campaignId === '') {
            $this->error('--campaign=ID is required to add negative keywords.');

            return self::FAILURE;
        }

        $keywords = $this->parseList((string) $this->option('keywords'));
        if (empty($keywords)) {
            $this->error('--keywords="kw1,kw2" is required.');

            return self::FAILURE;
        }

        $matchType = (string) ($this->option('match') ?: 'BROAD');
        $this->info('Adding '.count($keywords)." negative keywords to campaign {$campaignId} ({$matchType})...");

        $resources = $googleAds->addNegativeKeywords($account, $campaignId, $keywords, $matchType);
        $this->info('Added '.count($resources).' negative keyword criteria.');

        $this->outputJson(['resource_names' => $resources]);

        return self::SUCCESS;
    }

    protected function handleCreateRsa(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $adGroupId = (string) $this->option('ad-group');
        if ($adGroupId === '') {
            $this->error('--ad-group=ID is required to create a Responsive Search Ad.');

            return self::FAILURE;
        }

        $headlines = $this->parseList((string) $this->option('headlines'));
        $descriptions = $this->parseList((string) $this->option('descriptions'));
        $url = (string) $this->option('url');

        if (count($headlines) < 3 || count($headlines) > 15) {
            $this->error('--headlines must contain between 3 and 15 items.');

            return self::FAILURE;
        }

        if (count($descriptions) < 2 || count($descriptions) > 4) {
            $this->error('--descriptions must contain between 2 and 4 items.');

            return self::FAILURE;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('--url must be a valid URL.');

            return self::FAILURE;
        }

        $path1 = $this->option('path1') ? (string) $this->option('path1') : null;
        $path2 = $this->option('path2') ? (string) $this->option('path2') : null;

        $this->info("Creating Responsive Search Ad in ad group {$adGroupId}...");
        $result = $googleAds->createResponsiveSearchAd($account, $adGroupId, $headlines, $descriptions, $url, $path1, $path2);

        $this->info("RSA created: ID {$result['ad_id']}");
        $this->line("Resource: {$result['resource_name']}");

        $this->outputJson($result);

        return self::SUCCESS;
    }

    protected function handleUpdateStatus(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $campaignId = (string) $this->option('campaign');
        $status = strtoupper((string) $this->option('status'));

        if ($campaignId === '' || $status === '') {
            $this->error('--campaign=ID and --status=(ENABLED|PAUSED|REMOVED) are required.');

            return self::FAILURE;
        }

        $confirm = (bool) $this->option('confirm');
        if ($status === 'REMOVED' && ! $confirm) {
            $this->error('Setting status to REMOVED requires --confirm flag.');

            return self::FAILURE;
        }

        $this->info("Updating campaign {$campaignId} status to {$status}...");
        $googleAds->updateCampaignStatus($account, $campaignId, $status, $confirm);
        $this->info('Status updated successfully.');

        return self::SUCCESS;
    }

    protected function handleUpdateBudget(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $campaignId = (string) $this->option('campaign');
        $budget = (int) $this->option('budget');

        if ($campaignId === '') {
            $this->error('--campaign=ID is required to update budget.');

            return self::FAILURE;
        }

        $this->info("Updating campaign {$campaignId} budget to {$budget} micros...");
        $googleAds->updateCampaignBudget($account, $campaignId, $budget);
        $this->info('Budget updated successfully.');

        return self::SUCCESS;
    }

    protected function handleListAdGroups(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $campaignId = (string) $this->option('campaign');
        if ($campaignId === '') {
            $this->error('--campaign=ID is required to list ad groups.');

            return self::FAILURE;
        }

        $adGroups = $googleAds->listAdGroups($account, $campaignId);
        $this->table(['ID', 'Name', 'Status', 'Type', 'CPC Bid (micros)'], array_map(fn ($ag) => [
            $ag['id'],
            $ag['name'],
            $ag['status'],
            $ag['type'],
            $ag['cpc_bid_micros'],
        ], $adGroups));

        return self::SUCCESS;
    }

    protected function handleListKeywords(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $adGroupId = (string) $this->option('ad-group');
        if ($adGroupId === '') {
            $this->error('--ad-group=ID is required to list keywords.');

            return self::FAILURE;
        }

        $keywords = $googleAds->listKeywords($account, $adGroupId);
        $this->table(['ID', 'Keyword Text', 'Match Type', 'Status'], array_map(fn ($kw) => [
            $kw['id'],
            $kw['text'],
            $kw['match_type'],
            $kw['status'],
        ], $keywords));

        return self::SUCCESS;
    }

    protected function handleListAds(GoogleAdsClient $googleAds, GoogleAdsAccount $account): int
    {
        $adGroupId = (string) $this->option('ad-group');
        if ($adGroupId === '') {
            $this->error('--ad-group=ID is required to list ads.');

            return self::FAILURE;
        }

        $ads = $googleAds->listAds($account, $adGroupId);
        $this->table(['ID', 'Type', 'Status', 'Headlines', 'Descriptions', 'Final URL'], array_map(fn ($ad) => [
            $ad['id'],
            $ad['type'],
            $ad['status'],
            implode(' | ', array_slice($ad['headlines'], 0, 3)),
            implode(' | ', array_slice($ad['descriptions'], 0, 2)),
            $ad['final_urls'][0] ?? '—',
        ], $ads));

        return self::SUCCESS;
    }

    protected function handleUnknownAction(string $action): int
    {
        $this->error("Unknown action '{$action}'. Valid actions: create, ad-group, keywords, negative-keywords, rsa, status, budget, list-ad-groups, list-keywords, list-ads");

        return self::FAILURE;
    }

    /**
     * @return list<string>
     */
    protected function parseList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        // Support both pipe and comma delimiters
        $delimiter = str_contains($raw, '|') ? '|' : ',';
        $items = explode($delimiter, $raw);

        return array_values(array_filter(array_map('trim', $items), fn ($s) => $s !== ''));
    }

    protected function resolveAccount(): ?GoogleAdsAccount
    {
        $id = $this->option('account');
        $customer = $this->option('customer');

        if ($id) {
            return GoogleAdsAccount::query()->find($id);
        }

        if ($customer) {
            $digits = preg_replace('/\D/', '', (string) $customer);

            return GoogleAdsAccount::query()->where('customer_id', $digits)->first();
        }

        return GoogleAdsAccount::query()->latest()->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function outputJson(array $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
