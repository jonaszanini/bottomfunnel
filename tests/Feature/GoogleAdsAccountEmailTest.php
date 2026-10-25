<?php

namespace Tests\Feature;

use App\Models\GoogleAdsAccount;
use App\Models\User;
use App\Services\Google\GoogleAdsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GoogleAdsAccountEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.developer_token' => 'test-dev-token',
            'flowhub.google_ads.version' => 'v22',
            'flowhub.google_ads.redirect' => 'http://localhost/google-ads/callback',
        ]);

        $this->user = User::factory()->create([
            'username' => 'testowner',
        ]);
    }

    public function test_linking_an_account_persists_the_email(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-oauth-access-token',
                'refresh_token' => 'mock-oauth-refresh-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v22/customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/1234567890'],
            ], 200),
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'advertiser@example.com',
                'name' => 'Advertiser User',
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/googleAds:search' => Http::response([
                'results' => [
                    [
                        'customer' => [
                            'resourceName' => 'customers/1234567890',
                            'descriptiveName' => 'Primary Brand Account',
                        ],
                    ],
                ],
            ], 200),
        ]);

        // 1. Visit Callback with valid state
        $response = $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'valid-state'])
            ->get('/google-ads/callback?code=mock-auth-code&state=valid-state');

        $response->assertRedirect('/google-ads');
        $this->assertEquals('advertiser@example.com', session('google_oauth_email'));
        $this->assertEquals(['1234567890'], session('google_oauth_customers'));

        // 2. Complete Store step
        $storeResponse = $this->actingAs($this->user)
            ->post('/google-ads/accounts', [
                'customer_id' => '1234567890',
            ]);

        $storeResponse->assertRedirect('/google-ads');

        $account = GoogleAdsAccount::query()->where('customer_id', '1234567890')->first();
        $this->assertNotNull($account);
        $this->assertEquals('Primary Brand Account', $account->name);
        $this->assertEquals('advertiser@example.com', $account->email);
        $this->assertEquals('linked', $account->status);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://www.googleapis.com/oauth2/v2/userinfo'
                && $request->hasHeader('Authorization', 'Bearer mock-oauth-access-token');
        });
    }

    public function test_fetch_user_email_sends_authorization_bearer_header_and_returns_email(): void
    {
        Http::fake([
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'direct-test@example.com',
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $email = $client->fetchUserEmail('fresh-token-abc');

        $this->assertEquals('direct-test@example.com', $email);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://www.googleapis.com/oauth2/v2/userinfo'
                && $request->hasHeader('Authorization', 'Bearer fresh-token-abc');
        });
    }

    public function test_fetch_user_email_logs_warning_and_returns_null_on_401(): void
    {
        Http::fake([
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'error' => [
                    'code' => 401,
                    'message' => 'Request is missing required authentication credential.',
                    'status' => 'UNAUTHENTICATED',
                ],
            ], 401),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'Google userinfo call failed')
                && ($context['status'] ?? null) === 401
            );

        $client = app(GoogleAdsClient::class);
        $email = $client->fetchUserEmail('bad-or-missing-token');

        $this->assertNull($email);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://www.googleapis.com/oauth2/v2/userinfo'
                && $request->hasHeader('Authorization', 'Bearer bad-or-missing-token');
        });
    }

    public function test_fetch_user_email_returns_null_without_request_when_token_is_empty(): void
    {
        Http::fake();

        $client = app(GoogleAdsClient::class);
        $email = $client->fetchUserEmail('   ');

        $this->assertNull($email);
        Http::assertNothingSent();
    }

    public function test_linking_an_account_handles_userinfo_failure_gracefully_and_saves_null_email(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-oauth-access-token',
                'refresh_token' => 'mock-oauth-refresh-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v22/customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/9876543210'],
            ], 200),
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'error' => 'Internal server error',
            ], 500),
            'https://googleads.googleapis.com/v22/customers/9876543210/googleAds:search' => Http::response([
                'results' => [
                    [
                        'customer' => [
                            'resourceName' => 'customers/9876543210',
                            'descriptiveName' => 'Fallback Account',
                        ],
                    ],
                ],
            ], 200),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Google userinfo call failed'));

        // 1. Callback should succeed without erroring out
        $response = $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'valid-state'])
            ->get('/google-ads/callback?code=mock-auth-code&state=valid-state');

        $response->assertRedirect('/google-ads');
        $this->assertNull(session('google_oauth_email'));

        // 2. Store should still save the account with email = null
        $storeResponse = $this->actingAs($this->user)
            ->post('/google-ads/accounts', [
                'customer_id' => '9876543210',
            ]);

        $storeResponse->assertRedirect('/google-ads');

        $account = GoogleAdsAccount::query()->where('customer_id', '9876543210')->first();
        $this->assertNotNull($account);
        $this->assertEquals('Fallback Account', $account->name);
        $this->assertNull($account->email);
    }

    public function test_backfill_command_fills_null_emails_and_skips_accounts_that_already_have_one(): void
    {
        $accountWithoutEmail = GoogleAdsAccount::query()->create([
            'customer_id' => '3333333333',
            'name' => 'Needs Email Account',
            'email' => null,
            'refresh_token' => 'refresh-token-3',
            'status' => 'linked',
        ]);

        $accountWithEmail = GoogleAdsAccount::query()->create([
            'customer_id' => '4444444444',
            'name' => 'Existing Email Account',
            'email' => 'already-captured@example.com',
            'refresh_token' => 'refresh-token-4',
            'status' => 'linked',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-refreshed-token',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'backfilled-user@example.com',
            ], 200),
        ]);

        $this->artisan('google-ads:backfill-emails')
            ->expectsOutputToContain('Found 1 Google Ads account(s) missing email.')
            ->expectsOutputToContain('Needs Email Account (3333333333): successfully updated email to backfilled-user@example.com.')
            ->expectsOutputToContain('Summary: updated 1, failed 0')
            ->assertSuccessful();

        $this->assertEquals('backfilled-user@example.com', $accountWithoutEmail->fresh()->email);
        $this->assertEquals('already-captured@example.com', $accountWithEmail->fresh()->email);
    }

    public function test_backfill_command_handles_failures_gracefully(): void
    {
        $accountFailed = GoogleAdsAccount::query()->create([
            'customer_id' => '5555555555',
            'name' => 'Broken Account',
            'email' => null,
            'refresh_token' => 'refresh-token-5',
            'status' => 'linked',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-refreshed-token-5',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'error' => 'Invalid credentials',
            ], 401),
        ]);

        $this->artisan('google-ads:backfill-emails')
            ->expectsOutputToContain('Found 1 Google Ads account(s) missing email.')
            ->expectsOutputToContain('Broken Account (5555555555): failed - userinfo did not return an email.')
            ->expectsOutputToContain('Summary: updated 0, failed 1')
            ->assertSuccessful();

        $this->assertNull($accountFailed->fresh()->email);
    }

    public function test_index_inertia_response_contains_the_email_field(): void
    {
        GoogleAdsAccount::query()->create([
            'customer_id' => '7777777777',
            'name' => 'Email Render Account',
            'email' => 'profile@domain.com',
            'refresh_token' => 'token-7',
            'status' => 'linked',
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->user)->get('/google-ads');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Google/Index')
            ->has('accounts', 1)
            ->where('accounts.0.customer_id', '7777777777')
            ->where('accounts.0.email', 'profile@domain.com')
        );
    }
}
