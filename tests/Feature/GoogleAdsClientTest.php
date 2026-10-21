<?php

namespace Tests\Feature;

use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Http\Client\Request;
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
}
