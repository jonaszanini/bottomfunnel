/**
 * Production-Grade Dark Mode Theme Manager
 * 
 * Manages theme state (Light, Dark, System), persistent cookies for zero-FOUC
 * server rendering, native CSS media query detection, and accessible UI controls.
 */

const COOKIE_NAME = 'theme';
const COOKIE_MAX_AGE_SECONDS = 31536000; // 1 year
const THEME_LIGHT = 'light';
const THEME_DARK = 'dark';
const THEME_SYSTEM = 'system';
const TRANSITION_DURATION_MS = 280;

class ThemeManager {
    constructor() {
        this.preference = this.readPreference();
        this.mediaQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        this.transitionTimer = null;

        this.init();
    }

    /**
     * Initialize event listeners and apply initial state.
     */
    init() {
        // Apply state immediately to guarantee alignment
        this.apply(this.preference, { animate: false });

        // Listen for OS system theme changes
        if (this.mediaQuery) {
            const handleMediaChange = (e) => {
                if (this.getPreference() === THEME_SYSTEM) {
                    const resolved = e.matches ? THEME_DARK : THEME_LIGHT;
                    this.applyToDom(THEME_SYSTEM, resolved, { animate: true });
                    this.syncAllToggles();
                    this.dispatchChangeEvent(THEME_SYSTEM, resolved);
                }
            };

            if (typeof this.mediaQuery.addEventListener === 'function') {
                this.mediaQuery.addEventListener('change', handleMediaChange);
            } else if (typeof this.mediaQuery.addListener === 'function') {
                this.mediaQuery.addListener(handleMediaChange);
            }
        }

        // Initialize any toggle elements present on DOM load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.syncAllToggles());
        } else {
            this.syncAllToggles();
        }
    }

    /**
     * Read the saved theme preference from the cookie (primary) or localStorage (fallback).
     * @returns {'light' | 'dark' | 'system'}
     */
    readPreference() {
        try {
            const cookieMatch = document.cookie.match(new RegExp('(?:^|; )' + COOKIE_NAME + '=([^;]*)'));
            if (cookieMatch) {
                const val = decodeURIComponent(cookieMatch[1]);
                if (val === THEME_LIGHT || val === THEME_DARK || val === THEME_SYSTEM) {
                    return val;
                }
            }

            const localVal = window.localStorage ? window.localStorage.getItem(COOKIE_NAME) : null;
            if (localVal === THEME_LIGHT || localVal === THEME_DARK || localVal === THEME_SYSTEM) {
                return localVal;
            }
        } catch (e) {
            // LocalStorage or cookie blocked
        }

        return THEME_SYSTEM;
    }

    /**
     * Get the active user preference.
     * @returns {'light' | 'dark' | 'system'}
     */
    getPreference() {
        return this.preference;
    }

    /**
     * Get the current OS preference via native CSS media queries.
     * @returns {'light' | 'dark'}
     */
    getSystemTheme() {
        if (this.mediaQuery && this.mediaQuery.matches) {
            return THEME_DARK;
        }
        return THEME_LIGHT;
    }

    /**
     * Get the active resolved theme ('light' or 'dark').
     * @returns {'light' | 'dark'}
     */
    getResolvedTheme() {
        if (this.preference === THEME_SYSTEM) {
            return this.getSystemTheme();
        }
        return this.preference;
    }

    /**
     * Set a new theme preference with persistence and optional smooth transition.
     * @param {'light' | 'dark' | 'system'} newPreference 
     * @param {{ animate?: boolean }} options 
     */
    setPreference(newPreference, options = {}) {
        if (![THEME_LIGHT, THEME_DARK, THEME_SYSTEM].includes(newPreference)) {
            newPreference = THEME_SYSTEM;
        }

        this.preference = newPreference;
        const resolved = this.getResolvedTheme();

        // 1. Persist to cookie for subsequent PHP backend server-side rendering
        this.writeCookie(newPreference);

        // 2. Persist to localStorage for client-side resilience
        try {
            if (window.localStorage) {
                window.localStorage.setItem(COOKIE_NAME, newPreference);
            }
        } catch (e) {}

        // 3. Apply changes to DOM
        this.applyToDom(newPreference, resolved, options);

        // 4. Synchronize all UI toggles on page
        this.syncAllToggles();

        // 5. Notify observers
        this.dispatchChangeEvent(newPreference, resolved);

        // 6. Optional asynchronous sync with backend endpoint
        this.syncWithBackend(newPreference);
    }

    /**
     * Apply classes, data-attributes, and transitions to document element.
     */
    applyToDom(preference, resolved, { animate = true } = {}) {
        const root = document.documentElement;

        if (animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            root.classList.add('theme-transitioning');
            clearTimeout(this.transitionTimer);
            this.transitionTimer = setTimeout(() => {
                root.classList.remove('theme-transitioning');
            }, TRANSITION_DURATION_MS);
        }

        if (resolved === THEME_DARK) {
            root.classList.add('dark');
            root.classList.remove('light');
        } else {
            root.classList.add('light');
            root.classList.remove('dark');
        }

        root.setAttribute('data-theme', preference);
        root.setAttribute('data-theme-resolved', resolved);
        root.style.colorScheme = resolved;
    }

    /**
     * Apply initial state without animation.
     */
    apply(preference, options = { animate: false }) {
        this.applyToDom(preference, this.getResolvedTheme(), options);
    }

    /**
     * Write theme cookie.
     */
    writeCookie(value) {
        try {
            const isSecure = window.location.protocol === 'https:';
            let cookieStr = `${COOKIE_NAME}=${encodeURIComponent(value)}; path=/; max-age=${COOKIE_MAX_AGE_SECONDS}; SameSite=Lax`;
            if (isSecure) {
                cookieStr += '; Secure';
            }
            document.cookie = cookieStr;
        } catch (e) {}
    }

    /**
     * Send preference to backend endpoint to ensure session/cookie sync.
     */
    syncWithBackend(preference) {
        try {
            if (typeof fetch === 'function') {
                const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';

                fetch('/theme', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ theme: preference }),
                }).catch(() => {});
            }
        } catch (e) {}
    }

    /**
     * Dispatch event for reactive listeners.
     */
    dispatchChangeEvent(preference, resolved) {
        window.dispatchEvent(new CustomEvent('themechange', {
            detail: { preference, resolved },
        }));
    }

    /**
     * Synchronize all toggle controls rendered in the document.
     * Skips React-managed toggle components to prevent conflicting DOM mutations and duplicate event listeners.
     */
    syncAllToggles() {
        const toggleElements = document.querySelectorAll('[data-theme-toggle]:not([data-react]):not(#app [data-theme-toggle])');
        toggleElements.forEach((toggleEl) => this.initToggle(toggleEl));
    }

    /**
     * Initialize and bind an individual accessible toggle element.
     * @param {HTMLElement} container 
     */
    initToggle(container) {
        if (!container || container.hasAttribute('data-react') || container.closest('#app')) return;

        const preference = this.getPreference();
        const buttons = Array.from(container.querySelectorAll('[data-theme-option]'));
        const indicator = container.querySelector('[data-theme-indicator]');

        buttons.forEach((btn, index) => {
            const optionValue = btn.getAttribute('data-theme-option');
            const isSelected = optionValue === preference;

            btn.setAttribute('role', 'radio');
            btn.setAttribute('aria-checked', isSelected ? 'true' : 'false');
            btn.setAttribute('tabindex', isSelected ? '0' : '-1');

            if (isSelected) {
                btn.classList.add('is-active');
            } else {
                btn.classList.remove('is-active');
            }

            // Bind click handler if not already bound
            if (!btn.__themeClickBound) {
                btn.__themeClickBound = true;
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.setPreference(optionValue, { animate: true });
                    btn.focus();
                });
            }

            // Keyboard accessibility: Arrow navigation, Home, End
            if (!btn.__themeKeyBound) {
                btn.__themeKeyBound = true;
                btn.addEventListener('keydown', (e) => {
                    let targetIndex = -1;
                    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                        e.preventDefault();
                        targetIndex = (index + 1) % buttons.length;
                    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        targetIndex = (index - 1 + buttons.length) % buttons.length;
                    } else if (e.key === 'Home') {
                        e.preventDefault();
                        targetIndex = 0;
                    } else if (e.key === 'End') {
                        e.preventDefault();
                        targetIndex = buttons.length - 1;
                    }

                    if (targetIndex >= 0) {
                        const targetBtn = buttons[targetIndex];
                        const targetValue = targetBtn.getAttribute('data-theme-option');
                        this.setPreference(targetValue, { animate: true });
                        targetBtn.focus();
                    }
                });
            }
        });

        // Position animated indicator pill if present
        if (indicator) {
            const activeBtn = container.querySelector('[data-theme-option].is-active');
            if (activeBtn) {
                const containerRect = container.getBoundingClientRect();
                const btnRect = activeBtn.getBoundingClientRect();
                const leftOffset = activeBtn.offsetLeft;
                const width = activeBtn.offsetWidth;
                const height = activeBtn.offsetHeight;
                const topOffset = activeBtn.offsetTop;

                indicator.style.transform = `translateX(${leftOffset}px)`;
                indicator.style.width = `${width}px`;
                indicator.style.height = `${height}px`;
                indicator.style.top = `${topOffset}px`;
                indicator.style.opacity = '1';
            }
        }
    }
}

// Singleton instance
const themeManager = new ThemeManager();

if (typeof window !== 'undefined') {
    window.ThemeManager = themeManager;
}

export default themeManager;
export { THEME_LIGHT, THEME_DARK, THEME_SYSTEM };
