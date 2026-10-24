<?php

namespace Tests\Feature\Tracking;

use App\Jobs\ApplyIpBlockJob;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\IpBlock;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpBlockSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GoogleAdsAccount $account;

    private GoogleAdsCampaign $campaign;

    private Track $track;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['username' => 'sec_engineer']);

        $this->account = GoogleAdsAccount::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Security Master Account',
            'customer_id' => '1122334455',
            'refresh_token' => 'mock-token',
            'status' => 'ACTIVE',
            'is_default' => true,
        ]);

        $this->campaign = GoogleAdsCampaign::query()->create([
            'user_id' => $this->user->id,
            'google_ads_account_id' => $this->account->id,
            'customer_id' => '1122334455',
            'campaign_id' => '88990011',
            'name' => 'Search - Fraud Protected',
            'status' => 'ENABLED',
            'budget_amount_micros' => 10000000,
            'bidding_strategy' => 'MANUAL_CPC',
        ]);

        $action = $this->account->conversionActions()->create([
            'resource_name' => 'customers/1122334455/conversionActions/88',
            'external_id' => '88',
            'name' => 'Purchase',
        ]);

        $this->track = Track::query()->create([
            'product_name' => 'Fraud Guard Offer',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://example.com/hop',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $this->account->id,
            'conversion_action_id' => $action->id,
            'campaign_ids' => ['88990011'],
        ]);
    }

    public function test_ip_block_mass_assignment_and_casts_for_all_pipeline_attributes(): void
    {
        $syncedTime = now()->subMinutes(5);

        $block = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'google_ads_account_id' => $this->account->id,
            'ip_address' => '198.51.100.45',
            'fraud_score' => 85,
            'is_subnet' => false,
            'reason' => 'Repeated clicks with zero engagement',
            'campaign_id' => '88990011',
            'criterion_resource_name' => 'customers/1122334455/campaignCriteria/88990011~9988',
            'status' => 'applied',
            'last_error' => null,
            'synced_at' => $syncedTime,
        ]);

        $this->assertDatabaseHas('ip_blocks', [
            'id' => $block->id,
            'track_id' => $this->track->id,
            'google_ads_account_id' => $this->account->id,
            'ip_address' => '198.51.100.45',
            'fraud_score' => 85,
            'is_subnet' => 0,
            'campaign_id' => '88990011',
            'status' => 'applied',
        ]);

        $fresh = $block->fresh();
        $this->assertIsInt($fresh->track_id);
        $this->assertIsInt($fresh->google_ads_account_id);
        $this->assertIsInt($fresh->fraud_score);
        $this->assertSame(85, $fresh->fraud_score);
        $this->assertIsBool($fresh->is_subnet);
        $this->assertFalse($fresh->is_subnet);
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->synced_at);
        $this->assertSame($syncedTime->toDateTimeString(), $fresh->synced_at->toDateTimeString());
    }

    public function test_booted_hook_automatically_sets_is_subnet_when_cidr_mask_provided(): void
    {
        $cidrBlock = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'google_ads_account_id' => $this->account->id,
            'ip_address' => '198.51.100.0/24',
            'reason' => 'Automated /24 CIDR consolidation',
            'campaign_id' => '88990011',
            'status' => 'applied',
        ]);

        $this->assertTrue($cidrBlock->is_subnet);
        $this->assertTrue($cidrBlock->isSubnet());
        $this->assertDatabaseHas('ip_blocks', [
            'id' => $cidrBlock->id,
            'ip_address' => '198.51.100.0/24',
            'is_subnet' => 1,
        ]);
    }

    public function test_relationships_to_track_account_and_campaign(): void
    {
        $block = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'google_ads_account_id' => $this->account->id,
            'campaign_id' => '88990011',
            'ip_address' => '203.0.113.12',
            'reason' => 'Bot datacenter IP',
            'status' => 'applied',
        ]);

        $this->assertInstanceOf(Track::class, $block->track);
        $this->assertSame($this->track->id, $block->track->id);

        $this->assertInstanceOf(GoogleAdsAccount::class, $block->account);
        $this->assertSame($this->account->id, $block->account->id);

        $this->assertInstanceOf(GoogleAdsCampaign::class, $block->campaign);
        $this->assertSame($this->campaign->campaign_id, $block->campaign->campaign_id);

        // Test inverse relationships
        $this->assertTrue($this->track->ipBlocks->contains('id', $block->id));
        $this->assertTrue($this->account->ipBlocks->contains('id', $block->id));
    }

    public function test_ip_block_scopes_and_status_helper_methods(): void
    {
        $applied = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'ip_address' => '10.0.0.1',
            'status' => 'applied',
            'reason' => 'test',
        ]);

        $pending = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'ip_address' => '10.0.0.2',
            'status' => 'pending',
            'reason' => 'test',
        ]);

        $whitelisted = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'ip_address' => '10.0.0.3',
            'status' => 'whitelisted',
            'reason' => 'test',
        ]);

        $consolidated = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'ip_address' => '10.0.0.4',
            'status' => 'consolidated',
            'reason' => 'test',
        ]);

        $rotated = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'ip_address' => '10.0.0.5',
            'status' => 'rotated',
            'reason' => 'test',
        ]);

        $failed = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'ip_address' => '10.0.0.6',
            'status' => 'failed',
            'reason' => 'test',
        ]);

        $subnet = IpBlock::query()->create([
            'track_id' => $this->track->id,
            'ip_address' => '192.168.1.0/24',
            'status' => 'applied',
            'reason' => 'subnet block',
        ]);

        // Assert scopes
        $this->assertSame(1, IpBlock::query()->applied()->where('ip_address', '10.0.0.1')->count());
        $this->assertSame(1, IpBlock::query()->pending()->where('ip_address', '10.0.0.2')->count());
        $this->assertSame(1, IpBlock::query()->whitelisted()->where('ip_address', '10.0.0.3')->count());
        $this->assertSame(1, IpBlock::query()->consolidated()->where('ip_address', '10.0.0.4')->count());
        $this->assertSame(1, IpBlock::query()->rotated()->where('ip_address', '10.0.0.5')->count());
        $this->assertSame(1, IpBlock::query()->failed()->where('ip_address', '10.0.0.6')->count());
        $this->assertSame(3, IpBlock::query()->whereIn('id', [$applied->id, $pending->id, $whitelisted->id, $failed->id])->active()->count());
        $this->assertSame(1, IpBlock::query()->subnets()->where('ip_address', '192.168.1.0/24')->count());

        // Assert helper methods
        $this->assertTrue($applied->isApplied());
        $this->assertFalse($applied->isPending());
        $this->assertTrue($pending->isPending());
        $this->assertTrue($whitelisted->isWhitelisted());
        $this->assertTrue($consolidated->isConsolidated());
        $this->assertTrue($rotated->isRotated());
        $this->assertTrue($failed->isFailed());
        $this->assertTrue($subnet->isSubnet());
    }

    public function test_ip_block_controller_persists_fraud_score_and_renders_synced_fields(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->post('/ip-blocks/block', [
            'track_id' => $this->track->id,
            'ip_address' => '198.51.100.77',
            'fraud_score' => 92,
            'reason' => 'Manual block from inspect table',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ip_blocks', [
            'track_id' => $this->track->id,
            'ip_address' => '198.51.100.77',
            'fraud_score' => 92,
            'status' => 'pending',
        ]);

        Queue::assertPushed(ApplyIpBlockJob::class);
    }
}
