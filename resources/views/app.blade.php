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

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="antialiased" style="background-color: var(--primaryBackgroundColor); color: var(--primaryTextColor);">
    @inertia
</body>
</html>
