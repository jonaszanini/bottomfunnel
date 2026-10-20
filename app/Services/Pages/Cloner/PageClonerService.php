<?php

namespace App\Services\Pages\Cloner;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class PageClonerService
{
    public function __construct(
        protected PageCrawlerService $crawler
    ) {}

    /**
     * Known third party analytics & pixel script signatures to remove.
     */
    protected const TRACKING_SCRIPT_PATTERNS = [
        '/googletagmanager\.com/i',
        '/google-analytics\.com/i',
        '/connect\.facebook\.net/i',
        '/fbevents\.js/i',
        '/analytics\.tiktok\.com/i',
        '/clarity\.ms\/tag/i',
        '/static\.hotjar\.com/i',
        '/snap\.licdn\.com/i',
        '/sc-static\.net\/scevent/i',
        '/trkn\.us/i',
        '/voluumtrk\.com/i',
        '/criteo\.net/i',
        '/outbrain\.com/i',
        '/taboola\.com/i',
        '/yandex\.ru\/metrika/i',
    ];

    /**
     * Known inline script signatures for tracking or domain locks.
     */
    protected const INLINE_TRACKER_SIGNATURES = [
        'fbq(',
        'gtag(',
        'dataLayer.push',
        'clarity("',
        "clarity('",
        'hj(',
        'ttq.load',
        'pintrk(',
        'rdt(',
        'top.location != self.location',
        'top.location.href != window.location.href',
        'top.location!=self.location',
        'window.top.location =',
        'parent.location =',
    ];

    /**
     * Process, rewrite, sanitize, and clone raw HTML into a fully self-contained affiliate landing page.
     *
     * @param  array{
     *     rawHtml: string,
     *     sourceUrl: string,
     *     affiliateLink: string,
     *     customHeadScripts?: ?string,
     *     customBodyScripts?: ?string,
     *     stripTrackers?: bool,
     *     optimizeImages?: bool,
     *     enableParamPassThrough?: bool
     * }  $config
     * @return array{
     *     clonedHtml: string,
     *     stats: array{
     *         assetsRewritten: int,
     *         trackersStripped: int,
     *         affiliateLinksReplaced: int,
     *         lazyImagesPromoted: int,
     *         totalOutboundLinks: int
     *     },
     *     links: array<int, array{
     *         originalUrl: string,
     *         newUrl: string,
     *         anchorText: string,
     *         isAffiliate: bool
     *     }>
     * }
     */
    public function clone(array $config): array
    {
        $rawHtml = $config['rawHtml'];
        $sourceUrl = $config['sourceUrl'];
        $affiliateLink = trim((string) ($config['affiliateLink'] ?? ''));
        $customHead = trim((string) ($config['customHeadScripts'] ?? ''));
        $customBody = trim((string) ($config['customBodyScripts'] ?? ''));
        $stripTrackers = $config['stripTrackers'] ?? true;
        $optimizeImages = $config['optimizeImages'] ?? true;
        $enableParamPassThrough = $config['enableParamPassThrough'] ?? true;

        $stats = [
            'assetsRewritten' => 0,
            'trackersStripped' => 0,
            'affiliateLinksReplaced' => 0,
            'lazyImagesPromoted' => 0,
            'totalOutboundLinks' => 0,
        ];

        $linksInventory = [];

        // Pre-clean: strip anti-framing meta tags
        $cleanHtml = preg_replace(
            '/<meta[^>]+(?:http-equiv\s*=\s*["\']?(?:Content-Security-Policy|X-Frame-Options)["\']?)[^>]*>/iu',
            '',
            $rawHtml
        ) ?? $rawHtml;

        $dom = new DOMDocument;
        $prevErrors = libxml_use_internal_errors(true);
        // Load with UTF-8
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$cleanHtml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        $xpath = new DOMXPath($dom);

        // 1. Strip Tracking Scripts & Anti-Framing scripts
        if ($stripTrackers) {
            $scripts = $xpath->query('//script');
            if ($scripts) {
                $toRemove = [];
                foreach ($scripts as $script) {
                    if (! $script instanceof DOMElement) {
                        continue;
                    }

                    $src = $script->getAttribute('src');
                    $inlineContent = $script->textContent;

                    $shouldRemove = false;

                    // External tracking script detection
                    if ($src !== '') {
                        foreach (self::TRACKING_SCRIPT_PATTERNS as $pattern) {
                            if (preg_match($pattern, $src)) {
                                $shouldRemove = true;
                                break;
                            }
                        }
                    }

                    // Inline tracking or domain lock detection
                    if (! $shouldRemove && $inlineContent !== '') {
                        foreach (self::INLINE_TRACKER_SIGNATURES as $sig) {
                            if (str_contains($inlineContent, $sig)) {
                                $shouldRemove = true;
                                break;
                            }
                        }
                    }

                    if ($shouldRemove) {
                        $toRemove[] = $script;
                    }
                }

                foreach ($toRemove as $el) {
                    $el->parentNode?->removeChild($el);
                    $stats['trackersStripped']++;
                }
            }

            // Also strip noscript tracking pixels (e.g. facebook noscript img)
            $noscripts = $xpath->query('//noscript');
            if ($noscripts) {
                $noRemove = [];
                foreach ($noscripts as $ns) {
                    if ($ns instanceof DOMElement) {
                        $htmlContent = $dom->saveHTML($ns);
                        if (str_contains($htmlContent, 'facebook.com/tr') || str_contains($htmlContent, 'googletagmanager.com')) {
                            $noRemove[] = $ns;
                        }
                    }
                }
                foreach ($noRemove as $ns) {
                    $ns->parentNode?->removeChild($ns);
                    $stats['trackersStripped']++;
                }
            }
        }

        // 2. Smart Asset & Relative Path Resolution
        // A) Links, Stylesheets, Icons, Preloads
        $linkElements = $xpath->query('//link[@href]');
        if ($linkElements) {
            foreach ($linkElements as $link) {
                if ($link instanceof DOMElement) {
                    $rawHref = $link->getAttribute('href');
                    $absHref = $this->crawler->resolveAbsoluteUrl($rawHref, $sourceUrl);
                    if ($absHref !== $rawHref) {
                        $link->setAttribute('href', $absHref);
                        $stats['assetsRewritten']++;
                    }
                }
            }
        }

        // B) Scripts
        $remainingScripts = $xpath->query('//script[@src]');
        if ($remainingScripts) {
            foreach ($remainingScripts as $script) {
                if ($script instanceof DOMElement) {
                    $rawSrc = $script->getAttribute('src');
                    $absSrc = $this->crawler->resolveAbsoluteUrl($rawSrc, $sourceUrl);
                    if ($absSrc !== $rawSrc) {
                        $script->setAttribute('src', $absSrc);
                        $stats['assetsRewritten']++;
                    }
                }
            }
        }

        // C) Images, Source, Videos, Audio, Iframes
        $mediaTags = $xpath->query('//img | //source | //video | //audio | //iframe');
        $isFirstImage = true;

        if ($mediaTags) {
            foreach ($mediaTags as $media) {
                if (! $media instanceof DOMElement) {
                    continue;
                }

                $tag = strtolower($media->tagName);

                // Handle Lazy-loaded attributes (data-src, data-lazy-src, data-original)
                $lazySrc = $media->getAttribute('data-src')
                    ?: $media->getAttribute('data-lazy-src')
                    ?: $media->getAttribute('data-original')
                    ?: $media->getAttribute('data-url');

                $currentSrc = $media->getAttribute('src');

                if ($lazySrc !== '' && ($currentSrc === '' || str_starts_with($currentSrc, 'data:') || str_contains($currentSrc, 'placeholder') || str_contains($currentSrc, 'blank.gif') || str_contains($currentSrc, 'spacer'))) {
                    $media->setAttribute('src', $lazySrc);
                    $stats['lazyImagesPromoted']++;
                }

                $lazySrcset = $media->getAttribute('data-srcset') ?: $media->getAttribute('data-lazy-srcset');
                if ($lazySrcset !== '' && $media->getAttribute('srcset') === '') {
                    $media->setAttribute('srcset', $lazySrcset);
                    $stats['lazyImagesPromoted']++;
                }

                // Resolve src
                if ($media->hasAttribute('src')) {
                    $src = $media->getAttribute('src');
                    if ($src !== '' && ! str_starts_with($src, 'data:')) {
                        $absSrc = $this->crawler->resolveAbsoluteUrl($src, $sourceUrl);
                        if ($absSrc !== $src) {
                            $media->setAttribute('src', $absSrc);
                            $stats['assetsRewritten']++;
                        }
                    }
                }

                // Resolve srcset
                if ($media->hasAttribute('srcset')) {
                    $srcset = $media->getAttribute('srcset');
                    $rewrittenSrcset = $this->rewriteSrcset($srcset, $sourceUrl);
                    if ($rewrittenSrcset !== $srcset) {
                        $media->setAttribute('srcset', $rewrittenSrcset);
                        $stats['assetsRewritten']++;
                    }
                }

                // Resolve poster (for video)
                if ($media->hasAttribute('poster')) {
                    $poster = $media->getAttribute('poster');
                    if ($poster !== '') {
                        $absPoster = $this->crawler->resolveAbsoluteUrl($poster, $sourceUrl);
                        if ($absPoster !== $poster) {
                            $media->setAttribute('poster', $absPoster);
                            $stats['assetsRewritten']++;
                        }
                    }
                }

                // Core Web Vitals optimization on images
                if ($tag === 'img' && $optimizeImages) {
                    if ($isFirstImage) {
                        // Hero image: high fetch priority
                        $media->setAttribute('fetchpriority', 'high');
                        $isFirstImage = false;
                    } else {
                        // Below fold images: lazy & async decoding
                        if (! $media->hasAttribute('loading')) {
                            $media->setAttribute('loading', 'lazy');
                        }
                        if (! $media->hasAttribute('decoding')) {
                            $media->setAttribute('decoding', 'async');
                        }
                    }
                }
            }
        }

        // D) Rewrite CSS url(...) inside <style> tags and inline style attributes
        $styleElements = $xpath->query('//style');
        if ($styleElements) {
            foreach ($styleElements as $styleEl) {
                if ($styleEl instanceof DOMElement) {
                    $css = $styleEl->textContent;
                    $rewrittenCss = $this->rewriteCssUrls($css, $sourceUrl);
                    if ($rewrittenCss !== $css) {
                        $styleEl->textContent = $rewrittenCss;
                        $stats['assetsRewritten']++;
                    }
                }
            }
        }

        $elementsWithStyle = $xpath->query('//*[@style]');
        if ($elementsWithStyle) {
            foreach ($elementsWithStyle as $el) {
                if ($el instanceof DOMElement) {
                    $inlineStyle = $el->getAttribute('style');
                    $rewrittenStyle = $this->rewriteCssUrls($inlineStyle, $sourceUrl);
                    if ($rewrittenStyle !== $inlineStyle) {
                        $el->setAttribute('style', $rewrittenStyle);
                        $stats['assetsRewritten']++;
                    }
                }
            }
        }

        // 3. Automated Affiliate Link Replacement
        $anchors = $xpath->query('//a[@href]');
        $checkoutKeywords = '/(checkout|buy|order|cart|pay|claim|get|special-offer|hop\.clickbank|buygoods|maxweb|digistore24|hotmart)/iu';

        if ($anchors) {
            foreach ($anchors as $a) {
                if (! $a instanceof DOMElement) {
                    continue;
                }

                $rawHref = $a->getAttribute('href');
                $text = preg_replace('/\s+/', ' ', trim($a->textContent));

                // Skip hash anchors and javascript: voids
                if ($rawHref === '' || str_starts_with($rawHref, '#') || str_starts_with($rawHref, 'javascript:')) {
                    continue;
                }

                $absHref = $this->crawler->resolveAbsoluteUrl($rawHref, $sourceUrl);
                $isCheckoutOrAffiliate = false;

                // Check if link points to affiliate platform or checkout
                if (preg_match($checkoutKeywords, $absHref) || preg_match($checkoutKeywords, $text)) {
                    $isCheckoutOrAffiliate = true;
                }

                foreach (PageCrawlerService::AFFILIATE_PLATFORM_PATTERNS as $def) {
                    if (preg_match($def['regex'], $absHref)) {
                        $isCheckoutOrAffiliate = true;
                        break;
                    }
                }

                $newUrl = $absHref;
                if ($isCheckoutOrAffiliate && $affiliateLink !== '') {
                    $newUrl = $affiliateLink;
                    $a->setAttribute('href', $newUrl);
                    $a->setAttribute('data-flowhub-affiliate', 'true');
                    $a->setAttribute('target', '_blank');
                    $a->setAttribute('rel', 'noopener noreferrer');
                    $stats['affiliateLinksReplaced']++;
                } else {
                    $a->setAttribute('href', $absHref);
                    $stats['assetsRewritten']++;
                }

                $stats['totalOutboundLinks']++;
                $linksInventory[] = [
                    'originalUrl' => $absHref,
                    'newUrl' => $newUrl,
                    'anchorText' => mb_substr($text !== '' ? $text : $rawHref, 0, 100),
                    'isAffiliate' => $isCheckoutOrAffiliate && $affiliateLink !== '',
                ];
            }
        }

        // Also check form action urls
        $forms = $xpath->query('//form[@action]');
        if ($forms) {
            foreach ($forms as $form) {
                if ($form instanceof DOMElement) {
                    $action = $form->getAttribute('action');
                    if ($action !== '' && ! str_starts_with($action, '#')) {
                        $absAction = $this->crawler->resolveAbsoluteUrl($action, $sourceUrl);
                        if ($affiliateLink !== '' && preg_match($checkoutKeywords, $absAction)) {
                            $form->setAttribute('action', $affiliateLink);
                            $stats['affiliateLinksReplaced']++;
                        } else {
                            $form->setAttribute('action', $absAction);
                        }
                    }
                }
            }
        }

        // 4. Ensure Responsive Viewport Meta
        $viewportMeta = $xpath->query('//meta[@name="viewport"]');
        if (! $viewportMeta || $viewportMeta->length === 0) {
            $head = $dom->getElementsByTagName('head')->item(0);
            if ($head) {
                $meta = $dom->createElement('meta');
                $meta->setAttribute('name', 'viewport');
                $meta->setAttribute('content', 'width=device-width, initial-scale=1');
                $head->insertBefore($meta, $head->firstChild);
            }
        }

        // Convert DOM back to HTML string
        $finalHtml = $dom->saveHTML();

        // 5. Injections: Custom Head, Custom Body, and Parameter Pass-Through Script
        $paramPassThroughScript = $enableParamPassThrough
            ? $this->generateParamPassThroughScript($affiliateLink)
            : '';

        if ($customHead !== '') {
            $finalHtml = str_ireplace('</head>', $customHead."\n</head>", $finalHtml);
        }

        $bodyInjections = '';
        if ($customBody !== '') {
            $bodyInjections .= "\n".$customBody;
        }
        if ($paramPassThroughScript !== '') {
            $bodyInjections .= "\n".$paramPassThroughScript;
        }

        if ($bodyInjections !== '') {
            if (stripos($finalHtml, '</body>') !== false) {
                $finalHtml = str_ireplace('</body>', $bodyInjections."\n</body>", $finalHtml);
            } else {
                $finalHtml .= $bodyInjections;
            }
        }

        return [
            'clonedHtml' => $finalHtml,
            'stats' => $stats,
            'links' => $linksInventory,
        ];
    }

    /**
     * Rewrite multiple URLs inside an HTML5 `srcset` attribute.
     */
    protected function rewriteSrcset(string $srcset, string $baseUrl): string
    {
        $parts = explode(',', $srcset);
        $rewrittenParts = [];

        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }

            // A candidate looks like "image-320w.jpg 320w" or "image-2x.jpg 2x" or just "image.jpg"
            if (preg_match('/^(\S+)(\s+.+)?$/', $trimmed, $matches)) {
                $url = $matches[1];
                $descriptor = $matches[2] ?? '';
                $absUrl = $this->crawler->resolveAbsoluteUrl($url, $baseUrl);
                $rewrittenParts[] = $absUrl.$descriptor;
            } else {
                $rewrittenParts[] = $trimmed;
            }
        }

        return implode(', ', $rewrittenParts);
    }

    /**
     * Rewrite CSS `url(...)` and `@import` statements to absolute paths.
     */
    protected function rewriteCssUrls(string $css, string $baseUrl): string
    {
        // 1. Rewrite url(...)
        $css = preg_replace_callback('/url\(\s*(["\']?)([^)"\']+)\1\s*\)/i', function ($matches) use ($baseUrl) {
            $quote = $matches[1];
            $url = trim($matches[2]);

            if (str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
                return $matches[0];
            }

            $abs = $this->crawler->resolveAbsoluteUrl($url, $baseUrl);

            return "url({$quote}{$abs}{$quote})";
        }, $css) ?? $css;

        // 2. Rewrite @import "..."
        $css = preg_replace_callback('/@import\s+(["\'])([^"\']+)\1/i', function ($matches) use ($baseUrl) {
            $quote = $matches[1];
            $url = trim($matches[2]);
            $abs = $this->crawler->resolveAbsoluteUrl($url, $baseUrl);

            return "@import {$quote}{$abs}{$quote}";
        }, $css) ?? $css;

        return $css;
    }

    /**
     * Micro-script injected before </body> for parameter forwarding (UTMs, GCLID, WBRAID, GBRAID, SubIDs).
     */
    protected function generateParamPassThroughScript(string $fallbackAffiliateUrl): string
    {
        return <<<'HTML'
<!-- FlowHub Inbound Attribution & Parameter Pass-Through Engine -->
<script>
(function() {
    function getQueryParams() {
        var params = {};
        var search = window.location.search.substring(1);
        if (!search) return params;
        var pairs = search.split('&');
        for (var i = 0; i < pairs.length; i++) {
            var pair = pairs[i].split('=');
            if (pair[0]) {
                params[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1] || '');
            }
        }
        return params;
    }

    function appendParams(url, params) {
        if (!url || Object.keys(params).length === 0) return url;
        var hashParts = url.split('#');
        var baseWithQuery = hashParts[0];
        var hash = hashParts[1] ? '#' + hashParts[1] : '';

        var queryParts = baseWithQuery.split('?');
        var baseUrl = queryParts[0];
        var existingQuery = queryParts[1] || '';

        var urlParams = new URLSearchParams(existingQuery);

        // Tracking keys to preserve and forward
        var keys = [
            'gclid', 'wbraid', 'gbraid',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'tid', 'sid', 'subid', 'subid1', 'subid2', 'subid3', 'subid4', 'subid5'
        ];

        for (var k in params) {
            if (params.hasOwnProperty(k)) {
                var lowerKey = k.toLowerCase();
                // Pass through recognized tracking params or any param starting with utm_ or sub
                if (keys.indexOf(lowerKey) !== -1 || lowerKey.indexOf('utm_') === 0 || lowerKey.indexOf('sub') === 0) {
                    urlParams.set(k, params[k]);
                }
            }
        }

        var newQuery = urlParams.toString();
        return baseUrl + (newQuery ? '?' + newQuery : '') + hash;
    }

    function syncAffiliateLinks() {
        var incomingParams = getQueryParams();
        if (Object.keys(incomingParams).length === 0) return;

        var links = document.querySelectorAll('a[data-flowhub-affiliate="true"], a[href*="clickbank.net"], a[href*="buygoods.com"], a[href*="maxweb.com"], a[href*="digistore24.com"], a[href*="hotmart.com"]');
        for (var i = 0; i < links.length; i++) {
            var a = links[i];
            var currentHref = a.getAttribute('href');
            if (currentHref && currentHref !== '#') {
                a.setAttribute('href', appendParams(currentHref, incomingParams));
            }
        }
    }

    // Intercept click on any checkout/affiliate link for real-time parameter pass-through
    document.addEventListener('click', function(e) {
        var target = e.target.closest('a');
        if (!target) return;
        var href = target.getAttribute('href');
        if (!href || href === '#' || href.indexOf('javascript:') === 0) return;

        if (target.getAttribute('data-flowhub-affiliate') === 'true' || 
            href.indexOf('clickbank.net') !== -1 ||
            href.indexOf('buygoods.com') !== -1 ||
            href.indexOf('maxweb.com') !== -1 ||
            href.indexOf('digistore24.com') !== -1 ||
            href.indexOf('hotmart.com') !== -1) {
            var incomingParams = getQueryParams();
            target.setAttribute('href', appendParams(href, incomingParams));
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncAffiliateLinks);
    } else {
        syncAffiliateLinks();
    }
})();
</script>
HTML;
    }
}
