<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GoogleLoginAccountStatus;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleLoginAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoogleLoginAccountTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_unauthenticated_user_cannot_access_inventory(): void
    {
        $this->get('/google-login-accounts')
            ->assertRedirect('/login');
    }

    public function test_unauthenticated_user_cannot_reveal_passwords(): void
    {
        $account = GoogleLoginAccount::factory()->create([
            'password' => 'SuperSecret123!',
        ]);

        $this->postJson("/google-login-accounts/{$account->id}/reveal-password")
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_view_index_with_hidden_passwords(): void
    {
        $account = GoogleLoginAccount::factory()->create([
            'email' => 'client.ads@company.com',
            'customer_id' => '1234567890',
            'password' => 'TopSecretPassphrase99!',
        ]);

        $response = $this->actingAs($this->user)->get('/google-login-accounts');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('GoogleAccounts/Index')
            ->has('accounts', 1)
            ->where('accounts.0.email', 'client.ads@company.com')
            ->where('accounts.0.customer_id', '1234567890')
            ->where('accounts.0.formatted_customer_id', '123-456-7890')
            ->where('accounts.0.has_password', true)
            ->missing('accounts.0.password')
        );

        // Raw Inertia response must NEVER leak the raw password
        $this->assertStringNotContainsString('TopSecretPassphrase99!', $response->getContent());
    }

    public function test_password_is_stored_encrypted_in_database_and_reveal_returns_original(): void
    {
        $plainPassword = 'MyVaultPassword#2026';

        $response = $this->actingAs($this->user)->post('/google-login-accounts', [
            'email' => 'login.test@example.com',
            'password' => $plainPassword,
            'customer_id' => '987-654-3210',
            'status' => 'active',
            'currency' => 'CAD',
        ]);

        $response->assertRedirect('/google-login-accounts');

        $account = GoogleLoginAccount::where('email', 'login.test@example.com')->firstOrFail();

        // 1. In the database, the password is encrypted and never plain text
        $rawRow = DB::table('google_login_accounts')->where('id', $account->id)->first();
        $this->assertNotEmpty($rawRow->password);
        $this->assertNotEquals($plainPassword, $rawRow->password);

        // 2. Model serialization hides password
        $this->assertArrayNotHasKey('password', $account->toArray());
        $this->assertStringNotContainsString($plainPassword, $account->toJson());

        // 3. Reveal endpoint returns the original plain password
        $revealResponse = $this->actingAs($this->user)
            ->postJson("/google-login-accounts/{$account->id}/reveal-password");

        $revealResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'password' => $plainPassword,
            ]);
    }

    public function test_campaigns_can_be_attached_updated_and_removed(): void
    {
        $response = $this->actingAs($this->user)->post('/google-login-accounts', [
            'email' => 'campaigns.test@example.com',
            'password' => 'Pass1234!',
            'customer_id' => '1112223334',
            'status' => 'active',
            'campaigns' => [
                [
                    'campaign_id' => '21001122334',
                    'campaign_name' => 'Search - Alpha',
                    'status' => 'active',
                ],
                [
                    'campaign_id' => '21009988776',
                    'campaign_name' => 'Display - Retargeting',
                    'status' => 'paused',
                ],
            ],
        ]);

        $response->assertRedirect('/google-login-accounts');

        $account = GoogleLoginAccount::where('email', 'campaigns.test@example.com')->firstOrFail();

        $this->assertCount(2, $account->campaigns);
        $this->assertDatabaseHas('google_login_account_campaigns', [
            'google_login_account_id' => $account->id,
            'campaign_id' => '21001122334',
            'campaign_name' => 'Search - Alpha',
            'status' => 'active',
        ]);

        // Now update by removing one campaign and modifying another
        $this->actingAs($this->user)->put("/google-login-accounts/{$account->id}", [
            'email' => 'campaigns.test@example.com',
            'customer_id' => '1112223334',
            'status' => 'active',
            'campaigns' => [
                [
                    'campaign_id' => '21001122334',
                    'campaign_name' => 'Search - Alpha (Renamed)',
                    'status' => 'paused',
                ],
            ],
        ]);

        $account->refresh();
        $this->assertCount(1, $account->campaigns);
        $this->assertDatabaseHas('google_login_account_campaigns', [
            'google_login_account_id' => $account->id,
            'campaign_id' => '21001122334',
            'campaign_name' => 'Search - Alpha (Renamed)',
            'status' => 'paused',
        ]);
        $this->assertDatabaseMissing('google_login_account_campaigns', [
            'google_login_account_id' => $account->id,
            'campaign_id' => '21009988776',
        ]);
    }

    public function test_customer_id_validation_rejects_invalid_lengths(): void
    {
        $this->actingAs($this->user)->post('/google-login-accounts', [
            'email' => 'invalid.cid@example.com',
            'password' => 'Pass1234!',
            'customer_id' => '12345', // only 5 digits
            'status' => 'active',
        ])->assertSessionHasErrors(['customer_id']);
    }

    public function test_status_transitions_manage_cancelled_at_timestamp(): void
    {
        $account = GoogleLoginAccount::factory()->create([
            'status' => GoogleLoginAccountStatus::Active,
            'cancelled_at' => null,
        ]);

        // Transition to cancelled
        $this->actingAs($this->user)->put("/google-login-accounts/{$account->id}", [
            'email' => $account->email,
            'customer_id' => $account->customer_id,
            'status' => 'cancelled',
        ])->assertRedirect('/google-login-accounts');

        $account->refresh();
        $this->assertEquals(GoogleLoginAccountStatus::Cancelled, $account->status);
        $this->assertNotNull($account->cancelled_at);

        // Transition back to active
        $this->actingAs($this->user)->put("/google-login-accounts/{$account->id}", [
            'email' => $account->email,
            'customer_id' => $account->customer_id,
            'status' => 'active',
        ])->assertRedirect('/google-login-accounts');

        $account->refresh();
        $this->assertEquals(GoogleLoginAccountStatus::Active, $account->status);
        $this->assertNull($account->cancelled_at);
    }

    public function test_account_can_be_linked_to_oauth_google_ads_account(): void
    {
        $oauthAccount = GoogleAdsAccount::factory()->create([
            'name' => 'Connected Master Ads',
            'customer_id' => '5556667777',
        ]);

        $account = GoogleLoginAccount::factory()->create([
            'linked_google_ads_account_id' => $oauthAccount->id,
        ]);

        $this->assertTrue($account->linkedGoogleAdsAccount->is($oauthAccount));
        $this->assertTrue($oauthAccount->googleLoginAccounts->contains($account));
    }

    public function test_account_can_be_deleted(): void
    {
        $account = GoogleLoginAccount::factory()->create([
            'email' => 'to.delete@example.com',
        ]);

        $this->actingAs($this->user)
            ->delete("/google-login-accounts/{$account->id}")
            ->assertRedirect('/google-login-accounts');

        $this->assertDatabaseMissing('google_login_accounts', [
            'id' => $account->id,
        ]);
    }
}
