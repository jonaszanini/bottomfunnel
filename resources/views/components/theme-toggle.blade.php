@props([
    'id' => 'theme-toggle-'.uniqid(),
    'size' => 'md', // sm, md
    'showLabels' => false,
])

@php
    $currentPreference = \App\Support\ThemeManager::getPreference();
@endphp

<div
    id="{{ $id }}"
    class="theme-toggle-segmented"
    data-theme-toggle
    role="radiogroup"
    aria-label="Color theme selection"
>
    {{-- Animated sliding indicator pill --}}
    <span class="theme-toggle-indicator" data-theme-indicator aria-hidden="true"></span>

    {{-- Light Option --}}
    <button
        type="button"
        class="theme-toggle-btn {{ $currentPreference === 'light' ? 'is-active' : '' }}"
        data-theme-option="light"
        role="radio"
        aria-checked="{{ $currentPreference === 'light' ? 'true' : 'false' }}"
        tabindex="{{ $currentPreference === 'light' ? '0' : '-1' }}"
        title="Light theme"
        aria-label="Light theme"
    >
        <svg class="theme-toggle-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd" />
        </svg>
        @if ($showLabels)
            <span class="theme-toggle-label">Light</span>
        @endif
    </button>

    {{-- Dark Option --}}
    <button
        type="button"
        class="theme-toggle-btn {{ $currentPreference === 'dark' ? 'is-active' : '' }}"
        data-theme-option="dark"
        role="radio"
        aria-checked="{{ $currentPreference === 'dark' ? 'true' : 'false' }}"
        tabindex="{{ $currentPreference === 'dark' ? '0' : '-1' }}"
        title="Dark theme"
        aria-label="Dark theme"
    >
        <svg class="theme-toggle-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
        </svg>
        @if ($showLabels)
            <span class="theme-toggle-label">Dark</span>
        @endif
    </button>

    {{-- System / Auto Option --}}
    <button
        type="button"
        class="theme-toggle-btn {{ $currentPreference === 'system' ? 'is-active' : '' }}"
        data-theme-option="system"
        role="radio"
        aria-checked="{{ $currentPreference === 'system' ? 'true' : 'false' }}"
        tabindex="{{ $currentPreference === 'system' ? '0' : '-1' }}"
        title="System preference (auto)"
        aria-label="System preference (auto)"
    >
        <svg class="theme-toggle-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2h-2.22l.123.489.804.804A1 1 0 0113 18H7a1 1 0 01-.707-1.707l.804-.804L7.22 15H5a2 2 0 01-2-2V5zm2 0v8h10V5H5z" clip-rule="evenodd" />
        </svg>
        @if ($showLabels)
            <span class="theme-toggle-label">Auto</span>
        @endif
    </button>
</div>

@once
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.ThemeManager) {
            window.ThemeManager.syncAllToggles();
        }
    });
</script>
@endonce
