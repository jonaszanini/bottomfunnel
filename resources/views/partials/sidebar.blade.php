@php
    $username = auth()->user()->username ?? 'owner';
    $navLinks = [
        [
            'name' => 'Dashboard',
            'href' => url('/'),
            'active' => request()->is('/') || request()->is('dashboard*'),
            'icon' => '<svg class=":class" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1" /><rect width="7" height="5" x="14" y="3" rx="1" /><rect width="7" height="9" x="14" y="12" rx="1" /><rect width="7" height="5" x="3" y="16" rx="1" /></svg>'
        ],
        [
            'name' => 'Watching Products',
            'href' => url('/watching-products'),
            'active' => request()->is('watching-products*'),
            'icon' => '<svg class=":class" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /></svg>'
        ],
        [
            'name' => 'Tracks',
            'href' => url('/tracks'),
            'active' => request()->is('tracks*'),
            'icon' => '<svg class=":class" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="18" r="3" /><circle cx="18" cy="6" r="3" /><circle cx="6" cy="6" r="3" /><path d="M6 9v6" /><path d="M9 6h9" /><path d="M18 9a9 9 0 0 1-9 9" /></svg>'
        ],
        [
            'name' => 'Pages',
            'href' => url('/pages'),
            'active' => request()->is('pages*'),
            'icon' => '<svg class=":class" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" /><polyline points="14 2 14 8 20 8" /><line x1="16" x2="8" y1="13" y2="13" /><line x1="16" x2="8" y1="17" y2="17" /><line x1="10" x2="8" y1="9" y2="9" /></svg>'
        ],
        [
            'name' => 'Ad Copy Studio',
            'href' => url('/copy'),
            'active' => request()->is('copy*'),
            'icon' => '<svg class=":class" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" /></svg>'
        ],
        [
            'name' => 'Google Ads',
            'href' => url('/google-ads'),
            'active' => request()->is('google-ads*'),
            'icon' => '<svg class=":class" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><circle cx="12" cy="12" r="6" /><circle cx="12" cy="12" r="2" /></svg>'
        ],
        [
            'name' => 'IP blocks',
            'href' => url('/ip-blocks'),
            'active' => request()->is('ip-blocks*'),
            'icon' => '<svg class=":class" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" /><line x1="9.5" y1="9.5" x2="14.5" y2="14.5" /><line x1="14.5" y1="9.5" x2="9.5" y2="14.5" /></svg>'
        ],
    ];
@endphp

{{-- ========================================================================
     Desktop Fixed Left-Hand Sidebar
     ======================================================================== --}}
<aside
    id="blade-desktop-sidebar"
    class="hidden lg:flex fixed inset-y-0 left-0 z-30 w-64 flex-col border-r border-theme bg-theme-surface shadow-xs"
    aria-label="Sidebar navigation"
>
    <div class="flex h-full flex-col justify-between overflow-y-auto">
        <div class="flex flex-col">
            {{-- Brand Header --}}
            <div class="flex h-16 shrink-0 items-center justify-between px-5 border-b border-theme bg-theme-surface">
                <a href="{{ url('/') }}" class="flex items-center gap-3 rounded-lg py-1 px-1.5 -ml-1.5 group focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)]">
                    <svg class="w-8 h-8 shrink-0 text-theme-accent" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect width="32" height="32" rx="8" fill="var(--accentSubtleColor)" />
                        <path d="M8 10C8 8.89543 8.89543 8 10 8H22C23.1046 8 24 8.89543 24 10V12C24 13.1046 23.1046 14 22 14H10C8.89543 14 8 13.1046 8 12V10Z" fill="var(--accentColor)" />
                        <path d="M11 16C11 14.8954 11.8954 14 13 14H19C20.1046 14 21 14.8954 21 16V17C21 18.1046 20.1046 19 19 19H13C11.8954 19 11 18.1046 11 17V16Z" fill="var(--accentColor)" fill-opacity="0.8" />
                        <path d="M14 21C14 19.8954 14.8954 19 16 19H16C17.1046 19 18 19.8954 18 21V23C18 24.1046 17.1046 25 16 25H16C14.8954 25 14 24.1046 14 23V21Z" fill="var(--accentColor)" />
                    </svg>
                    <div class="flex flex-col">
                        <span class="text-base font-bold tracking-tight text-theme-accent group-hover:opacity-90 transition-opacity leading-none">
                            Flow Hub
                        </span>
                        <span class="text-[11px] font-medium text-theme-muted tracking-wider uppercase mt-1">
                            Affiliate Engine
                        </span>
                    </div>
                </a>
            </div>

            {{-- Navigation Links --}}
            <nav class="p-3 space-y-1" aria-label="Main Navigation">
                <div class="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-theme-muted">
                    Menu
                </div>
                @foreach ($navLinks as $item)
                    <a
                        href="{{ $item['href'] }}"
                        @if ($item['active']) aria-current="page" @endif
                        class="group relative flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] {{ $item['active'] ? 'bg-[var(--accentSubtleColor)] text-[var(--accentColor)] font-semibold shadow-2xs' : 'text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle' }}"
                    >
                        @if ($item['active'])
                            <span class="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[var(--accentColor)]" aria-hidden="true"></span>
                        @endif
                        {!! str_replace(':class', 'w-5 h-5 shrink-0 transition-colors ' . ($item['active'] ? 'text-[var(--accentColor)]' : 'text-theme-muted group-hover:text-theme-primary'), $item['icon']) !!}
                        <span class="truncate">{{ $item['name'] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        {{-- Footer Section --}}
        <div class="shrink-0 border-t border-theme p-4 space-y-4 bg-theme-surface">
            <div class="flex flex-col gap-1.5">
                <div class="flex items-center justify-between text-xs font-medium text-theme-muted px-1">
                    <span>Appearance</span>
                </div>
                <div class="flex justify-center w-full">
                    <x-theme-toggle />
                </div>
            </div>

            <div class="pt-3 border-t border-theme flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs uppercase shrink-0 bg-[var(--accentSubtleColor)] text-[var(--accentColor)] border border-[var(--borderColor)] shadow-2xs">
                        {{ strtoupper(substr($username, 0, 2)) }}
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-sm font-semibold text-theme-primary truncate leading-tight">
                            {{ $username }}
                        </span>
                        <span class="text-[11px] text-theme-muted truncate leading-tight mt-0.5">
                            Administrator
                        </span>
                    </div>
                </div>

                @if (Route::has('logout'))
                    <form method="POST" action="{{ route('logout') }}" class="m-0">
                        @csrf
                        <button
                            type="submit"
                            title="Log out of Flow Hub"
                            aria-label="Log out of Flow Hub"
                            class="flex items-center justify-center p-2 rounded-lg text-theme-secondary hover:text-[var(--dangerColor)] hover:bg-[var(--dangerBackgroundColor)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] cursor-pointer shrink-0"
                        >
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                <polyline points="16 17 21 12 16 7" />
                                <line x1="21" y1="12" x2="9" y2="12" />
                            </svg>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</aside>

{{-- ========================================================================
     Mobile Off-canvas Drawer Container & Backdrop
     ======================================================================== --}}
<div
    id="blade-mobile-sidebar-container"
    class="fixed inset-0 z-40 lg:hidden invisible pointer-events-none transition-all duration-300"
    role="dialog"
    aria-modal="true"
    aria-label="Mobile navigation drawer"
>
    {{-- Backdrop --}}
    <div
        id="blade-mobile-sidebar-backdrop"
        class="fixed inset-0 bg-black/60 backdrop-blur-xs opacity-0 transition-opacity duration-300 ease-in-out cursor-pointer"
        aria-hidden="true"
    ></div>

    {{-- Sliding Drawer Panel --}}
    <aside
        id="blade-mobile-sidebar-drawer"
        class="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] flex flex-col border-r border-theme bg-theme-surface shadow-2xl -translate-x-full transition-transform duration-300 ease-in-out"
        aria-label="Mobile sidebar navigation"
    >
        <div class="flex h-full flex-col justify-between overflow-y-auto">
            <div class="flex flex-col">
                {{-- Brand Header with Close Button --}}
                <div class="flex h-16 shrink-0 items-center justify-between px-5 border-b border-theme bg-theme-surface">
                    <a href="{{ url('/') }}" class="flex items-center gap-3 rounded-lg py-1 px-1.5 -ml-1.5 group focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)]">
                        <svg class="w-8 h-8 shrink-0 text-theme-accent" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect width="32" height="32" rx="8" fill="var(--accentSubtleColor)" />
                            <path d="M8 10C8 8.89543 8.89543 8 10 8H22C23.1046 8 24 8.89543 24 10V12C24 13.1046 23.1046 14 22 14H10C8.89543 14 8 13.1046 8 12V10Z" fill="var(--accentColor)" />
                            <path d="M11 16C11 14.8954 11.8954 14 13 14H19C20.1046 14 21 14.8954 21 16V17C21 18.1046 20.1046 19 19 19H13C11.8954 19 11 18.1046 11 17V16Z" fill="var(--accentColor)" fill-opacity="0.8" />
                            <path d="M14 21C14 19.8954 14.8954 19 16 19H16C17.1046 19 18 19.8954 18 21V23C18 24.1046 17.1046 25 16 25H16C14.8954 25 14 24.1046 14 23V21Z" fill="var(--accentColor)" />
                        </svg>
                        <div class="flex flex-col">
                            <span class="text-base font-bold tracking-tight text-theme-accent group-hover:opacity-90 transition-opacity leading-none">
                                Flow Hub
                            </span>
                            <span class="text-[11px] font-medium text-theme-muted tracking-wider uppercase mt-1">
                                Affiliate Engine
                            </span>
                        </div>
                    </a>

                    <button
                        type="button"
                        id="blade-mobile-sidebar-close"
                        class="rounded-lg p-1.5 text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] cursor-pointer"
                        aria-label="Close navigation menu"
                    >
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>

                {{-- Navigation Links --}}
                <nav class="p-3 space-y-1" aria-label="Main Navigation">
                    <div class="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-theme-muted">
                        Menu
                    </div>
                    @foreach ($navLinks as $item)
                        <a
                            href="{{ $item['href'] }}"
                            @if ($item['active']) aria-current="page" @endif
                            class="group relative flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] {{ $item['active'] ? 'bg-[var(--accentSubtleColor)] text-[var(--accentColor)] font-semibold shadow-2xs' : 'text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle' }}"
                        >
                            @if ($item['active'])
                                <span class="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[var(--accentColor)]" aria-hidden="true"></span>
                            @endif
                            {!! str_replace(':class', 'w-5 h-5 shrink-0 transition-colors ' . ($item['active'] ? 'text-[var(--accentColor)]' : 'text-theme-muted group-hover:text-theme-primary'), $item['icon']) !!}
                            <span class="truncate">{{ $item['name'] }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>

            {{-- Footer Section --}}
            <div class="shrink-0 border-t border-theme p-4 space-y-4 bg-theme-surface">
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between text-xs font-medium text-theme-muted px-1">
                        <span>Appearance</span>
                    </div>
                    <div class="flex justify-center w-full">
                        <x-theme-toggle />
                    </div>
                </div>

                <div class="pt-3 border-t border-theme flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs uppercase shrink-0 bg-[var(--accentSubtleColor)] text-[var(--accentColor)] border border-[var(--borderColor)] shadow-2xs">
                            {{ strtoupper(substr($username, 0, 2)) }}
                        </div>
                        <div class="flex flex-col min-w-0">
                            <span class="text-sm font-semibold text-theme-primary truncate leading-tight">
                                {{ $username }}
                            </span>
                            <span class="text-[11px] text-theme-muted truncate leading-tight mt-0.5">
                                Administrator
                            </span>
                        </div>
                    </div>

                    @if (Route::has('logout'))
                        <form method="POST" action="{{ route('logout') }}" class="m-0">
                            @csrf
                            <button
                                type="submit"
                                title="Log out of Flow Hub"
                                aria-label="Log out of Flow Hub"
                                class="flex items-center justify-center p-2 rounded-lg text-theme-secondary hover:text-[var(--dangerColor)] hover:bg-[var(--dangerBackgroundColor)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] cursor-pointer shrink-0"
                            >
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                    <polyline points="16 17 21 12 16 7" />
                                    <line x1="21" y1="12" x2="9" y2="12" />
                                </svg>
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </aside>
</div>

@once
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const container = document.getElementById('blade-mobile-sidebar-container');
        const backdrop = document.getElementById('blade-mobile-sidebar-backdrop');
        const drawer = document.getElementById('blade-mobile-sidebar-drawer');
        const closeBtn = document.getElementById('blade-mobile-sidebar-close');
        const toggleBtns = document.querySelectorAll('[data-sidebar-toggle]');

        function openDrawer() {
            if (!container || !drawer || !backdrop) return;
            container.classList.remove('invisible', 'pointer-events-none');
            container.classList.add('visible');
            backdrop.classList.remove('opacity-0');
            backdrop.classList.add('opacity-100');
            drawer.classList.remove('-translate-x-full');
            drawer.classList.add('translate-x-0');
            document.body.style.overflow = 'hidden';
            toggleBtns.forEach(btn => btn.setAttribute('aria-expanded', 'true'));
        }

        function closeDrawer() {
            if (!container || !drawer || !backdrop) return;
            backdrop.classList.remove('opacity-100');
            backdrop.classList.add('opacity-0');
            drawer.classList.remove('translate-x-0');
            drawer.classList.add('-translate-x-full');
            document.body.style.overflow = '';
            toggleBtns.forEach(btn => btn.setAttribute('aria-expanded', 'false'));
            setTimeout(() => {
                if (!drawer.classList.contains('translate-x-0')) {
                    container.classList.remove('visible');
                    container.classList.add('invisible', 'pointer-events-none');
                }
            }, 300);
        }

        toggleBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                openDrawer();
            });
        });

        if (backdrop) backdrop.addEventListener('click', closeDrawer);
        if (closeBtn) closeBtn.addEventListener('click', closeDrawer);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && container && container.classList.contains('visible')) {
                closeDrawer();
            }
        });
    });
</script>
@endonce
