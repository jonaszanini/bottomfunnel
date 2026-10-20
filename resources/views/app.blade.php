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

    {{-- Favicon Assets matching sidebar brand mark --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="antialiased" style="background-color: var(--primaryBackgroundColor); color: var(--primaryTextColor);">
    @inertia
</body>
</html>
