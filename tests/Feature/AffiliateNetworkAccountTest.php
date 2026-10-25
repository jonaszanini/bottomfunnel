<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AffiliateNetwork;
use App\Enums\AffiliateNetworkAccountStatus;
use App\Models\AffiliateNetworkAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AffiliateNetworkAccountTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_unauthenticated_user_cannot_access_affiliate_accounts(): void
    {
        $this->get('/affiliate-network-accounts')
            ->assertRedirect('/login');
    }

    public function test_unauthenticated_user_cannot_reveal_affiliate_passwords(): void
    {
        $account = AffiliateNetworkAccount::factory()->create([
            'password' => 'SecretAffiliate123!',
        ]);

        $this->postJson("/affiliate-network-accounts/{$account->id}/reveal-password")
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_view_index_with_hidden_passwords(): void
    {
        $account = AffiliateNetworkAccount::factory()->create([
            'network' => AffiliateNetwork::Clickbank,
            'nickname' => 'My ClickBank Store',
            'username' => 'clickbank_user',
            'password' => 'CbPassphrase#2026',
        ]);

        $response = $this->actingAs($this->user)->get('/affiliate-network-accounts');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('AffiliateAccounts/Index')
            ->has('accounts', 1)
            ->where('accounts.0.nickname', 'My ClickBank Store')
            ->where('accounts.0.network', 'clickbank')
            ->where('accounts.0.network_label', 'ClickBank')
            ->where('accounts.0.has_password', true)
            ->missing('accounts.0.password')
        );

        // Password must not leak in raw Inertia HTML payload
        $this->assertStringNotContainsString('CbPassphrase#2026', $response->getContent());
    }

    public function test_affiliate_password_is_stored_encrypted_and_reveal_endpoint_works(): void
    {
        $plainPassword = 'AffiliateSecretKey#999!';

        $response = $this->actingAs($this->user)->post('/affiliate-network-accounts', [
            'network' => 'digistore24',
            'nickname' => 'DigiStore Master',
            'username' => 'digi_affiliate',
            'email' => 'digi@company.com',
            'password' => $plainPassword,
            'account_id' => 'DS-89211',
            'status' => 'active',
            'default_currency' => 'EUR',
        ]);

        $response->assertRedirect('/affiliate-network-accounts');

        $account = AffiliateNetworkAccount::where('nickname', 'DigiStore Master')->firstOrFail();

        // 1. In DB, the column is encrypted
        $rawRow = DB::table('affiliate_network_accounts')->where('id', $account->id)->first();
        $this->assertNotEmpty($rawRow->password);
        $this->assertNotEquals($plainPassword, $rawRow->password);

        // 2. Serialization hides password
        $this->assertArrayNotHasKey('password', $account->toArray());
        $this->assertStringNotContainsString($plainPassword, $account->toJson());

        // 3. Reveal endpoint decrypts and returns password
        $revealResponse = $this->actingAs($this->user)
            ->postJson("/affiliate-network-accounts/{$account->id}/reveal-password");

        $revealResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'password' => $plainPassword,
            ]);
    }

    public function test_network_custom_is_required_when_network_is_other(): void
    {
        $this->actingAs($this->user)->post('/affiliate-network-accounts', [
            'network' => 'other',
            'network_custom' => '', // missing
            'nickname' => 'Custom Network Account',
            'password' => 'Pass1234!',
            'status' => 'active',
        ])->assertSessionHasErrors(['network_custom']);

        // With custom network supplied
        $this->actingAs($this->user)->post('/affiliate-network-accounts', [
            'network' => 'other',
            'network_custom' => 'CJ Affiliate',
            'nickname' => 'CJ Account',
            'password' => 'Pass1234!',
            'status' => 'active',
        ])->assertRedirect('/affiliate-network-accounts');

        $account = AffiliateNetworkAccount::where('nickname', 'CJ Account')->firstOrFail();
        $this->assertEquals(AffiliateNetwork::Other, $account->network);
        $this->assertEquals('CJ Affiliate', $account->display_network_name);
    }

    public function test_account_can_be_updated_and_deleted(): void
    {
        $account = AffiliateNetworkAccount::factory()->create([
            'nickname' => 'Initial Nickname',
            'status' => AffiliateNetworkAccountStatus::Active,
        ]);

        // Update without changing password
        $this->actingAs($this->user)->put("/affiliate-network-accounts/{$account->id}", [
            'network' => 'maxweb',
            'nickname' => 'Updated Nickname',
            'status' => 'suspended',
            'password' => '', // empty keeps original
        ])->assertRedirect('/affiliate-network-accounts');

        $account->refresh();
        $this->assertEquals('Updated Nickname', $account->nickname);
        $this->assertEquals(AffiliateNetworkAccountStatus::Suspended, $account->status);
        $this->assertTrue($account->has_password);

        // Delete account
        $this->actingAs($this->user)
            ->delete("/affiliate-network-accounts/{$account->id}")
            ->assertRedirect('/affiliate-network-accounts');

        $this->assertDatabaseMissing('affiliate_network_accounts', [
            'id' => $account->id,
        ]);
    }

    public function test_filter_by_status_and_network(): void
    {
        AffiliateNetworkAccount::factory()->create([
            'network' => AffiliateNetwork::Clickbank,
            'status' => AffiliateNetworkAccountStatus::Active,
        ]);

        AffiliateNetworkAccount::factory()->create([
            'network' => AffiliateNetwork::Digistore24,
            'status' => AffiliateNetworkAccountStatus::Suspended,
        ]);

        $res = $this->actingAs($this->user)->get('/affiliate-network-accounts?network=clickbank');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page->has('accounts', 1));

        $resSuspended = $this->actingAs($this->user)->get('/affiliate-network-accounts?status=suspended');
        $resSuspended->assertOk();
        $resSuspended->assertInertia(fn ($page) => $page->has('accounts', 1));
    }
}
