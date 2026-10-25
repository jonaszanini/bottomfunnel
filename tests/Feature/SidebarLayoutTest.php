<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_accesses_inertia_dashboard_and_receives_shared_props(): void
    {
        $user = User::factory()->create([
            'username' => 'owner',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('auth.user', fn ($page) => $page
                ->where('username', 'owner')
            )
            ->has('theme', fn ($page) => $page
                ->has('preference')
                ->has('htmlClass')
            )
        );
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_login_page_renders_inertia_root_with_theme_head(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('id="app"', $content);
        $this->assertStringContainsString('prefers-color-scheme: dark', $content);
        $this->assertStringContainsString('Flow Hub', $content);
    }

    public function test_authenticated_user_accesses_inertia_dashboard_and_tracks_routes(): void
    {
        $user = User::factory()->create([
            'username' => 'owner',
            'password' => 'ChangeMe123!',
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tracks')
            ->assertOk();

        $this->actingAs($user)
            ->get('/pages')
            ->assertOk();

        $this->actingAs($user)
            ->get('/google-ads')
            ->assertOk();

        $this->actingAs($user)
            ->get('/ip-blocks')
            ->assertOk();

        $this->actingAs($user)
            ->get('/watching-products')
            ->assertOk();

        $this->actingAs($user)
            ->get('/google-login-accounts')
            ->assertOk();

        $this->actingAs($user)
            ->get('/affiliate-network-accounts')
            ->assertOk();
    }

    public function test_authenticated_user_can_filter_dashboard_data(): void
    {
        $user = User::factory()->create([
            'username' => 'owner',
        ]);

        $response = $this->actingAs($user)
            ->get('/?from=2026-01-01&to=2026-01-31&strategy=bof_direct&conversion_status=converted');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('filters.from', '2026-01-01')
            ->where('filters.to', '2026-01-31')
            ->where('filters.strategy', 'bof_direct')
            ->where('filters.conversion_status', 'converted')
        );
    }
}
