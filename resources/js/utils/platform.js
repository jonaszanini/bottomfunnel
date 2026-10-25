/**
 * Extract normalized lowercase host from raw URL string.
 */
export function extractHost(rawUrl) {
    if (!rawUrl || typeof rawUrl !== 'string') return '';
    try {
        let trimmed = rawUrl.trim();
        if (!trimmed) return '';
        if (!/^https?:\/\//i.test(trimmed)) {
            trimmed = 'https://' + trimmed;
        }
        const parsed = new URL(trimmed);
        return (parsed.hostname || '').toLowerCase();
    } catch {
        return '';
    }
}

/**
 * Infer platform name from sales_page_url or affiliate_link domain.
 *
 * Rules:
 * - clickbank.net / hop.clickbank.net / *.hop.clickbank.net / clickbank.com -> ClickBank
 * - digistore24.com -> Digistore24
 * - buygoods.com -> BuyGoods
 * - maxweb.com -> MaxWeb
 * - affplus.com -> Affplus
 * - smartadv -> SmartAdv
 */
export function inferPlatformFromUrl(url) {
    const host = extractHost(url);
    if (!host) return null;

    if (
        host === 'clickbank.net' ||
        host.endsWith('.clickbank.net') ||
        host === 'clickbank.com' ||
        host.endsWith('.clickbank.com')
    ) {
        return 'ClickBank';
    }

    if (host === 'digistore24.com' || host.endsWith('.digistore24.com')) {
        return 'Digistore24';
    }

    if (host === 'buygoods.com' || host.endsWith('.buygoods.com')) {
        return 'BuyGoods';
    }

    if (host === 'maxweb.com' || host.endsWith('.maxweb.com')) {
        return 'MaxWeb';
    }

    if (host === 'affplus.com' || host.endsWith('.affplus.com')) {
        return 'Affplus';
    }

    if (host.includes('smartadv')) {
        return 'SmartAdv';
    }

    return null;
}

/**
 * Return subtle, consistent badge classes for each platform.
 * 'Unknown' is gray.
 */
export function getPlatformBadgeClass(platform) {
    switch (platform) {
        case 'ClickBank':
            return 'bg-rose-500/10 text-rose-600 border-rose-500/20 dark:text-rose-400';
        case 'Digistore24':
            return 'bg-sky-500/10 text-sky-600 border-sky-500/20 dark:text-sky-400';
        case 'BuyGoods':
            return 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 dark:text-emerald-400';
        case 'MaxWeb':
            return 'bg-amber-500/10 text-amber-600 border-amber-500/20 dark:text-amber-400';
        case 'SmartAdv':
            return 'bg-purple-500/10 text-purple-600 border-purple-500/20 dark:text-purple-400';
        case 'Affplus':
            return 'bg-indigo-500/10 text-indigo-600 border-indigo-500/20 dark:text-indigo-400';
        case 'Affplus (ClickHunts)':
            return 'bg-teal-500/10 text-teal-600 border-teal-500/20 dark:text-teal-400';
        case 'Affplus (WW)':
            return 'bg-cyan-500/10 text-cyan-600 border-cyan-500/20 dark:text-cyan-400';
        case 'Other':
            return 'bg-zinc-500/10 text-zinc-600 border-zinc-500/20 dark:text-zinc-300';
        case 'Unknown':
        default:
            return 'bg-zinc-400/10 text-zinc-500 border-zinc-400/20 dark:text-zinc-400';
    }
}
