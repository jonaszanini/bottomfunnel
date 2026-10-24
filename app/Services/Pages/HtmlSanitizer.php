<?php

namespace App\Services\Pages;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class HtmlSanitizer
{
    /**
     * Known third-party tracking scripts and competitor pixel domains.
     *
     * @var list<string>
     */
    public const TRACKING_DOMAIN_PATTERNS = [
        '/googletagmanager\.com/i',
        '/google-analytics\.com/i',
        '/connect\.facebook\.net/i',
        '/fbevents\.js/i',
        '/facebook\.com\/tr/i',
        '/analytics\.tiktok\.com/i',
        '/clarity\.ms/i',
        '/static\.hotjar\.com/i',
        '/hotjar\.com/i',
        '/snap\.licdn\.com/i',
        '/sc-static\.net\/scevent/i',
        '/pintrk/i',
        '/assets\.pinterest\.com/i',
        '/alb\.reddit\.com/i',
        '/trkn\.us/i',
        '/voluumtrk\.com/i',
        '/criteo\.net/i',
        '/outbrain\.com/i',
        '/taboola\.com/i',
        '/yandex\.ru\/metrika/i',
        '/redtrack\.io/i',
        '/bemob\.com/i',
        '/clkmg\.com/i',
        '/clickmagick\.com/i',
    ];

    /**
     * Known inline script signatures for competitor pixels, analytics, or domain locks.
     *
     * @var list<string>
     */
    public const INLINE_TRACKER_SIGNATURES = [
        'fbq(',
        'gtag(',
        'ga(',
        'dataLayer.push',
        'clarity("',
        "clarity('",
        'clarity(',
        'hj(',
        'ttq.load',
        'ttq.page',
        'ttq.track',
        'pintrk(',
        'rdt(',
        'snaptr(',
        'top.location != self.location',
        'top.location.href != window.location.href',
        'top.location!=self.location',
        'window.top.location =',
        'parent.location =',
    ];

    /** @var list<string> */
    private array $allowedTags = [
        'a', 'article', 'blockquote', 'br', 'button', 'details', 'div', 'em', 'footer',
        'h1', 'h2', 'h3', 'header', 'li', 'nav', 'ol', 'p', 'section', 'span', 'strong',
        'summary', 'ul',
    ];

    /** @var list<string> */
    private array $allowedAttributes = [
        'class', 'id', 'href', 'type', 'hidden', 'aria-label', 'data-outbound', 'data-age-gate', 'data-age-confirm', 'data-popup',
    ];

    /**
     * Strict whitelist sanitization for page builder components and structured copy blocks.
     */
    public function sanitize(string $html): string
    {
        // Pre-strip meta-refresh and tracking tags
        $html = $this->preStripForbiddenMetaAndTags($html);

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"><body>'.$html.'</body>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->walk($body);

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Strip third-party tracking scripts, competitor pixels, meta refresh redirects,
     * and affiliate cookie-dropping scripts from full landing pages or raw HTML documents,
     * preserving page layout, styles, and safe content.
     */
    public function stripThirdPartyTrackers(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        // 1. Pre-strip meta refresh redirects & anti-framing meta tags
        $clean = $this->preStripForbiddenMetaAndTags($trimmed);

        $isFullDocument = (bool) preg_match('/<(?:!DOCTYPE|html|head|body)/i', $clean);

        $dom = new DOMDocument;
        $prevErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$clean, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        $xpath = new DOMXPath($dom);

        // 2. Remove meta-refresh tags from DOM if any remain
        $metaRefreshes = $xpath->query('//meta[translate(@http-equiv, "REFSH", "refsh")="refresh"]');
        if ($metaRefreshes) {
            foreach ($metaRefreshes as $meta) {
                $meta->parentNode?->removeChild($meta);
            }
        }

        // 3. Remove third-party and inline tracker scripts
        $scripts = $xpath->query('//script');
        if ($scripts) {
            $toRemove = [];
            foreach ($scripts as $script) {
                if (! $script instanceof DOMElement) {
                    continue;
                }

                $src = $script->getAttribute('src');
                $inline = $script->textContent;

                if ($this->isTrackingUrl($src) || $this->isTrackingScriptContent($inline)) {
                    $toRemove[] = $script;
                }
            }

            foreach ($toRemove as $el) {
                $el->parentNode?->removeChild($el);
            }
        }

        // 4. Remove noscript tracking pixels
        $noscripts = $xpath->query('//noscript');
        if ($noscripts) {
            $noRemove = [];
            foreach ($noscripts as $ns) {
                if ($ns instanceof DOMElement) {
                    $nsHtml = $dom->saveHTML($ns);
                    if ($this->isTrackingScriptContent($nsHtml) || $this->containsTrackingDomain($nsHtml)) {
                        $noRemove[] = $ns;
                    }
                }
            }
            foreach ($noRemove as $ns) {
                $ns->parentNode?->removeChild($ns);
            }
        }

        // 5. Remove tracking pixel images (e.g. 1x1 pixels or external pixel endpoints)
        $imgPixels = $xpath->query('//img[@src]');
        if ($imgPixels) {
            $imgsToRemove = [];
            foreach ($imgPixels as $img) {
                if ($img instanceof DOMElement) {
                    $src = $img->getAttribute('src');
                    if ($this->isTrackingUrl($src)) {
                        $imgsToRemove[] = $img;
                    }
                }
            }
            foreach ($imgsToRemove as $img) {
                $img->parentNode?->removeChild($img);
            }
        }

        // 6. Remove tracking iframes
        $iframes = $xpath->query('//iframe[@src]');
        if ($iframes) {
            $iframesToRemove = [];
            foreach ($iframes as $iframe) {
                if ($iframe instanceof DOMElement) {
                    $src = $iframe->getAttribute('src');
                    if ($this->isTrackingUrl($src)) {
                        $iframesToRemove[] = $iframe;
                    }
                }
            }
            foreach ($iframesToRemove as $iframe) {
                $iframe->parentNode?->removeChild($iframe);
            }
        }

        if ($isFullDocument) {
            return $dom->saveHTML() ?: $clean;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body instanceof DOMElement) {
            $output = '';
            foreach ($body->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }

            return trim($output);
        }

        return $dom->saveHTML() ?: $clean;
    }

    /**
     * Check if a URL matches known tracking or competitor pixel endpoints.
     */
    public function isTrackingUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        foreach (self::TRACKING_DOMAIN_PATTERNS as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if inline script content contains tracking signatures or anti-framing locks.
     */
    public function isTrackingScriptContent(string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            return false;
        }

        foreach (self::INLINE_TRACKER_SIGNATURES as $sig) {
            if (str_contains($content, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if arbitrary HTML contains references to known tracking domains.
     */
    protected function containsTrackingDomain(string $html): bool
    {
        foreach (self::TRACKING_DOMAIN_PATTERNS as $pattern) {
            if (preg_match($pattern, $html)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pre-clean forbidden meta tags (meta-refresh redirects and CSP/anti-framing headers).
     */
    protected function preStripForbiddenMetaAndTags(string $html): string
    {
        // Strip meta-refresh tags (prevents traffic hijacking and cookie overwrites)
        $clean = preg_replace(
            '/<meta[^>]+(?:http-equiv\s*=\s*["\']?refresh["\']?)[^>]*>/iu',
            '',
            $html
        ) ?? $html;

        // Strip anti-framing and CSP meta tags
        return preg_replace(
            '/<meta[^>]+(?:http-equiv\s*=\s*["\']?(?:Content-Security-Policy|X-Frame-Options)["\']?)[^>]*>/iu',
            '',
            $clean
        ) ?? $clean;
    }

    private function walk(DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (! in_array(strtolower($child->tagName), $this->allowedTags, true)) {
                $node->removeChild($child);

                continue;
            }

            $this->cleanAttributes($child);
            $this->walk($child);
        }
    }

    private function cleanAttributes(DOMElement $element): void
    {
        $remove = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if (str_starts_with($name, 'on') || ! in_array($name, $this->allowedAttributes, true)) {
                $remove[] = $attribute->name;

                continue;
            }
            if ($name === 'href' && ! preg_match('/^#[A-Za-z0-9_-]*$/', $attribute->value)) {
                $remove[] = $attribute->name;
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }
    }
}
