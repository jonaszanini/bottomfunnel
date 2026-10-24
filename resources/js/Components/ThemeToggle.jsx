import React, { useEffect, useState, useRef, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import themeManager, { THEME_LIGHT, THEME_DARK, THEME_SYSTEM } from '../theme-manager';

export default function ThemeToggle({ showLabels = false, className = '' }) {
    let initialPref = null;
    try {
        const page = usePage();
        initialPref = page?.props?.theme?.preference;
    } catch {
        // Fallback when rendered outside Inertia context
    }

    const [preference, setPreference] = useState(() => initialPref || themeManager.getPreference());
    const [resolved, setResolved] = useState(() => themeManager.getResolvedTheme());
    const containerRef = useRef(null);
    const indicatorRef = useRef(null);

    const updateIndicator = useCallback(() => {
        if (!containerRef.current || !indicatorRef.current) return;

        const activeBtn = containerRef.current.querySelector('[data-theme-option].is-active');
        if (activeBtn) {
            const left = activeBtn.offsetLeft;
            const width = activeBtn.offsetWidth;
            const height = activeBtn.offsetHeight;
            const top = activeBtn.offsetTop;

            indicatorRef.current.style.transform = `translateX(${left}px)`;
            indicatorRef.current.style.width = `${width}px`;
            indicatorRef.current.style.height = `${height}px`;
            indicatorRef.current.style.top = `${top}px`;
            indicatorRef.current.style.opacity = '1';
        }
    }, []);

    useEffect(() => {
        const handleThemeChange = (event) => {
            setPreference(event.detail.preference);
            setResolved(event.detail.resolved);
        };

        window.addEventListener('themechange', handleThemeChange);

        return () => {
            window.removeEventListener('themechange', handleThemeChange);
        };
    }, []);

    useEffect(() => {
        updateIndicator();
    }, [preference, updateIndicator]);

    useEffect(() => {
        window.addEventListener('resize', updateIndicator);
        return () => window.removeEventListener('resize', updateIndicator);
    }, [updateIndicator]);

    const options = [
        {
            id: THEME_LIGHT,
            label: 'Light',
            title: 'Light theme',
            icon: (
                <svg className="theme-toggle-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clipRule="evenodd" />
                </svg>
            ),
        },
        {
            id: THEME_DARK,
            label: 'Dark',
            title: 'Dark theme',
            icon: (
                <svg className="theme-toggle-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
                </svg>
            ),
        },
        {
            id: THEME_SYSTEM,
            label: 'Auto',
            title: 'System theme (auto)',
            icon: (
                <svg className="theme-toggle-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2h-2.22l.123.489.804.804A1 1 0 0113 18H7a1 1 0 01-.707-1.707l.804-.804L7.22 15H5a2 2 0 01-2-2V5zm2 0v8h10V5H5z" clipRule="evenodd" />
                </svg>
            ),
        },
    ];

    const handleSelect = (optionId) => {
        themeManager.setPreference(optionId, { animate: true });
    };

    const handleKeyDown = (e, currentIndex) => {
        let nextIndex = -1;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault();
            nextIndex = (currentIndex + 1) % options.length;
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault();
            nextIndex = (currentIndex - 1 + options.length) % options.length;
        } else if (e.key === 'Home') {
            e.preventDefault();
            nextIndex = 0;
        } else if (e.key === 'End') {
            e.preventDefault();
            nextIndex = options.length - 1;
        }

        if (nextIndex >= 0) {
            const nextOption = options[nextIndex];
            handleSelect(nextOption.id);
            const nextBtn = containerRef.current?.querySelectorAll('[data-theme-option]')[nextIndex];
            nextBtn?.focus();
        }
    };

    return (
        <div
            ref={containerRef}
            className={`theme-toggle-segmented ${className}`}
            data-theme-toggle
            data-react="true"
            role="radiogroup"
            aria-label="Color theme selection"
        >
            <span ref={indicatorRef} className="theme-toggle-indicator" data-theme-indicator aria-hidden="true" />
            {options.map((opt, index) => {
                const isSelected = preference === opt.id;
                return (
                    <button
                        key={opt.id}
                        type="button"
                        className={`theme-toggle-btn ${isSelected ? 'is-active' : ''}`}
                        data-theme-option={opt.id}
                        role="radio"
                        aria-checked={isSelected}
                        tabIndex={isSelected ? 0 : -1}
                        title={opt.title}
                        aria-label={opt.title}
                        onClick={() => handleSelect(opt.id)}
                        onKeyDown={(e) => handleKeyDown(e, index)}
                    >
                        {opt.icon}
                        {showLabels && <span className="theme-toggle-label">{opt.label}</span>}
                    </button>
                );
            })}
        </div>
    );
}
