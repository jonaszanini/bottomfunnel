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

    <title>{{ $title ?? config('app.name', 'Flow Hub') }}</title>

    {{-- Immediate inline FOUC prevention and OS theme auto-detection --}}
    @include('partials.theme-head')

    {{-- Favicon Assets matching sidebar brand mark --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-theme-canvas text-theme-primary antialiased">
    {{-- Reusable Sidebar (Desktop Fixed + Mobile Off-canvas Drawer) --}}
    @include('partials.sidebar')

    {{-- Main Application Wrapper: Correctly offset by sidebar width on desktop --}}
    <div class="lg:pl-64 flex flex-col min-h-screen">
        {{-- Mobile Top Header Bar --}}
        <header class="lg:hidden sticky top-0 z-20 flex h-16 items-center justify-between border-b border-theme bg-theme-surface px-4 shadow-xs">
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    data-sidebar-toggle
                    class="rounded-lg p-2 text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] cursor-pointer"
                    aria-label="Open navigation menu"
                    aria-expanded="false"
                    aria-controls="blade-mobile-sidebar-drawer"
                >
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="4" y1="6" x2="20" y2="6" />
                        <line x1="4" y1="12" x2="20" y2="12" />
                        <line x1="4" y1="18" x2="20" y2="18" />
                    </svg>
                </button>
                <a href="{{ url('/') }}" class="flex items-center gap-2 font-semibold text-theme-accent tracking-tight text-lg">
                    <svg class="w-7 h-7 shrink-0 text-theme-accent" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect width="32" height="32" rx="8" fill="var(--accentSubtleColor)" />
                        <path d="M8 10C8 8.89543 8.89543 8 10 8H22C23.1046 8 24 8.89543 24 10V12C24 13.1046 23.1046 14 22 14H10C8.89543 14 8 13.1046 8 12V10Z" fill="var(--accentColor)" />
                        <path d="M11 16C11 14.8954 11.8954 14 13 14H19C20.1046 14 21 14.8954 21 16V17C21 18.1046 20.1046 19 19 19H13C11.8954 19 11 18.1046 11 17V16Z" fill="var(--accentColor)" fill-opacity="0.8" />
                        <path d="M14 21C14 19.8954 14.8954 19 16 19H16C17.1046 19 18 19.8954 18 21V23C18 24.1046 17.1046 25 16 25H16C14.8954 25 14 24.1046 14 23V21Z" fill="var(--accentColor)" />
                    </svg>
                    <span>Flow Hub</span>
                </a>
            </div>
            <div class="flex items-center gap-2">
                <x-theme-toggle />
            </div>
        </header>

        {{-- Primary Content Area --}}
        <main class="flex-1 mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</body>
</html>
