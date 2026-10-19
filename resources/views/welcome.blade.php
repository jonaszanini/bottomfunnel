@php
    $themePreference = \App\Support\ThemeManager::getPreference();
    $themeHtmlClass = \App\Support\ThemeManager::getHtmlClass();
    $dataTheme = \App\Support\ThemeManager::getDataTheme();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $themeHtmlClass }}" data-theme="{{ $dataTheme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Flow Hub') }}</title>

        {{-- Immediate inline FOUC prevention and OS theme auto-detection --}}
        @include('partials.theme-head')

        @fonts

        <!-- Styles / Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col" style="background-color: var(--primaryBackgroundColor); color: var(--primaryTextColor);">
        <header class="w-full lg:max-w-4xl max-w-[335px] text-sm mb-6">
            <nav class="flex items-center justify-between gap-4">
                {{-- Flow Hub Brand Link --}}
                <a href="{{ url('/') }}" class="text-base font-semibold tracking-tight" style="color: var(--accentColor);">
                    {{ config('app.name', 'Flow Hub') }}
                </a>

                <div class="flex items-center gap-3">
                    {{-- Accessible 3-state Theme Toggle Component --}}
                    <x-theme-toggle />

                    @if (Route::has('login'))
                        @auth
                            <a
                                href="{{ url('/dashboard') }}"
                                class="inline-block px-4 py-1.5 rounded-md text-sm font-medium transition-colors"
                                style="background-color: var(--surfaceBackgroundColor); color: var(--primaryTextColor); border: 1px solid var(--borderColor);"
                            >
                                Dashboard
                            </a>
                        @else
                            <a
                                href="{{ route('login') }}"
                                class="inline-block px-4 py-1.5 rounded-md text-sm font-medium transition-colors"
                                style="background-color: var(--accentColor); color: var(--accentTextColor);"
                            >
                                Log in
                            </a>
                        @endauth
                    @endif
                </div>
            </nav>
        </header>

        <div class="flex items-center justify-center w-full transition-opacity opacity-100 duration-750 lg:grow">
            <main class="flex max-w-[335px] w-full flex-col-reverse lg:max-w-4xl lg:flex-row shadow-sm rounded-lg overflow-hidden" style="border: 1px solid var(--borderColor);">
                <div class="text-[13px] leading-[20px] flex-1 p-6 pb-6 lg:p-20 lg:pb-10 rounded-bl-lg rounded-br-lg lg:rounded-tl-lg lg:rounded-br-none" style="background-color: var(--surfaceBackgroundColor); color: var(--primaryTextColor);">
                    <h1 class="mb-1 text-base font-semibold" style="color: var(--primaryTextColor);">Welcome to Flow Hub</h1>
                    <p class="mb-4" style="color: var(--secondaryTextColor);">
                        Your unified affiliate marketing tracking, conversion analytics, and landing page engine.
                    </p>

                    <ul class="flex flex-col mb-4 lg:mb-6">
                        <li class="flex items-center gap-4 py-2 relative before:border-l before:top-1/2 before:bottom-0 before:left-[0.4rem] before:absolute" style="before:border-color: var(--borderColor);">
                            <span class="relative py-1" style="background-color: var(--surfaceBackgroundColor);">
                                <span class="flex items-center justify-center rounded-full w-3.5 h-3.5 border" style="background-color: var(--primaryBackgroundColor); border-color: var(--borderColor);">
                                    <span class="rounded-full w-1.5 h-1.5" style="background-color: var(--accentColor);"></span>
                                </span>
                            </span>
                            <span>
                                Open the
                                <a href="{{ route('login') }}" class="inline-flex items-center space-x-1 font-medium underline underline-offset-4 ml-1" style="color: var(--accentColor);">
                                    <span>Dashboard &amp; Tracks</span>
                                    <svg width="10" height="11" viewBox="0 0 10 11" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5 ml-1">
                                        <path d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001" stroke="currentColor" stroke-linecap="square" />
                                    </svg>
                                </a>
                            </span>
                        </li>

                        <li class="flex items-center gap-4 py-2 relative before:border-l before:bottom-1/2 before:top-0 before:left-[0.4rem] before:absolute" style="before:border-color: var(--borderColor);">
                            <span class="relative py-1" style="background-color: var(--surfaceBackgroundColor);">
                                <span class="flex items-center justify-center rounded-full w-3.5 h-3.5 border" style="background-color: var(--primaryBackgroundColor); border-color: var(--borderColor);">
                                    <span class="rounded-full w-1.5 h-1.5" style="background-color: var(--accentColor);"></span>
                                </span>
                            </span>
                            <span>
                                View documentation at
                                <a href="https://laravel.com/docs" target="_blank" rel="noopener noreferrer" class="inline-flex items-center space-x-1 font-medium underline underline-offset-4 ml-1" style="color: var(--accentColor);">
                                    <span>Laravel Docs</span>
                                    <svg width="10" height="11" viewBox="0 0 10 11" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5 ml-1">
                                        <path d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001" stroke="currentColor" stroke-linecap="square" />
                                    </svg>
                                </a>
                            </span>
                        </li>
                    </ul>

                    <ul class="flex gap-3 text-sm leading-normal">
                        <li>
                            <a href="{{ route('login') }}" class="inline-block px-5 py-2 rounded-md font-medium text-sm transition-opacity hover:opacity-90" style="background-color: var(--accentColor); color: var(--accentTextColor);">
                                Go to Dashboard
                            </a>
                        </li>
                    </ul>

                    <p class="mt-6 lg:mt-10 text-xs" style="color: var(--mutedTextColor);">
                        Flow Hub &bull; Laravel v{{ app()->version() }} &bull; PHP v{{ PHP_VERSION }}
                    </p>
                </div>

                <div class="relative lg:-ml-px -mb-px lg:mb-0 rounded-t-lg lg:rounded-t-none lg:rounded-r-lg aspect-[335/364] lg:aspect-auto w-full lg:w-[438px] shrink-0 overflow-hidden flex flex-col items-center justify-center p-8" style="background-color: var(--subtleBackgroundColor); border-left: 1px solid var(--borderColor);">
                    <div class="p-6 rounded-2xl max-w-xs text-center" style="background-color: var(--surfaceBackgroundColor); border: 1px solid var(--borderColor); box-shadow: var(--shadowMd);">
                        <div class="w-12 h-12 mx-auto rounded-full flex items-center justify-center mb-3" style="background-color: var(--accentSubtleColor); color: var(--accentColor);">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <h2 class="text-sm font-semibold mb-1" style="color: var(--primaryTextColor);">Dual Engine Dark Mode</h2>
                        <p class="text-xs mb-3" style="color: var(--secondaryTextColor);">
                            Supports instant system auto-detection, server cookie persistence, and zero flash of unstyled content.
                        </p>
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium" style="background-color: var(--accentSubtleColor); color: var(--accentColor);">
                            <span class="w-2 h-2 rounded-full" style="background-color: var(--accentColor);"></span>
                            <span>Tokens Active</span>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <footer class="mt-8 text-center text-xs" style="color: var(--mutedTextColor);">
            <span>Theme synchronized with design tokens &bull; Cookie persisted</span>
        </footer>
    </body>
</html>
