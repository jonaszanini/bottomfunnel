import React, { useEffect } from 'react';
import { Link, router } from '@inertiajs/react';
import ThemeToggle from './ThemeToggle';
import themeManager from '../theme-manager';

export function FlowHubLogo({ className = 'w-7 h-7 text-theme-accent' }) {
    return (
        <svg
            className={className}
            viewBox="0 0 32 32"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <rect width="32" height="32" rx="8" fill="var(--accentSubtleColor)" />
            <path
                d="M8 10C8 8.89543 8.89543 8 10 8H22C23.1046 8 24 8.89543 24 10V12C24 13.1046 23.1046 14 22 14H10C8.89543 14 8 13.1046 8 12V10Z"
                fill="var(--accentColor)"
            />
            <path
                d="M11 16C11 14.8954 11.8954 14 13 14H19C20.1046 14 21 14.8954 21 16V17C21 18.1046 20.1046 19 19 19H13C11.8954 19 11 18.1046 11 17V16Z"
                fill="var(--accentColor)"
                fillOpacity="0.8"
            />
            <path
                d="M14 21C14 19.8954 14.8954 19 16 19H16C17.1046 19 18 19.8954 18 21V23C18 24.1046 17.1046 25 16 25H16C14.8954 25 14 24.1046 14 23V21Z"
                fill="var(--accentColor)"
            />
        </svg>
    );
}

const navItems = [
    {
        name: 'Dashboard',
        href: '/',
        icon: (className) => (
            <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <rect width="7" height="9" x="3" y="3" rx="1" />
                <rect width="7" height="5" x="14" y="3" rx="1" />
                <rect width="7" height="9" x="14" y="12" rx="1" />
                <rect width="7" height="5" x="3" y="16" rx="1" />
            </svg>
        ),
    },
    {
        name: 'Watching Products',
        href: '/watching-products',
        icon: (className) => (
            <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
        ),
    },
    {
        name: 'Tracks',
        href: '/tracks',
        icon: (className) => (
            <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <circle cx="6" cy="18" r="3" />
                <circle cx="18" cy="6" r="3" />
                <circle cx="6" cy="6" r="3" />
                <path d="M6 9v6" />
                <path d="M9 6h9" />
                <path d="M18 9a9 9 0 0 1-9 9" />
            </svg>
        ),
    },
    {
        name: 'Pages',
        href: '/pages',
        icon: (className) => (
            <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" x2="8" y1="13" y2="13" />
                <line x1="16" x2="8" y1="17" y2="17" />
                <line x1="10" x2="8" y1="9" y2="9" />
            </svg>
        ),
    },
    {
        name: 'Ad Copy Studio',
        href: '/copy',
        icon: (className) => (
            <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M12 20h9" />
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
            </svg>
        ),
    },
    {
        name: 'Google Ads',
        href: '/google-ads',
        icon: (className) => (
            <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10" />
                <circle cx="12" cy="12" r="6" />
                <circle cx="12" cy="12" r="2" />
            </svg>
        ),
    },
    {
        name: 'Google Accounts',
        href: '/google-login-accounts',
        icon: (className) => (
            <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
        ),
    },
    {
        name: 'IP blocks',
        href: '/ip-blocks',
        icon: (className) => (
            <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                <line x1="9.5" y1="9.5" x2="14.5" y2="14.5" />
                <line x1="14.5" y1="9.5" x2="9.5" y2="14.5" />
            </svg>
        ),
    },
];

function SidebarNavContent({ currentUrl, username, isMobile, onClose }) {
    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <div className="flex h-full flex-col justify-between overflow-y-auto">
            {/* Top Section: Brand + Navigation */}
            <div className="flex flex-col">
                {/* Brand Header */}
                <div className="flex h-16 shrink-0 items-center justify-between px-5 border-b border-theme bg-theme-surface">
                    <Link
                        href="/"
                        onClick={() => {
                            if (isMobile && onClose) onClose();
                        }}
                        className="flex items-center gap-3 rounded-lg py-1 px-1.5 -ml-1.5 group focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)]"
                    >
                        <FlowHubLogo className="w-8 h-8 shrink-0" />
                        <div className="flex flex-col">
                            <span className="text-base font-bold tracking-tight text-theme-accent group-hover:opacity-90 transition-opacity leading-none">
                                Flow Hub
                            </span>
                            <span className="text-[11px] font-medium text-theme-muted tracking-wider uppercase mt-1">
                                Affiliate Engine
                            </span>
                        </div>
                    </Link>

                    {isMobile && (
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-1.5 text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] cursor-pointer"
                            aria-label="Close navigation menu"
                        >
                            <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    )}
                </div>

                {/* Navigation Links */}
                <nav className="p-3 space-y-1" aria-label="Main Navigation">
                    <div className="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-theme-muted">
                        Menu
                    </div>
                    {navItems.map((item) => {
                        const isActive = currentUrl === item.href || (item.href !== '/' && currentUrl.startsWith(item.href));
                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                aria-current={isActive ? 'page' : undefined}
                                onClick={() => {
                                    if (isMobile && onClose) onClose();
                                }}
                                className={`group relative flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] ${
                                    isActive
                                        ? 'bg-[var(--accentSubtleColor)] text-[var(--accentColor)] font-semibold shadow-2xs'
                                        : 'text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle'
                                }`}
                            >
                                {/* Left Active Accent Pill Bar */}
                                {isActive && (
                                    <span
                                        className="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[var(--accentColor)]"
                                        aria-hidden="true"
                                    />
                                )}
                                {item.icon(
                                    `w-5 h-5 shrink-0 transition-colors ${
                                        isActive
                                            ? 'text-[var(--accentColor)]'
                                            : 'text-theme-muted group-hover:text-theme-primary'
                                    }`
                                )}
                                <span className="truncate">{item.name}</span>
                            </Link>
                        );
                    })}
                </nav>
            </div>

            {/* Bottom / Footer Section: Theme Toggle & User Profile */}
            <div className="shrink-0 border-t border-theme p-4 space-y-4 bg-theme-surface">
                {/* Theme Selector Section */}
                <div className="flex flex-col gap-1.5">
                    <div className="flex items-center justify-between text-xs font-medium text-theme-muted px-1">
                        <span>Appearance</span>
                    </div>
                    <div className="flex justify-center w-full">
                        <ThemeToggle className="w-full justify-between" />
                    </div>
                </div>

                {/* User Profile & Logout Section */}
                <div className="pt-3 border-t border-theme flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5 min-w-0">
                        {/* Avatar initials pill */}
                        <div className="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs uppercase shrink-0 bg-[var(--accentSubtleColor)] text-[var(--accentColor)] border border-[var(--borderColor)] shadow-2xs">
                            {(username || 'OW').slice(0, 2).toUpperCase()}
                        </div>
                        <div className="flex flex-col min-w-0">
                            <span className="text-sm font-semibold text-theme-primary truncate leading-tight">
                                {username || 'owner'}
                            </span>
                            <span className="text-[11px] text-theme-muted truncate leading-tight mt-0.5">
                                Administrator
                            </span>
                        </div>
                    </div>

                    {/* Log out Button */}
                    <button
                        type="button"
                        onClick={handleLogout}
                        title="Log out of Flow Hub"
                        aria-label="Log out of Flow Hub"
                        className="flex items-center justify-center p-2 rounded-lg text-theme-secondary hover:text-[var(--dangerColor)] hover:bg-[var(--dangerBackgroundColor)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] cursor-pointer shrink-0"
                    >
                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" y1="12" x2="9" y2="12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function Sidebar({ isOpen, onClose, currentUrl, auth }) {
    const username = auth?.user?.username || 'owner';

    // Close mobile drawer on Escape key press
    useEffect(() => {
        if (!isOpen) return;

        const handleKeyDown = (e) => {
            if (e.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [isOpen, onClose]);

    // Lock body scrolling when mobile drawer is open
    useEffect(() => {
        if (isOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }

        return () => {
            document.body.style.overflow = '';
        };
    }, [isOpen]);

    return (
        <>
            {/* Desktop Fixed Left-Hand Sidebar */}
            <aside
                id="desktop-sidebar"
                className="hidden lg:flex fixed inset-y-0 left-0 z-30 w-64 flex-col border-r border-theme bg-theme-surface shadow-xs"
                aria-label="Sidebar navigation"
            >
                <SidebarNavContent
                    currentUrl={currentUrl}
                    username={username}
                    isMobile={false}
                    onClose={onClose}
                />
            </aside>

            {/* Mobile Off-canvas Drawer Container */}
            <div
                className={`fixed inset-0 z-40 lg:hidden transition-all duration-300 ${
                    isOpen ? 'visible' : 'invisible pointer-events-none delay-300'
                }`}
                role="dialog"
                aria-modal="true"
                aria-label="Mobile navigation drawer"
            >
                {/* Backdrop Blur Overlay */}
                <div
                    className={`fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity duration-300 ease-in-out ${
                        isOpen ? 'opacity-100' : 'opacity-0'
                    }`}
                    onClick={onClose}
                    aria-hidden="true"
                />

                {/* Off-canvas Sliding Drawer */}
                <aside
                    id="mobile-sidebar-drawer"
                    className={`fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] flex flex-col border-r border-theme bg-theme-surface shadow-2xl transition-transform duration-300 ease-in-out ${
                        isOpen ? 'translate-x-0' : '-translate-x-full'
                    }`}
                    aria-label="Mobile sidebar navigation"
                >
                    <SidebarNavContent
                        currentUrl={currentUrl}
                        username={username}
                        isMobile={true}
                        onClose={onClose}
                    />
                </aside>
            </div>
        </>
    );
}
