<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class ThemeManager
{
    public const COOKIE_NAME = 'theme';

    public const THEME_LIGHT = 'light';

    public const THEME_DARK = 'dark';

    public const THEME_SYSTEM = 'system';

    /**
     * @var list<string>
     */
    public const VALID_PREFERENCES = [
        self::THEME_LIGHT,
        self::THEME_DARK,
        self::THEME_SYSTEM,
    ];

    /**
     * @var list<string>
     */
    public const VALID_RESOLVED = [
        self::THEME_LIGHT,
        self::THEME_DARK,
    ];

    /**
     * Retrieve the stored user theme preference from cookie or fallback to system.
     */
    public static function getPreference(?Request $request = null): string
    {
        if ($request === null && function_exists('request')) {
            try {
                $request = request();
            } catch (\Throwable) {
                $request = null;
            }
        }

        $raw = null;

        if ($request !== null) {
            $raw = $request->cookie(self::COOKIE_NAME);
        }

        if ($raw === null && isset($_COOKIE[self::COOKIE_NAME])) {
            $raw = (string) $_COOKIE[self::COOKIE_NAME];
        }

        if (is_string($raw) && in_array($raw, self::VALID_PREFERENCES, true)) {
            return $raw;
        }

        return self::THEME_SYSTEM;
    }

    /**
     * Determine the initial CSS class to be injected on the <html> tag before first render.
     *
     * Returns 'light', 'dark', or '' if 'system' / unset (deferred to client inline script).
     */
    public static function getHtmlClass(?Request $request = null): string
    {
        $preference = self::getPreference($request);

        if ($preference === self::THEME_LIGHT || $preference === self::THEME_DARK) {
            return $preference;
        }

        return '';
    }

    /**
     * Retrieve the data-theme attribute value for the <html> element.
     */
    public static function getDataTheme(?Request $request = null): string
    {
        return self::getPreference($request);
    }

    /**
     * Check if a specific resolved theme is active (when cookie explicitly specifies light or dark).
     */
    public static function isExplicitlyDark(?Request $request = null): bool
    {
        return self::getPreference($request) === self::THEME_DARK;
    }

    /**
     * Check if a specific resolved theme is explicitly light.
     */
    public static function isExplicitlyLight(?Request $request = null): bool
    {
        return self::getPreference($request) === self::THEME_LIGHT;
    }

    /**
     * Create a standard 1-year persistence cookie for the chosen theme preference.
     */
    public static function makeCookie(string $preference): Cookie
    {
        $value = in_array($preference, self::VALID_PREFERENCES, true)
            ? $preference
            : self::THEME_SYSTEM;

        return cookie(
            name: self::COOKIE_NAME,
            value: $value,
            minutes: 60 * 24 * 365,
            path: '/',
            domain: null,
            secure: null,
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        );
    }
}
