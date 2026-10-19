<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ThemeManager;
use Tests\TestCase;

class ThemeFeatureTest extends TestCase
{
    public function test_theme_endpoint_updates_cookie_successfully(): void
    {
        $response = $this->postJson('/theme', [
            'theme' => 'dark',
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'theme' => 'dark',
        ]);
        $response->assertPlainCookie(ThemeManager::COOKIE_NAME, 'dark');
    }

    public function test_theme_endpoint_validates_allowed_values(): void
    {
        $response = $this->postJson('/theme', [
            'theme' => 'neon-purple',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['theme']);
    }

    public function test_welcome_page_injects_dark_class_when_dark_cookie_provided(): void
    {
        $response = $this->withUnencryptedCookies([
            ThemeManager::COOKIE_NAME => 'dark',
        ])->get('/welcome');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('class="dark"', $content);
        $this->assertStringContainsString('data-theme="dark"', $content);
        $this->assertStringContainsString('data-theme-toggle', $content);
        $this->assertStringContainsString('prefers-color-scheme: dark', $content);
    }

    public function test_welcome_page_injects_light_class_when_light_cookie_provided(): void
    {
        $response = $this->withUnencryptedCookies([
            ThemeManager::COOKIE_NAME => 'light',
        ])->get('/welcome');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('class="light"', $content);
        $this->assertStringContainsString('data-theme="light"', $content);
    }

    public function test_welcome_page_defaults_to_system_when_no_cookie_present(): void
    {
        $response = $this->get('/welcome');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('class=""', $content);
        $this->assertStringContainsString('data-theme="system"', $content);
        $this->assertStringContainsString('prefers-color-scheme: dark', $content);
    }

    public function test_inertia_app_view_injects_dark_class_on_html_tag(): void
    {
        $response = $this->withUnencryptedCookies([
            ThemeManager::COOKIE_NAME => 'dark',
        ])->get('/login');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('class="dark"', $content);
        $this->assertStringContainsString('data-theme="dark"', $content);
        $this->assertStringContainsString('prefers-color-scheme: dark', $content);
    }
}
