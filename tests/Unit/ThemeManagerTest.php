<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ThemeManager;
use Illuminate\Http\Request;
use Tests\TestCase;

class ThemeManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[ThemeManager::COOKIE_NAME]);
        parent::tearDown();
    }

    public function test_default_preference_is_system(): void
    {
        $request = Request::create('/');
        $this->assertSame(ThemeManager::THEME_SYSTEM, ThemeManager::getPreference($request));
        $this->assertSame('', ThemeManager::getHtmlClass($request));
        $this->assertSame(ThemeManager::THEME_SYSTEM, ThemeManager::getDataTheme($request));
    }

    public function test_dark_preference_from_request_cookie(): void
    {
        $request = Request::create('/', cookies: [ThemeManager::COOKIE_NAME => 'dark']);

        $this->assertSame(ThemeManager::THEME_DARK, ThemeManager::getPreference($request));
        $this->assertSame('dark', ThemeManager::getHtmlClass($request));
        $this->assertSame('dark', ThemeManager::getDataTheme($request));
        $this->assertTrue(ThemeManager::isExplicitlyDark($request));
        $this->assertFalse(ThemeManager::isExplicitlyLight($request));
    }

    public function test_light_preference_from_request_cookie(): void
    {
        $request = Request::create('/', cookies: [ThemeManager::COOKIE_NAME => 'light']);

        $this->assertSame(ThemeManager::THEME_LIGHT, ThemeManager::getPreference($request));
        $this->assertSame('light', ThemeManager::getHtmlClass($request));
        $this->assertSame('light', ThemeManager::getDataTheme($request));
        $this->assertTrue(ThemeManager::isExplicitlyLight($request));
        $this->assertFalse(ThemeManager::isExplicitlyDark($request));
    }

    public function test_global_cookie_superglobal_fallback(): void
    {
        $_COOKIE[ThemeManager::COOKIE_NAME] = 'dark';

        $this->assertSame(ThemeManager::THEME_DARK, ThemeManager::getPreference(null));
        $this->assertSame('dark', ThemeManager::getHtmlClass(null));
    }

    public function test_invalid_cookie_falls_back_to_system(): void
    {
        $request = Request::create('/', cookies: [ThemeManager::COOKIE_NAME => 'invalid_scheme']);

        $this->assertSame(ThemeManager::THEME_SYSTEM, ThemeManager::getPreference($request));
        $this->assertSame('', ThemeManager::getHtmlClass($request));
        $this->assertSame(ThemeManager::THEME_SYSTEM, ThemeManager::getDataTheme($request));
    }

    public function test_make_cookie_creates_proper_cookie(): void
    {
        $cookie = ThemeManager::makeCookie(ThemeManager::THEME_DARK);

        $this->assertSame(ThemeManager::COOKIE_NAME, $cookie->getName());
        $this->assertSame(ThemeManager::THEME_DARK, $cookie->getValue());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertFalse($cookie->isHttpOnly());
    }
}
