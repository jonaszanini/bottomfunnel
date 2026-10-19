<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ThemeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ThemeController extends Controller
{
    /**
     * Update the stored theme cookie.
     */
    public function update(Request $request): JsonResponse|RedirectResponse|Response
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:light,dark,system'],
        ]);

        $theme = $validated['theme'];
        $cookie = ThemeManager::makeCookie($theme);

        if ($request->wantsJson() || $request->isJson() || $request->ajax()) {
            return response()
                ->json([
                    'status' => 'success',
                    'theme' => $theme,
                ])
                ->withCookie($cookie);
        }

        return back()->withCookie($cookie);
    }
}
