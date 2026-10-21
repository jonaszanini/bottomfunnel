<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_access_terms_of_service_page(): void
    {
        $response = $this->get('/terms-of-service');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal/TermsOfService')
            );
    }

    public function test_guest_can_access_privacy_policy_page(): void
    {
        $response = $this->get('/privacy-policy');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal/PrivacyPolicy')
            );
    }

    public function test_terms_and_privacy_convenience_redirects(): void
    {
        $this->get('/terms')
            ->assertRedirect('/terms-of-service');

        $this->get('/privacy')
            ->assertRedirect('/privacy-policy');
    }

    public function test_authenticated_user_can_access_legal_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/terms-of-service')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal/TermsOfService')
            );

        $this->actingAs($user)
            ->get('/privacy-policy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Legal/PrivacyPolicy')
            );
    }
}
