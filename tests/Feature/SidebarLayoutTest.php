<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class SidebarLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_partial_renders_brand_and_navigation_links(): void
    {
        $user = User::factory()->create([
            'username' => 'owner',
        ]);

        $this->actingAs($user);

        $html = View::make('partials.sidebar')->render();

        $this->assertStringContainsString('Flow Hub', $html);
        $this->assertStringContainsString('Affiliate Engine', $html);
        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('Watching Products', $html);
        $this->assertStringContainsString('Tracks', $html);
        $this->assertStringContainsString('Pages', $html);
        $this->assertStringContainsString('Google Ads', $html);
        $this->assertStringContainsString('IP blocks', $html);
        $this->assertStringContainsString('owner', $html);
        $this->assertStringContainsString('Log out of Flow Hub', $html);
        $this->assertStringContainsString('theme-toggle-segmented', $html);
        $this->assertStringContainsString('blade-desktop-sidebar', $html);
        $this->assertStringContainsString('blade-mobile-sidebar-container', $html);
        $this->assertStringContainsString('blade-mobile-sidebar-drawer', $html);
    }

    public function test_sidebar_component_renders_correctly(): void
    {
        $user = User::factory()->create([
            'username' => 'admin_user',
        ]);

        $this->actingAs($user);

        $html = View::make('components.sidebar')->render();

        $this->assertStringContainsString('admin_user', $html);
        $this->assertStringContainsString('AD', $html); // Initials
        $this->assertStringContainsString('Flow Hub', $html);
    }

    public function test_sidebar_layout_wrapper_offsets_desktop_content(): void
    {
        $user = User::factory()->create([
            'username' => 'owner',
        ]);

        $this->actingAs($user);

        $html = View::make('layouts.sidebar-layout', [
            'title' => 'Test Dashboard',
            'slot' => '<div id="test-content">Welcome Content</div>',
        ])->render();

        $this->assertStringContainsString('Test Dashboard', $html);
        $this->assertStringContainsString('lg:pl-64', $html);
        $this->assertStringContainsString('test-content', $html);
        $this->assertStringContainsString('data-sidebar-toggle', $html);
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
    }
}
