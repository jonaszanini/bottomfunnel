<?php

namespace App\Services\Google;

use App\Models\GoogleAdsAccount;
use App\Models\IpBlock;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleAdsClient
{
    public const MAX_CAMPAIGN_IP_EXCLUSIONS = 500;

    public const IP_EXCLUSION_SAFETY_THRESHOLD = 490;

    public function authorizationUrl(string $state): string
    {
        $this->assertOauthConfigured();

        $query = http_build_query([
            'client_id' => config('flowhub.google_ads.client_id'),
            'redirect_uri' => config('flowhub.google_ads.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/adwords',
                'https://www.googleapis.com/auth/datamanager',
                'https://www.googleapis.com/auth/userinfo.email',
            ]),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $this->assertOauthConfigured();
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('flowhub.google_ads.client_id'),
            'client_secret' => config('flowhub.google_ads.client_secret'),
            'redirect_uri' => config('flowhub.google_ads.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        return $this->tokenPayload($response, true);
    }

    public function accessToken(GoogleAdsAccount $account): string
    {
        $cacheKey = 'google-ads-token-'.$account->id;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->refresh($account->refresh_token);
        Cache::put($cacheKey, $token['access_token'], max(60, $token['expires_in'] - 60));

        return $token['access_token'];
    }

    /**
     * Request userinfo to get the Google account email using the access token.
     */
    public function fetchUserEmail(string $accessToken): ?string
    {
        $token = trim($accessToken);
        if (str_starts_with($token, 'Bearer ')) {
            $token = trim(substr($token, 7));
        }

        if ($token === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->acceptJson()
                ->get('https://www.googleapis.com/oauth2/v2/userinfo');

            if ($response->successful()) {
                $email = $response->json('email');
                if (is_string($email) && trim($email) !== '') {
                    return trim($email);
                }
            }

            Log::warning('Google userinfo call failed or did not return email', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Exception fetching Google userinfo email: '.$e->getMessage());
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function listAccessibleCustomers(string $accessToken): array
    {
        $this->assertDeveloperToken();
        $response = $this->send($accessToken, null, 'GET', '/customers:listAccessibleCustomers');
        $names = $response['resourceNames'] ?? [];

        return array_values(array_map(
            fn (string $name): string => $this->digits(str_replace('customers/', '', $name)),
            array_filter($names, 'is_string'),
        ));
    }

    public function customerName(string $accessToken, string $customerId, ?string $loginCustomerId): string
    {
        $customerId = $this->digits($customerId);
        $result = $this->search($accessToken, $customerId, $loginCustomerId, 'SELECT customer.descriptive_name FROM customer LIMIT 1');
        $name = $result[0]['customer']['descriptiveName'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'Google Ads '.$customerId;
    }

    /**
     * @return list<array{id: string, name: string, resource_name: string}>
     */
    public function listConversionActions(GoogleAdsAccount $account): array
    {
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT conversion_action.id, conversion_action.name, conversion_action.resource_name, conversion_action.type, conversion_action.status FROM conversion_action WHERE conversion_action.status = 'ENABLED'",
        );

        $actions = [];
        foreach ($rows as $row) {
            $action = $row['conversionAction'] ?? null;
            if (! is_array($action) || ($action['type'] ?? '') !== 'UPLOAD_CLICKS') {
                continue;
            }
            $actions[] = [
                'id' => (string) ($action['id'] ?? ''),
                'name' => (string) ($action['name'] ?? 'Conversion'),
                'resource_name' => (string) ($action['resourceName'] ?? ''),
            ];
        }

        return $actions;
    }

    /**
     * @return array{id: string, name: string, resource_name: string}
     */
    public function createConversionAction(GoogleAdsAccount $account, string $name): array
    {
        $customerId = $this->digits($account->customer_id);
        $payload = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/conversionActions:mutate',
            [
                'operations' => [[
                    'create' => [
                        'name' => $name,
                        'type' => 'UPLOAD_CLICKS',
                        'category' => 'PURCHASE',
                        'status' => 'ENABLED',
                        'countingType' => 'ONE_PER_CLICK',
                    ],
                ]],
            ],
        );

        $resource = (string) ($payload['results'][0]['resourceName'] ?? '');
        if ($resource === '') {
            throw new GoogleRequestException('Google Ads did not return the new conversion action.');
        }

        $id = basename($resource);

        return [
            'id' => $id,
            'name' => $name,
            'resource_name' => $resource,
        ];
    }

    /**
     * Query the ConversionAction and return the TagSnippet (global_site_tag + event_snippet) for type WEBPAGE.
     *
     * @return array{global_site_tag: string, event_snippet: string, type: ?string, page_format: ?string, has_tag: bool}
     */
    public function getConversionTag(GoogleAdsAccount $account, string $conversionActionResourceName): array
    {
        $customerId = $this->digits($account->customer_id);
        $resourceName = str_starts_with($conversionActionResourceName, 'customers/')
            ? $conversionActionResourceName
            : "customers/{$customerId}/conversionActions/{$conversionActionResourceName}";

        $escaped = str_replace("'", "\\'", $resourceName);

        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT conversion_action.id, conversion_action.name, conversion_action.resource_name, conversion_action.tag_snippets FROM conversion_action WHERE conversion_action.resource_name = '{$escaped}' LIMIT 1",
        );

        $snippets = [];
        if (! empty($rows)) {
            $action = $rows[0]['conversionAction'] ?? $rows[0]['conversion_action'] ?? [];
            $snippets = $action['tagSnippets'] ?? $action['tag_snippets'] ?? [];
        }

        $selectedSnippet = null;
        if (is_array($snippets)) {
            foreach ($snippets as $snippet) {
                if (! is_array($snippet)) {
                    continue;
                }
                $type = strtoupper((string) ($snippet['type'] ?? ''));
                if ($type === 'WEBPAGE') {
                    $selectedSnippet = $snippet;
                    break;
                }
            }
            if ($selectedSnippet === null && ! empty($snippets) && is_array($snippets[0])) {
                $selectedSnippet = $snippets[0];
            }
        }

        $globalSiteTag = '';
        $eventSnippet = '';
        $type = null;
        $pageFormat = null;
        $hasTag = false;

        if ($selectedSnippet !== null) {
            $globalSiteTag = (string) ($selectedSnippet['globalSiteTag'] ?? $selectedSnippet['global_site_tag'] ?? '');
            $eventSnippet = (string) ($selectedSnippet['eventSnippet'] ?? $selectedSnippet['event_snippet'] ?? '');
            $type = (string) ($selectedSnippet['type'] ?? 'WEBPAGE');
            $pageFormat = (string) ($selectedSnippet['pageFormat'] ?? $selectedSnippet['page_format'] ?? 'HTML');
            $hasTag = $globalSiteTag !== '' || $eventSnippet !== '';
        }

        // If no tag snippets exist yet in Google Ads, fallback to standard gtag for this customer ID
        if ($globalSiteTag === '' && $customerId !== '') {
            $globalSiteTag = $this->buildGlobalSiteTag($customerId);
        }

        return [
            'global_site_tag' => $globalSiteTag,
            'event_snippet' => $eventSnippet,
            'type' => $type ?? 'WEBPAGE',
            'page_format' => $pageFormat ?? 'HTML',
            'has_tag' => $hasTag,
        ];
    }

    /**
     * Build the standard Google tag (gtag.js) HTML snippet for a customer ID.
     */
    public function buildGlobalSiteTag(string $customerId): string
    {
        $id = $this->digits($customerId);
        if ($id === '') {
            return '';
        }

        return "<!-- Google tag (gtag.js) -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id=AW-{$id}\"></script>\n<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n\n  gtag('config', 'AW-{$id}');\n</script>";
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function listCampaigns(GoogleAdsAccount $account): array
    {
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT campaign.id, campaign.name, campaign.status FROM campaign WHERE campaign.status != 'REMOVED' ORDER BY campaign.name",
        );

        $campaigns = [];
        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? null;
            if (! is_array($campaign)) {
                continue;
            }
            $campaigns[] = [
                'id' => (string) ($campaign['id'] ?? ''),
                'name' => (string) ($campaign['name'] ?? 'Campaign'),
            ];
        }

        return $campaigns;
    }

    /**
     * @return list<array{resource_name: string, ip_address: string}>
     */
    public function getCampaignIpExclusions(GoogleAdsAccount $account, string $campaignId): array
    {
        $campaignId = $this->digits($campaignId);
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT campaign_criterion.resource_name, campaign_criterion.ip_block.ip_address FROM campaign_criterion WHERE campaign.id = {$campaignId} AND campaign_criterion.type = 'IP_BLOCK'",
        );

        $results = [];
        foreach ($rows as $row) {
            $criterion = $row['campaignCriterion'] ?? null;
            if (! is_array($criterion)) {
                continue;
            }
            $resourceName = (string) ($criterion['resourceName'] ?? '');
            $ip = (string) ($criterion['ipBlock']['ipAddress'] ?? '');
            if ($resourceName !== '' && $ip !== '') {
                $results[] = [
                    'resource_name' => $resourceName,
                    'ip_address' => $ip,
                ];
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $resourceNames
     */
    public function removeCriteria(GoogleAdsAccount $account, array $resourceNames): void
    {
        $resourceNames = array_values(array_filter($resourceNames));
        if ($resourceNames === []) {
            return;
        }

        $customerId = $this->digits($account->customer_id);
        $operations = array_map(fn (string $name): array => ['remove' => $name], $resourceNames);

        $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaignCriteria:mutate',
            ['operations' => $operations],
        );
    }

    /**
     * Enforce Google Ads 500 IP exclusion limit on a campaign.
     * Consolidates /24 subnets and rotates oldest exclusions if approaching 500 limit.
     *
     * @return array{consolidated_subnets: int, removed_ips: int, rotated: int, active_count: int}
     */
    public function enforceIpExclusionLimit(GoogleAdsAccount $account, string $campaignId, int $safetyThreshold = self::IP_EXCLUSION_SAFETY_THRESHOLD): array
    {
        $exclusions = $this->getCampaignIpExclusions($account, $campaignId);
        $count = count($exclusions);

        $consolidatedSubnets = 0;
        $removedIps = 0;
        $rotated = 0;

        if ($count < $safetyThreshold) {
            return [
                'consolidated_subnets' => 0,
                'removed_ips' => 0,
                'rotated' => 0,
                'active_count' => $count,
            ];
        }

        // Phase 1: Consolidate multiple IPs from the same /24 subnet into a CIDR mask
        $subnetGroups = [];
        foreach ($exclusions as $item) {
            $subnet = self::toSubnet24($item['ip_address']);
            if ($subnet !== null) {
                $subnetGroups[$subnet][] = $item;
            }
        }

        $customerId = $this->digits($account->customer_id);
        $cleanCampaignId = $this->digits($campaignId);

        foreach ($subnetGroups as $subnet => $items) {
            $hasSubnetMask = false;
            $individualItems = [];
            foreach ($items as $item) {
                if ($item['ip_address'] === $subnet) {
                    $hasSubnetMask = true;
                } else {
                    $individualItems[] = $item;
                }
            }

            if (! $hasSubnetMask && count($individualItems) >= 2) {
                $cidrPayload = $this->mutate(
                    $this->accessToken($account),
                    $customerId,
                    $account->login_customer_id,
                    '/campaignCriteria:mutate',
                    [
                        'operations' => [[
                            'create' => [
                                'campaign' => 'customers/'.$customerId.'/campaigns/'.$cleanCampaignId,
                                'negative' => true,
                                'ipBlock' => ['ipAddress' => $subnet],
                            ],
                        ]],
                    ],
                );
                $cidrResource = (string) ($cidrPayload['results'][0]['resourceName'] ?? '');

                $resourceNamesToRemove = array_map(fn (array $it): string => $it['resource_name'], $individualItems);
                $this->removeCriteria($account, $resourceNamesToRemove);

                $consolidatedSubnets++;
                $removedIps += count($individualItems);

                $removedIpList = array_map(fn (array $it): string => $it['ip_address'], $individualItems);
                IpBlock::query()
                    ->where('campaign_id', $cleanCampaignId)
                    ->whereIn('ip_address', $removedIpList)
                    ->where('status', 'applied')
                    ->update([
                        'status' => 'consolidated',
                        'last_error' => 'Consolidated into /24 subnet mask: '.$subnet,
                    ]);

                $existingBlock = IpBlock::query()
                    ->where('campaign_id', $cleanCampaignId)
                    ->whereIn('ip_address', $removedIpList)
                    ->first();
                $trackId = $existingBlock?->track_id;

                if ($trackId) {
                    IpBlock::query()->updateOrCreate(
                        [
                            'track_id' => $trackId,
                            'ip_address' => $subnet,
                            'campaign_id' => $cleanCampaignId,
                        ],
                        [
                            'google_ads_account_id' => $account->id,
                            'reason' => 'Automated /24 CIDR consolidation',
                            'criterion_resource_name' => $cidrResource,
                            'status' => 'applied',
                            'is_subnet' => true,
                            'synced_at' => now(),
                            'last_error' => null,
                        ]
                    );
                }
            } elseif ($hasSubnetMask && count($individualItems) >= 1) {
                $resourceNamesToRemove = array_map(fn (array $it): string => $it['resource_name'], $individualItems);
                $this->removeCriteria($account, $resourceNamesToRemove);

                $removedIps += count($individualItems);
                $removedIpList = array_map(fn (array $it): string => $it['ip_address'], $individualItems);
                IpBlock::query()
                    ->where('campaign_id', $cleanCampaignId)
                    ->whereIn('ip_address', $removedIpList)
                    ->where('status', 'applied')
                    ->update([
                        'status' => 'consolidated',
                        'last_error' => 'Consolidated into existing /24 subnet mask: '.$subnet,
                    ]);
            }
        }

        // Re-check count after consolidation
        $exclusions = $this->getCampaignIpExclusions($account, $campaignId);
        $count = count($exclusions);

        // Phase 2: If still approaching limit (e.g. >= 495), rotate out oldest blocked IPs
        $rotateThreshold = min(495, $safetyThreshold + 5);
        if ($count >= $rotateThreshold) {
            $slotsToFree = max(5, $count - ($safetyThreshold - 5));
            $oldestBlocks = IpBlock::query()
                ->where('campaign_id', $cleanCampaignId)
                ->where('status', 'applied')
                ->whereNotNull('criterion_resource_name')
                ->oldest()
                ->limit($slotsToFree)
                ->get();

            $resourceNamesToRotate = [];
            foreach ($oldestBlocks as $oldBlock) {
                if ($oldBlock->criterion_resource_name) {
                    $resourceNamesToRotate[] = $oldBlock->criterion_resource_name;
                }
            }

            if (count($resourceNamesToRotate) < $slotsToFree) {
                foreach ($exclusions as $item) {
                    if (count($resourceNamesToRotate) >= $slotsToFree) {
                        break;
                    }
                    if (! in_array($item['resource_name'], $resourceNamesToRotate, true)) {
                        $resourceNamesToRotate[] = $item['resource_name'];
                    }
                }
            }

            if ($resourceNamesToRotate !== []) {
                $this->removeCriteria($account, $resourceNamesToRotate);
                $rotated = count($resourceNamesToRotate);

                IpBlock::query()
                    ->where('campaign_id', $cleanCampaignId)
                    ->whereIn('criterion_resource_name', $resourceNamesToRotate)
                    ->update([
                        'status' => 'rotated',
                        'last_error' => 'Rotated out to enforce 500 campaign IP exclusion limit',
                    ]);
            }

            $count = count($this->getCampaignIpExclusions($account, $campaignId));
        }

        return [
            'consolidated_subnets' => $consolidatedSubnets,
            'removed_ips' => $removedIps,
            'rotated' => $rotated,
            'active_count' => $count,
        ];
    }

    public function blockIp(GoogleAdsAccount $account, string $campaignId, string $ip): string
    {
        $customerId = $this->digits($account->customer_id);
        $campaignId = $this->digits($campaignId);
        $existing = $this->findIpCriterion($account, $campaignId, $ip);
        if ($existing !== null) {
            return $existing;
        }

        $this->enforceIpExclusionLimit($account, $campaignId);

        $existingAfter = $this->findIpCriterion($account, $campaignId, $ip);
        if ($existingAfter !== null) {
            return $existingAfter;
        }

        $payload = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaignCriteria:mutate',
            [
                'operations' => [[
                    'create' => [
                        'campaign' => 'customers/'.$customerId.'/campaigns/'.$campaignId,
                        'negative' => true,
                        'ipBlock' => ['ipAddress' => $ip],
                    ],
                ]],
            ],
        );

        $resource = (string) ($payload['results'][0]['resourceName'] ?? '');
        if ($resource === '') {
            throw new GoogleRequestException('Google Ads did not return the IP exclusion.');
        }

        return $resource;
    }

    public function removeCriterion(GoogleAdsAccount $account, string $resourceName): void
    {
        $customerId = $this->digits($account->customer_id);
        $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaignCriteria:mutate',
            [
                'operations' => [[
                    'remove' => $resourceName,
                ]],
            ],
        );
    }

    /**
     * Create a new Search campaign and budget (always created with status PAUSED).
     *
     * @return array{campaign_id: string, campaign_resource: string, budget_resource: string}
     */
    public function createSearchCampaign(
        GoogleAdsAccount $account,
        string $name,
        int $budgetAmountMicros,
        string $biddingStrategy = 'MANUAL_CPC'
    ): array {
        $customerId = $this->digits($account->customer_id);

        // 1. Create campaign budget
        $budgetName = 'Budget - '.$name.' - '.now()->format('Ymd_His').'_'.substr(md5(uniqid()), 0, 6);
        $budgetPayload = [
            'operations' => [
                [
                    'create' => [
                        'name' => $budgetName,
                        'amountMicros' => (string) $budgetAmountMicros,
                        'deliveryMethod' => 'STANDARD',
                        'explicitlyShared' => false,
                    ],
                ],
            ],
        ];

        $budgetResponse = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaignBudgets:mutate',
            $budgetPayload
        );

        $budgetResource = (string) ($budgetResponse['results'][0]['resourceName'] ?? '');
        if ($budgetResource === '') {
            throw new GoogleRequestException('Google Ads did not return the created campaign budget resource.');
        }

        // 2. Create Search campaign with status PAUSED
        $bidding = match (strtoupper($biddingStrategy)) {
            'MAXIMIZE_CLICKS' => ['targetSpend' => new \stdClass],
            'MAXIMIZE_CONVERSIONS' => ['maximizeConversions' => new \stdClass],
            'TARGET_CPA' => ['targetCpa' => new \stdClass],
            'TARGET_ROAS' => ['targetRoas' => new \stdClass],
            default => ['manualCpc' => new \stdClass],
        };

        $campaignData = array_merge([
            'name' => $name,
            'advertisingChannelType' => 'SEARCH',
            'status' => 'PAUSED',
            'campaignBudget' => $budgetResource,
            'networkSettings' => [
                'targetGoogleSearch' => true,
                'targetSearchNetwork' => true,
                'targetContentNetwork' => false,
                'targetPartnerSearchNetwork' => false,
            ],
        ], $bidding);

        $campaignResponse = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaigns:mutate',
            [
                'operations' => [
                    [
                        'create' => $campaignData,
                    ],
                ],
            ]
        );

        $campaignResource = (string) ($campaignResponse['results'][0]['resourceName'] ?? '');
        if ($campaignResource === '') {
            throw new GoogleRequestException('Google Ads did not return the created campaign resource.');
        }

        $campaignId = basename($campaignResource);

        Log::info("Google Ads Mutate: Created Search campaign '{$name}'", [
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'campaign_id' => $campaignId,
            'campaign_resource' => $campaignResource,
            'budget_resource' => $budgetResource,
            'status' => 'PAUSED',
            'actor' => auth()->user()?->username ?? auth()->user()?->email ?? 'cli/system',
        ]);

        return [
            'campaign_id' => $campaignId,
            'campaign_resource' => $campaignResource,
            'budget_resource' => $budgetResource,
        ];
    }

    /**
     * Create an Ad Group under a campaign.
     *
     * @return array{ad_group_id: string, resource_name: string}
     */
    public function createAdGroup(
        GoogleAdsAccount $account,
        string $campaignId,
        string $name,
        int $cpcBidMicros
    ): array {
        $customerId = $this->digits($account->customer_id);
        $campaignId = $this->digits($campaignId);
        $campaignResource = "customers/{$customerId}/campaigns/{$campaignId}";

        $payload = [
            'operations' => [
                [
                    'create' => [
                        'name' => $name,
                        'campaign' => $campaignResource,
                        'status' => 'ENABLED',
                        'type' => 'SEARCH_STANDARD',
                        'cpcBidMicros' => (string) $cpcBidMicros,
                    ],
                ],
            ],
        ];

        $response = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/adGroups:mutate',
            $payload
        );

        $resourceName = (string) ($response['results'][0]['resourceName'] ?? '');
        if ($resourceName === '') {
            throw new GoogleRequestException('Google Ads did not return the created ad group resource.');
        }

        $adGroupId = basename($resourceName);

        Log::info("Google Ads Mutate: Created ad group '{$name}'", [
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'campaign_id' => $campaignId,
            'ad_group_id' => $adGroupId,
            'resource_name' => $resourceName,
            'cpc_bid_micros' => $cpcBidMicros,
            'actor' => auth()->user()?->username ?? auth()->user()?->email ?? 'cli/system',
        ]);

        return [
            'ad_group_id' => $adGroupId,
            'resource_name' => $resourceName,
        ];
    }

    /**
     * Add keywords to an ad group.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    public function addKeywords(
        GoogleAdsAccount $account,
        string $adGroupId,
        array $keywords,
        string $matchType = 'BROAD'
    ): array {
        $customerId = $this->digits($account->customer_id);
        $adGroupId = $this->digits($adGroupId);
        $adGroupResource = "customers/{$customerId}/adGroups/{$adGroupId}";
        $matchType = strtoupper(trim($matchType));

        $operations = [];
        foreach ($keywords as $kw) {
            $text = trim((string) $kw);
            if ($text === '') {
                continue;
            }
            $operations[] = [
                'create' => [
                    'adGroup' => $adGroupResource,
                    'status' => 'ENABLED',
                    'keyword' => [
                        'text' => $text,
                        'matchType' => $matchType,
                    ],
                ],
            ];
        }

        if (empty($operations)) {
            return [];
        }

        $response = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/adGroupCriteria:mutate',
            ['operations' => $operations]
        );

        $results = $response['results'] ?? [];
        $resourceNames = [];
        foreach ($results as $res) {
            if (isset($res['resourceName']) && is_string($res['resourceName'])) {
                $resourceNames[] = $res['resourceName'];
            }
        }

        Log::info('Google Ads Mutate: Added keywords to ad group', [
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'ad_group_id' => $adGroupId,
            'count' => count($resourceNames),
            'match_type' => $matchType,
            'actor' => auth()->user()?->username ?? auth()->user()?->email ?? 'cli/system',
        ]);

        return $resourceNames;
    }

    /**
     * Add campaign-level negative keywords.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    public function addNegativeKeywords(
        GoogleAdsAccount $account,
        string $campaignId,
        array $keywords,
        string $matchType = 'BROAD'
    ): array {
        $customerId = $this->digits($account->customer_id);
        $campaignId = $this->digits($campaignId);
        $campaignResource = "customers/{$customerId}/campaigns/{$campaignId}";
        $matchType = strtoupper(trim($matchType));

        $operations = [];
        foreach ($keywords as $kw) {
            $text = trim((string) $kw);
            if ($text === '') {
                continue;
            }
            $operations[] = [
                'create' => [
                    'campaign' => $campaignResource,
                    'negative' => true,
                    'keyword' => [
                        'text' => $text,
                        'matchType' => $matchType,
                    ],
                ],
            ];
        }

        if (empty($operations)) {
            return [];
        }

        $response = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaignCriteria:mutate',
            ['operations' => $operations]
        );

        $results = $response['results'] ?? [];
        $resourceNames = [];
        foreach ($results as $res) {
            if (isset($res['resourceName']) && is_string($res['resourceName'])) {
                $resourceNames[] = $res['resourceName'];
            }
        }

        Log::info('Google Ads Mutate: Added negative keywords to campaign', [
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'campaign_id' => $campaignId,
            'count' => count($resourceNames),
            'match_type' => $matchType,
            'actor' => auth()->user()?->username ?? auth()->user()?->email ?? 'cli/system',
        ]);

        return $resourceNames;
    }

    /**
     * Create a Responsive Search Ad (RSA) in an ad group.
     *
     * @param  list<string>  $headlines  3–15 headlines, max 30 chars each
     * @param  list<string>  $descriptions  2–4 descriptions, max 90 chars each
     * @return array{ad_id: string, resource_name: string}
     */
    public function createResponsiveSearchAd(
        GoogleAdsAccount $account,
        string $adGroupId,
        array $headlines,
        array $descriptions,
        string $finalUrl,
        ?string $path1 = null,
        ?string $path2 = null
    ): array {
        // Enforce headline constraints: 3 to 15, max 30 characters each
        $hCount = count($headlines);
        if ($hCount < 3 || $hCount > 15) {
            throw new GoogleRequestException("Responsive Search Ads require between 3 and 15 headlines ({$hCount} provided).");
        }

        foreach ($headlines as $i => $h) {
            $len = mb_strlen(trim((string) $h));
            if ($len === 0) {
                throw new GoogleRequestException("Headline at index {$i} is empty.");
            }
            if ($len > 30) {
                throw new GoogleRequestException("Headline at index {$i} ('{$h}') exceeds maximum of 30 characters ({$len} characters).");
            }
        }

        // Enforce description constraints: 2 to 4, max 90 characters each
        $dCount = count($descriptions);
        if ($dCount < 2 || $dCount > 4) {
            throw new GoogleRequestException("Responsive Search Ads require between 2 and 4 descriptions ({$dCount} provided).");
        }

        foreach ($descriptions as $i => $d) {
            $len = mb_strlen(trim((string) $d));
            if ($len === 0) {
                throw new GoogleRequestException("Description at index {$i} is empty.");
            }
            if ($len > 90) {
                throw new GoogleRequestException("Description at index {$i} ('{$d}') exceeds maximum of 90 characters ({$len} characters).");
            }
        }

        if (! filter_var($finalUrl, FILTER_VALIDATE_URL)) {
            throw new GoogleRequestException("The finalUrl '{$finalUrl}' is not a valid URL.");
        }

        if ($path1 !== null && mb_strlen($path1) > 15) {
            throw new GoogleRequestException("path1 ('{$path1}') exceeds maximum of 15 characters.");
        }

        if ($path2 !== null && mb_strlen($path2) > 15) {
            throw new GoogleRequestException("path2 ('{$path2}') exceeds maximum of 15 characters.");
        }

        $customerId = $this->digits($account->customer_id);
        $adGroupId = $this->digits($adGroupId);
        $adGroupResource = "customers/{$customerId}/adGroups/{$adGroupId}";

        $rsa = [
            'headlines' => array_map(fn ($text) => ['text' => trim((string) $text)], $headlines),
            'descriptions' => array_map(fn ($text) => ['text' => trim((string) $text)], $descriptions),
        ];

        if ($path1 !== null && trim($path1) !== '') {
            $rsa['path1'] = trim($path1);
        }
        if ($path2 !== null && trim($path2) !== '') {
            $rsa['path2'] = trim($path2);
        }

        $payload = [
            'operations' => [
                [
                    'create' => [
                        'adGroup' => $adGroupResource,
                        'status' => 'ENABLED',
                        'ad' => [
                            'finalUrls' => [$finalUrl],
                            'responsiveSearchAd' => $rsa,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/adGroupAds:mutate',
            $payload
        );

        $resourceName = (string) ($response['results'][0]['resourceName'] ?? '');
        if ($resourceName === '') {
            throw new GoogleRequestException('Google Ads did not return the created responsive search ad resource.');
        }

        $adId = str_contains($resourceName, '~')
            ? explode('~', basename($resourceName))[1]
            : basename($resourceName);

        Log::info('Google Ads Mutate: Created Responsive Search Ad', [
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'ad_group_id' => $adGroupId,
            'ad_id' => $adId,
            'resource_name' => $resourceName,
            'headlines_count' => $hCount,
            'descriptions_count' => $dCount,
            'actor' => auth()->user()?->username ?? auth()->user()?->email ?? 'cli/system',
        ]);

        return [
            'ad_id' => $adId,
            'resource_name' => $resourceName,
        ];
    }

    /**
     * Update campaign status (ENABLED | PAUSED | REMOVED).
     */
    public function updateCampaignStatus(
        GoogleAdsAccount $account,
        string $campaignId,
        string $status,
        bool $confirm = false
    ): void {
        $status = strtoupper(trim($status));
        if (! in_array($status, ['ENABLED', 'PAUSED', 'REMOVED'], true)) {
            throw new GoogleRequestException("Invalid campaign status '{$status}'. Allowed values are ENABLED, PAUSED, REMOVED.");
        }

        if ($status === 'REMOVED' && ! $confirm) {
            throw new GoogleRequestException('Campaign status cannot be set to REMOVED without explicit confirmation ($confirm = true).');
        }

        $customerId = $this->digits($account->customer_id);
        $campaignId = $this->digits($campaignId);
        $resourceName = "customers/{$customerId}/campaigns/{$campaignId}";

        $payload = [
            'operations' => [
                [
                    'update' => [
                        'resourceName' => $resourceName,
                        'status' => $status,
                    ],
                    'updateMask' => 'status',
                ],
            ],
        ];

        $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaigns:mutate',
            $payload
        );

        Log::info("Google Ads Mutate: Updated campaign status to '{$status}'", [
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'campaign_id' => $campaignId,
            'status' => $status,
            'actor' => auth()->user()?->username ?? auth()->user()?->email ?? 'cli/system',
        ]);
    }

    /**
     * Update campaign budget amount in micros.
     */
    public function updateCampaignBudget(
        GoogleAdsAccount $account,
        string $budgetResourceName,
        int $amountMicros
    ): void {
        $customerId = $this->digits($account->customer_id);

        if (! str_starts_with($budgetResourceName, 'customers/')) {
            $budgetId = $this->digits($budgetResourceName);
            $budgetResourceName = "customers/{$customerId}/campaignBudgets/{$budgetId}";
        }

        $payload = [
            'operations' => [
                [
                    'update' => [
                        'resourceName' => $budgetResourceName,
                        'amountMicros' => (string) $amountMicros,
                    ],
                    'updateMask' => 'amountMicros',
                ],
            ],
        ];

        $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaignBudgets:mutate',
            $payload
        );

        Log::info('Google Ads Mutate: Updated campaign budget', [
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'budget_resource' => $budgetResourceName,
            'amount_micros' => $amountMicros,
            'actor' => auth()->user()?->username ?? auth()->user()?->email ?? 'cli/system',
        ]);
    }

    /**
     * @return list<array{id: string, name: string, status: string, type: string, cpc_bid_micros: int, resource_name: string}>
     */
    public function listAdGroups(GoogleAdsAccount $account, string $campaignId): array
    {
        $campaignId = $this->digits($campaignId);
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT ad_group.id, ad_group.name, ad_group.status, ad_group.type, ad_group.cpc_bid_micros, ad_group.resource_name FROM ad_group WHERE campaign.id = {$campaignId} AND ad_group.status != 'REMOVED' ORDER BY ad_group.name"
        );

        $adGroups = [];
        foreach ($rows as $row) {
            $ag = $row['adGroup'] ?? null;
            if (! is_array($ag)) {
                continue;
            }
            $adGroups[] = [
                'id' => (string) ($ag['id'] ?? ''),
                'name' => (string) ($ag['name'] ?? ''),
                'status' => (string) ($ag['status'] ?? ''),
                'type' => (string) ($ag['type'] ?? ''),
                'cpc_bid_micros' => (int) ($ag['cpcBidMicros'] ?? 0),
                'resource_name' => (string) ($ag['resourceName'] ?? ''),
            ];
        }

        return $adGroups;
    }

    /**
     * @return list<array{id: string, text: string, match_type: string, status: string, resource_name: string}>
     */
    public function listKeywords(GoogleAdsAccount $account, string $adGroupId): array
    {
        $adGroupId = $this->digits($adGroupId);
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.status, ad_group_criterion.resource_name FROM ad_group_criterion WHERE ad_group.id = {$adGroupId} AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.status != 'REMOVED'"
        );

        $keywords = [];
        foreach ($rows as $row) {
            $crit = $row['adGroupCriterion'] ?? null;
            if (! is_array($crit)) {
                continue;
            }
            $keywords[] = [
                'id' => (string) ($crit['criterionId'] ?? ''),
                'text' => (string) ($crit['keyword']['text'] ?? ''),
                'match_type' => (string) ($crit['keyword']['matchType'] ?? ''),
                'status' => (string) ($crit['status'] ?? ''),
                'resource_name' => (string) ($crit['resourceName'] ?? ''),
            ];
        }

        return $keywords;
    }

    /**
     * @return list<array{id: string, type: string, status: string, resource_name: string, final_urls: list<string>, headlines: list<string>, descriptions: list<string>, path1: ?string, path2: ?string}>
     */
    public function listAds(GoogleAdsAccount $account, string $adGroupId): array
    {
        $adGroupId = $this->digits($adGroupId);
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT ad_group_ad.ad.id, ad_group_ad.ad.type, ad_group_ad.status, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions, ad_group_ad.ad.responsive_search_ad.path1, ad_group_ad.ad.responsive_search_ad.path2, ad_group_ad.resource_name FROM ad_group_ad WHERE ad_group.id = {$adGroupId} AND ad_group_ad.status != 'REMOVED'"
        );

        $ads = [];
        foreach ($rows as $row) {
            $adGroupAd = $row['adGroupAd'] ?? null;
            if (! is_array($adGroupAd)) {
                continue;
            }
            $ad = $adGroupAd['ad'] ?? [];
            $rsa = $ad['responsiveSearchAd'] ?? [];

            $headlines = [];
            foreach ($rsa['headlines'] ?? [] as $h) {
                if (isset($h['text']) && is_string($h['text'])) {
                    $headlines[] = $h['text'];
                }
            }

            $descriptions = [];
            foreach ($rsa['descriptions'] ?? [] as $d) {
                if (isset($d['text']) && is_string($d['text'])) {
                    $descriptions[] = $d['text'];
                }
            }

            $ads[] = [
                'id' => (string) ($ad['id'] ?? ''),
                'type' => (string) ($ad['type'] ?? ''),
                'status' => (string) ($adGroupAd['status'] ?? ''),
                'resource_name' => (string) ($adGroupAd['resourceName'] ?? ''),
                'final_urls' => array_values(array_filter($ad['finalUrls'] ?? [], 'is_string')),
                'headlines' => $headlines,
                'descriptions' => $descriptions,
                'path1' => isset($rsa['path1']) && is_string($rsa['path1']) ? $rsa['path1'] : null,
                'path2' => isset($rsa['path2']) && is_string($rsa['path2']) ? $rsa['path2'] : null,
            ];
        }

        return $ads;
    }

    public static function toSubnet24(string $ip): ?string
    {
        $ip = trim($ip);
        if (str_ends_with($ip, '/24')) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
            }
        }

        return null;
    }

    public static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if ($ip === $cidr) {
            return true;
        }
        if (! str_contains($cidr, '/')) {
            return false;
        }
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function findIpCriterion(GoogleAdsAccount $account, string $campaignId, string $ip): ?string
    {
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT campaign_criterion.resource_name, campaign_criterion.ip_block.ip_address FROM campaign_criterion WHERE campaign.id = {$campaignId} AND campaign_criterion.type = 'IP_BLOCK'",
        );

        foreach ($rows as $row) {
            $criterion = $row['campaignCriterion'] ?? null;
            if (! is_array($criterion)) {
                continue;
            }
            $blocked = (string) ($criterion['ipBlock']['ipAddress'] ?? '');
            if ($blocked === $ip || self::ipMatchesCidr($ip, $blocked)) {
                return (string) ($criterion['resourceName'] ?? '');
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $accessToken, string $customerId, ?string $loginCustomerId, string $query): array
    {
        $customerId = $this->digits($customerId);
        $payload = $this->send($accessToken, $loginCustomerId, 'POST', '/customers/'.$customerId.'/googleAds:search', [
            'query' => $query,
        ]);

        return is_array($payload['results'] ?? null) ? $payload['results'] : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function mutate(string $accessToken, string $customerId, ?string $loginCustomerId, string $path, array $body): array
    {
        return $this->send($accessToken, $loginCustomerId, 'POST', '/customers/'.$customerId.$path, $body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $accessToken, ?string $loginCustomerId, string $method, string $path, ?array $body = null): array
    {
        $this->assertDeveloperToken();
        $version = (string) config('flowhub.google_ads.version', 'v22');
        $headers = [
            'Authorization' => 'Bearer '.$accessToken,
        ];
        $developerToken = config('flowhub.google_ads.developer_token');
        if (is_string($developerToken) && $developerToken !== '') {
            $headers['developer-token'] = $developerToken;
        }
        $login = $this->digits((string) $loginCustomerId);
        if ($login !== '') {
            $headers['login-customer-id'] = $login;
        }

        $request = Http::withHeaders($headers)->timeout(25)->acceptJson();
        $url = 'https://googleads.googleapis.com/'.$version.$path;
        $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body ?? []);

        return $this->jsonOrFail($response);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function refresh(string $refreshToken): array
    {
        $this->assertOauthConfigured();
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('flowhub.google_ads.client_id'),
            'client_secret' => config('flowhub.google_ads.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        return $this->tokenPayload($response, false);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function tokenPayload(Response $response, bool $requireRefresh): array
    {
        if ($response->failed()) {
            $message = $response->json('error_description') ?: $response->json('error') ?: 'Google did not return a token.';
            throw new GoogleRequestException('Google OAuth failed: '.$message, $response->status() >= 500);
        }

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        if (! is_string($access) || $access === '') {
            throw new GoogleRequestException('Google OAuth did not return an access token.');
        }
        if ($requireRefresh && (! is_string($refresh) || $refresh === '')) {
            throw new GoogleRequestException('Google did not return a refresh token. Remove the app access in the Google Account and link it again.');
        }

        return [
            'access_token' => $access,
            'refresh_token' => is_string($refresh) ? $refresh : '',
            'expires_in' => (int) ($response->json('expires_in') ?: 3600),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOrFail(Response $response): array
    {
        if ($response->failed()) {
            $message = $this->errorMessage($response);
            $transient = in_array($response->status(), [429, 500, 502, 503, 504], true);
            throw new GoogleRequestException($message, $transient);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function errorMessage(Response $response): string
    {
        $errors = $response->json('error.details.0.errors') ?? $response->json('error.errors');
        if (is_array($errors) && isset($errors[0]['message'])) {
            return 'Google Ads: '.$errors[0]['message'];
        }
        $message = $response->json('error.message');
        if (is_string($message) && $message !== '') {
            return 'Google Ads: '.$message;
        }

        return 'Google Ads request failed with status '.$response->status().'.';
    }

    public function assertOauthConfigured(): void
    {
        if (! config('flowhub.google_ads.client_id') || ! config('flowhub.google_ads.client_secret')) {
            throw new GoogleRequestException('Google OAuth is not configured. Set GOOGLE_ADS_CLIENT_ID and GOOGLE_ADS_CLIENT_SECRET.');
        }
    }

    private function assertDeveloperToken(): void
    {
        $this->assertOauthConfigured();
        // Developer token is optional after the September 2026 sunset.
    }

    public function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
