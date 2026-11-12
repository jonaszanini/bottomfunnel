import React, { useEffect, useId, useRef, useState } from 'react';

export function VolumeNoDataBadge({ className = '' }) {
    return (
        <span
            className={`inline-flex shrink-0 items-center rounded-md border border-amber-500/40 bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-400 ${className}`}
        >
            No data
        </span>
    );
}

export function hasVolumeNoData(product) {
    return Boolean(product?.score_flags?.volume_no_data);
}

export function formatVolumeSubscore(v) {
    if (v === null || v === undefined || Number.isNaN(Number(v))) {
        return '0.00';
    }

    return Number(v).toFixed(2);
}

export default function ScoreFormulaInfo({ align = 'left', className = '' }) {
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);
    const titleId = useId();

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onPointer = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointer);
        document.addEventListener('touchstart', onPointer);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('touchstart', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const alignmentClass = align === 'right' ? 'right-0' : 'left-0';

    return (
        <div ref={rootRef} className={`relative inline-flex ${className}`} data-score-formula-info>
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                className="inline-flex items-center justify-center w-4 h-4 rounded-full border border-theme text-[10px] leading-none text-theme-muted hover:text-theme-accent cursor-pointer"
                aria-label="How the potential score is calculated"
                aria-expanded={open}
                aria-controls={open ? titleId : undefined}
                title="Score formula"
            >
                ⓘ
            </button>
            {open && (
                <div
                    id={titleId}
                    role="dialog"
                    className={`absolute ${alignmentClass} top-full mt-1 z-40 w-72 max-w-[calc(100vw-2rem)] rounded-lg border border-theme bg-theme-surface p-3 text-[11px] text-theme-secondary shadow-xl space-y-1.5`}
                >
                    <div className="flex items-start justify-between gap-2">
                        <span className="font-semibold text-theme-primary">Potential score formula</span>
                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            className="text-theme-muted hover:text-theme-primary cursor-pointer text-xs leading-none"
                            aria-label="Close formula"
                        >
                            ✕
                        </button>
                    </div>
                    <p>
                        Q is the nine-component quality score after hard gates. V (0–10) is monthly Google search volume via log interpolation (10→0, 150→2.5, 1000→8, 2000→9, 5000→10).
                    </p>
                    <p>
                        w = 0.15 + 0.035×V. VI = 11.25×V when V≤8, else 90 + 5×(V−8). S = (1−w)×Q + w×VI.
                    </p>
                    <p>
                        Caps: false BoF 0; other hard gates 20; restricted 50; no volume data 69; V&lt;8 → 89. Test when score &gt; 90.
                    </p>
                </div>
            )}
        </div>
    );
}
