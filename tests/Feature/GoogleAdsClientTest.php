<?php

namespace Tests\Feature;

use App\Models\GoogleAdsAccount;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsClientTest extends TestCase
{
    public function test_client_works_without_developer_token(): void
    {
        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.developer_token' => null,
            'flowhub.google_ads.version' => 'v22',
        ]);

        Http::fake([
            'https://googleads.googleapis.com/v22/customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/1234567890'],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $customers = $client->listAccessibleCustomers('mock-access-token');

        $this->assertEquals(['1234567890'], $customers);

        Http::assertSent(function (Request $request) {
            return ! $request->hasHeader('developer-token')
                && $request->hasHeader('Authorization', 'Bearer mock-access-token');
        });
    }

    public function test_client_sends_developer_token_when_configured(): void
    {
        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.developer_token' => 'custom-dev-token',
            'flowhub.google_ads.version' => 'v22',
        ]);

        Http::fake([
            'https://googleads.googleapis.com/v22/customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/9876543210'],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $customers = $client->listAccessibleCustomers('mock-access-token');

        $this->assertEquals(['9876543210'], $customers);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('developer-token', 'custom-dev-token')
                && $request->hasHeader('Authorization', 'Bearer mock-access-token');
        });
    }

    public function test_client_still_validates_oauth_credentials(): void
    {
        config([
            'flowhub.google_ads.client_id' => null,
            'flowhub.google_ads.client_secret' => null,
            'flowhub.google_ads.developer_token' => null,
        ]);

        $this->expectException(GoogleRequestException::class);
        $this->expectExceptionMessage('Google OAuth is not configured. Set GOOGLE_ADS_CLIENT_ID and GOOGLE_ADS_CLIENT_SECRET.');

        $client = app(GoogleAdsClient::class);
        $client->listAccessibleCustomers('mock-token');
    }

    public function test_create_search_campaign_creates_budget_and_paused_campaign(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignBudgets:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaignBudgets/88888'],
                ],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaigns:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaigns/99999'],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $result = $client->createSearchCampaign($account, 'BoF Search Campaign', 15000000, 'MANUAL_CPC');

        $this->assertEquals('99999', $result['campaign_id']);
        $this->assertEquals('customers/1234567890/campaigns/99999', $result['campaign_resource']);
        $this->assertEquals('customers/1234567890/campaignBudgets/88888', $result['budget_resource']);

        // Assert Budget payload shape
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'campaignBudgets:mutate')) {
                return false;
            }
            $op = $request['operations'][0]['create'] ?? [];

            return $op['amountMicros'] === '15000000'
                && $op['deliveryMethod'] === 'STANDARD'
                && str_contains($op['name'], 'BoF Search Campaign');
        });

        // Assert Campaign payload shape (status MUST be PAUSED)
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'campaigns:mutate')) {
                return false;
            }
            $op = $request['operations'][0]['create'] ?? [];

            return $op['name'] === 'BoF Search Campaign'
                && $op['advertisingChannelType'] === 'SEARCH'
                && $op['status'] === 'PAUSED'
                && $op['campaignBudget'] === 'customers/1234567890/campaignBudgets/88888'
                && ($op['networkSettings']['targetGoogleSearch'] ?? false) === true;
        });
    }

    public function test_create_ad_group_sends_correct_payload(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroups:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/adGroups/77777'],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $result = $client->createAdGroup($account, '99999', 'Ad Group 1', 1250000);

        $this->assertEquals('77777', $result['ad_group_id']);
        $this->assertEquals('customers/1234567890/adGroups/77777', $result['resource_name']);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'adGroups:mutate')) {
                return false;
            }
            $op = $request['operations'][0]['create'] ?? [];

            return $op['name'] === 'Ad Group 1'
                && $op['campaign'] === 'customers/1234567890/campaigns/99999'
                && $op['type'] === 'SEARCH_STANDARD'
                && $op['cpcBidMicros'] === '1250000';
        });
    }

    public function test_add_keywords_sends_correct_payload(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroupCriteria:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/adGroupCriteria/77777~101'],
                    ['resourceName' => 'customers/1234567890/adGroupCriteria/77777~102'],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $resources = $client->addKeywords($account, '77777', ['keto trim discount', 'buy keto trim'], 'PHRASE');

        $this->assertCount(2, $resources);
        $this->assertEquals('customers/1234567890/adGroupCriteria/77777~101', $resources[0]);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'adGroupCriteria:mutate')) {
                return false;
            }
            $ops = $request['operations'] ?? [];
            if (count($ops) !== 2) {
                return false;
            }

            return $ops[0]['create']['adGroup'] === 'customers/1234567890/adGroups/77777'
                && $ops[0]['create']['keyword']['text'] === 'keto trim discount'
                && $ops[0]['create']['keyword']['matchType'] === 'PHRASE'
                && $ops[1]['create']['keyword']['text'] === 'buy keto trim';
        });
    }

    public function test_add_negative_keywords_sends_correct_payload(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignCriteria:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaignCriteria/99999~201'],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $resources = $client->addNegativeKeywords($account, '99999', ['free'], 'EXACT');

        $this->assertCount(1, $resources);
        $this->assertEquals('customers/1234567890/campaignCriteria/99999~201', $resources[0]);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'campaignCriteria:mutate')) {
                return false;
            }
            $op = $request['operations'][0]['create'] ?? [];

            return $op['campaign'] === 'customers/1234567890/campaigns/99999'
                && ($op['negative'] ?? false) === true
                && $op['keyword']['text'] === 'free'
                && $op['keyword']['matchType'] === 'EXACT';
        });
    }

    public function test_create_responsive_search_ad_valid_payload(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroupAds:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/adGroupAds/77777~55555'],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $headlines = ['Official Store', 'Buy Today and Save', 'High Potency Formula'];
        $descriptions = ['Order now direct from the official website.', 'Fast discrete shipping and 60-day guarantee.'];
        $url = 'https://example.com/lander';

        $result = $client->createResponsiveSearchAd($account, '77777', $headlines, $descriptions, $url, 'official', 'order');

        $this->assertEquals('55555', $result['ad_id']);
        $this->assertEquals('customers/1234567890/adGroupAds/77777~55555', $result['resource_name']);

        Http::assertSent(function (Request $request) use ($headlines, $descriptions, $url) {
            if (! str_contains($request->url(), 'adGroupAds:mutate')) {
                return false;
            }
            $ad = $request['operations'][0]['create']['ad'] ?? [];
            $rsa = $ad['responsiveSearchAd'] ?? [];

            return $ad['finalUrls'][0] === $url
                && count($rsa['headlines']) === 3
                && $rsa['headlines'][0]['text'] === $headlines[0]
                && count($rsa['descriptions']) === 2
                && $rsa['descriptions'][0]['text'] === $descriptions[0]
                && $rsa['path1'] === 'official'
                && $rsa['path2'] === 'order';
        });
    }

    public function test_create_responsive_search_ad_rejects_too_few_headlines(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        $client = app(GoogleAdsClient::class);

        $this->expectException(GoogleRequestException::class);
        $this->expectExceptionMessage('Responsive Search Ads require between 3 and 15 headlines');

        $client->createResponsiveSearchAd(
            $account,
            '77777',
            ['Only One', 'Only Two'], // Only 2 headlines (minimum 3 required)
            ['Desc 1', 'Desc 2'],
            'https://example.com'
        );
    }

    public function test_create_responsive_search_ad_rejects_oversized_headline(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        $client = app(GoogleAdsClient::class);

        $this->expectException(GoogleRequestException::class);
        $this->expectExceptionMessage('exceeds maximum of 30 characters');

        $client->createResponsiveSearchAd(
            $account,
            '77777',
            [
                'Headline 1 Valid',
                'Headline 2 Valid',
                'This headline is way too long and clearly exceeds thirty characters',
            ],
            ['Desc 1', 'Desc 2'],
            'https://example.com'
        );
    }

    public function test_create_responsive_search_ad_rejects_too_few_descriptions(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        $client = app(GoogleAdsClient::class);

        $this->expectException(GoogleRequestException::class);
        $this->expectExceptionMessage('Responsive Search Ads require between 2 and 4 descriptions');

        $client->createResponsiveSearchAd(
            $account,
            '77777',
            ['H1 Valid', 'H2 Valid', 'H3 Valid'],
            ['Single description only'], // Only 1 description (minimum 2 required)
            'https://example.com'
        );
    }

    public function test_create_responsive_search_ad_rejects_oversized_description(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        $client = app(GoogleAdsClient::class);

        $this->expectException(GoogleRequestException::class);
        $this->expectExceptionMessage('exceeds maximum of 90 characters');

        $client->createResponsiveSearchAd(
            $account,
            '77777',
            ['H1 Valid', 'H2 Valid', 'H3 Valid'],
            [
                'Valid description 1',
                str_repeat('A', 95), // 95 chars (max 90 allowed)
            ],
            'https://example.com'
        );
    }

    public function test_update_campaign_status_and_requires_confirmation_for_removed(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaigns:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaigns/99999'],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);

        // 1. Enabling campaign
        $client->updateCampaignStatus($account, '99999', 'ENABLED');

        Http::assertSent(function (Request $request) {
            $op = $request['operations'][0]['update'] ?? [];

            return $op['status'] === 'ENABLED'
                && $request['operations'][0]['updateMask'] === 'status';
        });

        // 2. Reject REMOVED without confirmation
        try {
            $client->updateCampaignStatus($account, '99999', 'REMOVED', confirm: false);
            $this->fail('Expected GoogleRequestException when removing without confirm');
        } catch (GoogleRequestException $e) {
            $this->assertStringContainsString('without explicit confirmation', $e->getMessage());
        }

        // 3. Allow REMOVED with confirmation
        $client->updateCampaignStatus($account, '99999', 'REMOVED', confirm: true);

        Http::assertSent(function (Request $request) {
            $op = $request['operations'][0]['update'] ?? [];

            return $op['status'] === 'REMOVED';
        });
    }

    public function test_update_campaign_budget(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignBudgets:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaignBudgets/88888'],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $client->updateCampaignBudget($account, '88888', 25000000);

        Http::assertSent(function (Request $request) {
            $op = $request['operations'][0]['update'] ?? [];

            return $op['resourceName'] === 'customers/1234567890/campaignBudgets/88888'
                && $op['amountMicros'] === '25000000'
                && $request['operations'][0]['updateMask'] === 'amountMicros';
        });
    }

    public function test_list_ad_groups_keywords_and_ads(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/googleAds:search' => function (Request $request) {
                $query = $request['query'] ?? '';

                if (str_contains($query, 'FROM ad_group WHERE')) {
                    return Http::response([
                        'results' => [
                            [
                                'adGroup' => [
                                    'id' => '77777',
                                    'name' => 'Search Ad Group',
                                    'status' => 'ENABLED',
                                    'type' => 'SEARCH_STANDARD',
                                    'cpcBidMicros' => 1500000,
                                    'resourceName' => 'customers/1234567890/adGroups/77777',
                                ],
                            ],
                        ],
                    ], 200);
                }

                if (str_contains($query, 'FROM ad_group_criterion WHERE')) {
                    return Http::response([
                        'results' => [
                            [
                                'adGroupCriterion' => [
                                    'criterionId' => '101',
                                    'keyword' => ['text' => 'buy widgets', 'matchType' => 'BROAD'],
                                    'status' => 'ENABLED',
                                    'resourceName' => 'customers/1234567890/adGroupCriteria/77777~101',
                                ],
                            ],
                        ],
                    ], 200);
                }

                if (str_contains($query, 'FROM ad_group_ad WHERE')) {
                    return Http::response([
                        'results' => [
                            [
                                'adGroupAd' => [
                                    'ad' => [
                                        'id' => '55555',
                                        'type' => 'RESPONSIVE_SEARCH_AD',
                                        'finalUrls' => ['https://example.com'],
                                        'responsiveSearchAd' => [
                                            'headlines' => [['text' => 'Headline 1'], ['text' => 'Headline 2'], ['text' => 'Headline 3']],
                                            'descriptions' => [['text' => 'Desc 1'], ['text' => 'Desc 2']],
                                            'path1' => 'official',
                                            'path2' => 'shop',
                                        ],
                                    ],
                                    'status' => 'ENABLED',
                                    'resourceName' => 'customers/1234567890/adGroupAds/77777~55555',
                                ],
                            ],
                        ],
                    ], 200);
                }

                return Http::response(['results' => []], 200);
            },
        ]);

        $client = app(GoogleAdsClient::class);

        $adGroups = $client->listAdGroups($account, '99999');
        $this->assertCount(1, $adGroups);
        $this->assertEquals('77777', $adGroups[0]['id']);

        $keywords = $client->listKeywords($account, '77777');
        $this->assertCount(1, $keywords);
        $this->assertEquals('buy widgets', $keywords[0]['text']);

        $ads = $client->listAds($account, '77777');
        $this->assertCount(1, $ads);
        $this->assertEquals('55555', $ads[0]['id']);
        $this->assertEquals(['Headline 1', 'Headline 2', 'Headline 3'], $ads[0]['headlines']);
    }

    public function test_get_conversion_tag_returns_webpage_snippets(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/googleAds:search' => Http::response([
                'results' => [
                    [
                        'conversionAction' => [
                            'resourceName' => 'customers/1234567890/conversionActions/987654',
                            'id' => '987654',
                            'name' => 'Purchase',
                            'tagSnippets' => [
                                [
                                    'type' => 'WEBPAGE',
                                    'pageFormat' => 'HTML',
                                    'globalSiteTag' => '<!-- Google tag (gtag.js) -->',
                                    'eventSnippet' => '<!-- Event snippet for Purchase -->',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $result = $client->getConversionTag($account, 'customers/1234567890/conversionActions/987654');

        $this->assertTrue($result['has_tag']);
        $this->assertEquals('<!-- Google tag (gtag.js) -->', $result['global_site_tag']);
        $this->assertEquals('<!-- Event snippet for Purchase -->', $result['event_snippet']);
        $this->assertEquals('WEBPAGE', $result['type']);
    }

    public function test_get_conversion_tag_handles_missing_snippets_gracefully(): void
    {
        $this->configureOauth();
        $account = $this->createMockAccount();

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/googleAds:search' => Http::response([
                'results' => [
                    [
                        'conversionAction' => [
                            'resourceName' => 'customers/1234567890/conversionActions/987654',
                            'id' => '987654',
                            'name' => 'Offline Upload',
                            'tagSnippets' => [],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $result = $client->getConversionTag($account, '987654');

        $this->assertFalse($result['has_tag']);
        $this->assertStringContainsString('AW-1234567890', $result['global_site_tag']);
        $this->assertEquals('', $result['event_snippet']);
    }

    private function configureOauth(): void
    {
        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.version' => 'v22',
        ]);
    }

    private function createMockAccount(): GoogleAdsAccount
    {
        $account = new GoogleAdsAccount([
            'customer_id' => '1234567890',
            'name' => 'Test Account',
            'refresh_token' => 'mock-token',
        ]);
        $account->id = 1;

        Cache::put('google-ads-token-1', 'mock-access-token', 3600);

        return $account;
    }
}
