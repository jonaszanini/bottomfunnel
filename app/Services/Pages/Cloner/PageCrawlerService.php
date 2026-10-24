<?php

namespace App\Services\Pages\Cloner;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class PageCrawlerService
{
    /**
     * Common affiliate platform domains and footprint patterns.
     */
    public const AFFILIATE_PLATFORM_PATTERNS = [
        'ClickBank' => [
            'host' => ['clickbank.net', 'hop.clickbank.net', 'ssl.clickbank.net'],
            'regex' => '/\b(clickbank\.net|hop\.clickbank\.net|clkbank\.com)\b/i',
        ],
        'BuyGoods' => [
            'host' => ['buygoods.com'],
            'regex' => '/\b(buygoods\.com)\b/i',
        ],
        'MaxWeb' => [
            'host' => ['maxweb.com', 'mwebtrk.com'],
            'regex' => '/\b(maxweb\.com|mwebtrk\.com)\b/i',
        ],
        'Digistore24' => [
            'host' => ['digistore24.com', 'ds24.io'],
            'regex' => '/\b(digistore24\.com|ds24\.io)\b/i',
        ],
        'Hotmart' => [
            'host' => ['hotmart.com', 'pay.hotmart.com'],
            'regex' => '/\b(hotmart\.com|pay\.hotmart\.com)\b/i',
        ],
        'SmartAdv' => [
            'host' => ['smartadv.com'],
            'regex' => '/\b(smartadv\.com)\b/i',
        ],
    ];

    public const HARD_TIMEOUT_CAP_SECONDS = 30;

    /**
     * Crawl the page using a hybrid architecture:
     * Fast HTTP request first, falling back to headless Chromium for SPAs / dynamic lazy-loading.
     *
     * @return array{
     *     url: string,
     *     finalUrl: string,
     *     html: string,
     *     title: string,
     *     description: string,
     *     image: ?string,
     *     priceRange: ?string,
     *     detectedPlatform: ?string,
     *     ctaLinks: array<int, array{text: string, href: string, isCheckout: bool}>,
     *     renderedVia: string,
     *     stats: array<string, mixed>
     * }
     */
    public function crawl(string $url, bool $forceBrowser = false, int $timeoutSeconds = 20): array
    {
        $url = trim($url);
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Invalid URL format: {$url}");
        }

        // Enforce hard timeout cap to protect shared hosting environments
        $timeoutSeconds = min(max(5, $timeoutSeconds), self::HARD_TIMEOUT_CAP_SECONDS);

        try {
            $html = '';
            $finalUrl = $url;
            $renderedVia = 'http';

            if (! $forceBrowser) {
                try {
                    $httpResult = $this->fetchViaHttp($url, $timeoutSeconds);
                    $html = $httpResult['html'];
                    $finalUrl = $httpResult['finalUrl'];

                    if ($this->isDynamicSpaOrEmpty($html)) {
                        // Page is dynamic SPA or requires JS rendering; trigger headless browser
                        $browserResult = $this->fetchViaBrowser($url, $timeoutSeconds);
                        $html = $browserResult['html'];
                        $finalUrl = $browserResult['finalUrl'] ?: $finalUrl;
                        $renderedVia = $browserResult['renderedVia'] ?: 'browser';
                    }
                } catch (Throwable $e) {
                    // If HTTP failed (e.g. 403 bot block or Cloudflare challenge), attempt headless browser
                    try {
                        $browserResult = $this->fetchViaBrowser($url, $timeoutSeconds);
                        $html = $browserResult['html'];
                        $finalUrl = $browserResult['finalUrl'] ?: $url;
                        $renderedVia = $browserResult['renderedVia'] ?: 'browser';
                    } catch (Throwable $browserError) {
                        throw new RuntimeException("Failed to crawl URL: {$e->getMessage()}. Browser fallback failed: {$browserError->getMessage()}");
                    }
                }
            } else {
                $browserResult = $this->fetchViaBrowser($url, $timeoutSeconds);
                $html = $browserResult['html'];
                $finalUrl = $browserResult['finalUrl'] ?: $url;
                $renderedVia = $browserResult['renderedVia'] ?: 'browser';
            }

            if (trim($html) === '') {
                throw new RuntimeException("The crawler received an empty response from {$url}");
            }

            // Extract metadata & conversion signals
            $metadata = $this->extractMetadata($html, $finalUrl);

            return array_merge([
                'url' => $url,
                'finalUrl' => $finalUrl,
                'html' => $html,
                'renderedVia' => $renderedVia,
            ], $metadata);
        } finally {
            $this->cleanupTempDirectories();
        }
    }

    /**
     * Tier 1: Fast HTTP request with authentic browser headers, SSL verification disabled, & automatic redirect following.
     *
     * @return array{html: string, finalUrl: string, status: int}
     */
    protected function fetchViaHttp(string $url, int $timeoutSeconds): array
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9,pt-BR;q=0.8,pt;q=0.7,es;q=0.6',
            'Sec-Ch-Ua' => '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ];

        // Disable SSL certificate verification ('verify' => false) to prevent cURL error 60
        // on local Windows XAMPP environments and misconfigured vendor SSL certificates.
        $client = Http::withoutVerifying()
            ->withOptions([
                'verify' => false,
                'allow_redirects' => [
                    'max' => 10,
                    'strict' => false,
                    'referer' => true,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true,
                ],
                'curl' => [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ],
            ])
            ->withHeaders($headers)
            ->connectTimeout(min($timeoutSeconds, 5))
            ->timeout($timeoutSeconds);

        $response = $client->retry(1, 100, function (Throwable $exception): bool {
            return true;
        }, throw: false)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("HTTP request returned status code {$response->status()}");
        }

        $finalUrl = (string) $response->effectiveUri();
        if ($finalUrl === '') {
            $finalUrl = $url;
        }

        return [
            'html' => $response->body(),
            'finalUrl' => $finalUrl,
            'status' => $response->status(),
        ];
    }

    /**
     * Tier 2: Headless browser execution using Playwright/Puppeteer script.
     *
     * @return array{html: string, finalUrl: string, renderedVia: string}
     */
    protected function fetchViaBrowser(string $url, int $timeoutSeconds): array
    {
        $scriptPath = __DIR__.'/scripts/browser-crawler.cjs';
        if (! file_exists($scriptPath)) {
            throw new RuntimeException("Headless crawler script not found at {$scriptPath}");
        }

        $optionsJson = json_encode([
            'timeoutMs' => ($timeoutSeconds * 1000),
        ]);

        $command = [
            'node',
            $scriptPath,
            $url,
            $optionsJson,
        ];

        $tempDir = storage_path('app/playwright_temp');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        // Inherit essential Windows environment variables so Node/Playwright never receives 'undefined'
        $systemRoot = getenv('SystemRoot') ?: getenv('WINDIR') ?: 'C:\\Windows';
        $pathEnv = getenv('PATH') ?: ($systemRoot.'\\system32;'.$systemRoot.';'.$systemRoot.'\\System32\\Wbem');
        $userProfile = getenv('USERPROFILE') ?: (getenv('HOMEDRIVE') && getenv('HOMEPATH') ? getenv('HOMEDRIVE').getenv('HOMEPATH') : 'C:\\Users\\jonas');
        $localAppData = getenv('LOCALAPPDATA') ?: ($userProfile ? $userProfile.'\\AppData\\Local' : $tempDir);
        $appData = getenv('APPDATA') ?: ($userProfile ? $userProfile.'\\AppData\\Roaming' : $tempDir);

        $env = [
            'PATH' => $pathEnv,
            'SystemRoot' => $systemRoot,
            'WINDIR' => $systemRoot,
            'TEMP' => $tempDir,
            'TMP' => $tempDir,
            'TMPDIR' => $tempDir,
            'USERPROFILE' => $userProfile,
            'LOCALAPPDATA' => $localAppData,
            'APPDATA' => $appData,
            'PLAYWRIGHT_BROWSERS_PATH' => getenv('PLAYWRIGHT_BROWSERS_PATH') ?: ($localAppData.'\\ms-playwright'),
            'NODE_ENV' => 'production',
        ];

        // Sanitize: filter out null and literal 'undefined' strings
        $sanitizedEnv = [];
        foreach ($env as $k => $v) {
            if ($v !== null && $v !== '' && $v !== 'undefined') {
                $sanitizedEnv[$k] = (string) $v;
            }
        }

        $processResult = Process::env($sanitizedEnv)
            ->timeout($timeoutSeconds + 10)
            ->run($command);

        if (! $processResult->successful()) {
            $err = trim($processResult->errorOutput());
            if ($err === '') {
                $err = trim($processResult->output());
            }

            throw new RuntimeException("Headless browser crawler failed: {$err}");
        }

        $rawOutput = trim($processResult->output());
        $data = json_decode($rawOutput, true);

        if (! is_array($data) || empty($data['html'])) {
            throw new RuntimeException('Invalid JSON payload returned by headless browser crawler.');
        }

        return [
            'html' => $data['html'],
            'finalUrl' => $data['finalUrl'] ?? $url,
            'renderedVia' => $data['renderedVia'] ?? 'browser',
        ];
    }

    /**
     * Check if the fetched HTML looks like an empty client-side SPA or lazy-loaded shell.
     */
    protected function isDynamicSpaOrEmpty(string $html): bool
    {
        $trimmed = trim($html);
        if (mb_strlen($trimmed) < 1200) {
            return true;
        }

        // SPA mounts
        $spaMarkers = [
            '<div id="root"></div>',
            "<div id='root'></div>",
            '<div id="app"></div>',
            "<div id='app'></div>",
            '<div id="__next"></div>',
            '<div id="__nuxt"></div>',
            'You need to enable JavaScript to run this app',
            'Please enable JavaScript to view this website',
        ];

        foreach ($spaMarkers as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }

        // If body has very little visible text despite many script tags
        if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $matches)) {
            $bodyContent = strip_tags($matches[1]);
            $cleanText = preg_replace('/\s+/', ' ', trim($bodyContent));
            if (mb_strlen($cleanText) < 250 && str_contains($html, '<script')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract page metadata, prices, detected platform, and CTA links.
     *
     * @return array{
     *     title: string,
     *     description: string,
     *     image: ?string,
     *     priceRange: ?string,
     *     detectedPlatform: ?string,
     *     ctaLinks: array<int, array{text: string, href: string, isCheckout: bool}>,
     *     stats: array<string, mixed>
     * }
     */
    public function extractMetadata(string $html, string $baseUrl): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        // 1. Title
        $title = '';
        $titleNode = $xpath->query('//title');
        if ($titleNode && $titleNode->length > 0) {
            $title = trim($titleNode->item(0)->textContent);
        }
        if ($title === '') {
            $ogTitleNode = $xpath->query('//meta[@property="og:title"]/@content');
            if ($ogTitleNode && $ogTitleNode->length > 0) {
                $title = trim($ogTitleNode->item(0)->textContent);
            }
        }
        if ($title === '') {
            $h1Node = $xpath->query('//h1');
            if ($h1Node && $h1Node->length > 0) {
                $title = trim($h1Node->item(0)->textContent);
            }
        }

        // 2. Description
        $description = '';
        $metaDescNode = $xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]/@content');
        if ($metaDescNode && $metaDescNode->length > 0) {
            $description = trim($metaDescNode->item(0)->textContent);
        }
        if ($description === '') {
            $ogDescNode = $xpath->query('//meta[@property="og:description"]/@content');
            if ($ogDescNode && $ogDescNode->length > 0) {
                $description = trim($ogDescNode->item(0)->textContent);
            }
        }

        // 3. Image
        $image = null;
        $ogImgNode = $xpath->query('//meta[@property="og:image"]/@content');
        if ($ogImgNode && $ogImgNode->length > 0) {
            $image = trim($ogImgNode->item(0)->textContent);
        }
        if (! $image) {
            $twImgNode = $xpath->query('//meta[@name="twitter:image"]/@content');
            if ($twImgNode && $twImgNode->length > 0) {
                $image = trim($twImgNode->item(0)->textContent);
            }
        }
        if (! $image) {
            $imgNodes = $xpath->query('//img[@src]');
            if ($imgNodes && $imgNodes->length > 0) {
                foreach ($imgNodes as $img) {
                    if ($img instanceof DOMElement) {
                        $src = $img->getAttribute('src');
                        if ($src !== '' && ! str_starts_with($src, 'data:')) {
                            $image = $src;
                            break;
                        }
                    }
                }
            }
        }
        if ($image && ! preg_match('/^https?:\/\//i', $image)) {
            $image = $this->resolveAbsoluteUrl($image, $baseUrl);
        }

        // 4. CTA links & Outbound checkout links
        $ctaLinks = [];
        $detectedPlatform = null;
        $anchorNodes = $xpath->query('//a[@href]');

        $checkoutKeywords = '/(checkout|buy|order|cart|pay|claim|get|special-offer|hop\.clickbank|buygoods|maxweb|digistore24|hotmart)/iu';

        if ($anchorNodes) {
            foreach ($anchorNodes as $a) {
                if ($a instanceof DOMElement) {
                    $rawHref = $a->getAttribute('href');
                    $text = preg_replace('/\s+/', ' ', trim($a->textContent));
                    $absoluteHref = $this->resolveAbsoluteUrl($rawHref, $baseUrl);

                    // Skip empty or fragment links
                    if ($rawHref === '' || str_starts_with($rawHref, '#') || str_starts_with($rawHref, 'javascript:')) {
                        continue;
                    }

                    $isCheckout = false;
                    if (preg_match($checkoutKeywords, $absoluteHref) || preg_match($checkoutKeywords, $text)) {
                        $isCheckout = true;
                    }

                    // Check for affiliate platforms
                    if (! $detectedPlatform) {
                        foreach (self::AFFILIATE_PLATFORM_PATTERNS as $platformName => $def) {
                            if (preg_match($def['regex'], $absoluteHref)) {
                                $detectedPlatform = $platformName;
                                $isCheckout = true;
                                break;
                            }
                        }
                    }

                    $ctaLinks[] = [
                        'text' => mb_substr($text !== '' ? $text : $rawHref, 0, 100),
                        'href' => $absoluteHref,
                        'isCheckout' => $isCheckout,
                    ];
                }
            }
        }

        // 5. Detected platform fallback from entire HTML text / scripts
        if (! $detectedPlatform) {
            foreach (self::AFFILIATE_PLATFORM_PATTERNS as $platformName => $def) {
                if (preg_match($def['regex'], $html)) {
                    $detectedPlatform = $platformName;
                    break;
                }
            }
        }

        // 6. Price range detection
        $priceRange = $this->detectPriceRange($html);

        // Deduplicate CTA links
        $uniqueCtas = [];
        $seenHrefs = [];
        foreach ($ctaLinks as $item) {
            if (! isset($seenHrefs[$item['href']])) {
                $seenHrefs[$item['href']] = true;
                $uniqueCtas[] = $item;
                if (count($uniqueCtas) >= 50) {
                    break;
                }
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'priceRange' => $priceRange,
            'detectedPlatform' => $detectedPlatform,
            'ctaLinks' => $uniqueCtas,
            'stats' => [
                'totalAnchors' => $anchorNodes ? $anchorNodes->length : 0,
                'checkoutLinksCount' => count(array_filter($uniqueCtas, fn ($c) => $c['isCheckout'])),
                'contentLength' => mb_strlen($html),
            ],
        ];
    }

    /**
     * Regex scanner for price signals ($XX.XX, $XX, USD XX).
     */
    protected function detectPriceRange(string $html): ?string
    {
        $prices = [];
        if (preg_match_all('/(?:\$|USD\s*|CAD\s*)([0-9]{1,4}(?:\.[0-9]{2})?)/i', $html, $matches)) {
            foreach ($matches[1] as $p) {
                $val = (float) $p;
                if ($val >= 9.0 && $val <= 1999.0) {
                    $prices[] = $val;
                }
            }
        }

        if (empty($prices)) {
            return null;
        }

        sort($prices);
        $min = min($prices);
        $max = max($prices);

        if ($min === $max) {
            return '$'.number_format($min, 2);
        }

        return '$'.number_format($min, 2).' - $'.number_format($max, 2);
    }

    /**
     * Convert relative URL to absolute based on base URL.
     */
    public function resolveAbsoluteUrl(string $relative, string $baseUrl): string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return $baseUrl;
        }

        // Already absolute or protocol-relative or data URI
        if (preg_match('/^https?:\/\//i', $relative)) {
            return $relative;
        }

        if (str_starts_with($relative, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$relative;
        }

        if (str_starts_with($relative, 'data:') || str_starts_with($relative, 'mailto:') || str_starts_with($relative, 'tel:')) {
            return $relative;
        }

        $baseParsed = parse_url($baseUrl);
        $scheme = $baseParsed['scheme'] ?? 'https';
        $host = $baseParsed['host'] ?? '';
        $port = isset($baseParsed['port']) ? ':'.$baseParsed['port'] : '';
        $basePath = $baseParsed['path'] ?? '/';

        $rootUrl = "{$scheme}://{$host}{$port}";

        // Root-relative
        if (str_starts_with($relative, '/')) {
            return $rootUrl.$relative;
        }

        // Relative path
        $dir = preg_replace('/\/[^\/]*$/', '', $basePath);
        if ($dir === '' || $dir === '/') {
            $dir = '';
        }

        $combined = $dir.'/'.$relative;

        // Resolve ./ and ../
        $segments = explode('/', $combined);
        $resolved = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $seg;
            }
        }

        return $rootUrl.'/'.implode('/', $resolved);
    }

    /**
     * Clean up temporary crawler files and directories to prevent disk exhaustion.
     */
    public function cleanupTempDirectories(?string $dir = null): void
    {
        $targetDir = $dir ?: storage_path('app/playwright_temp');
        if (! is_dir($targetDir)) {
            return;
        }

        try {
            $items = @scandir($targetDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $targetDir.DIRECTORY_SEPARATOR.$item;
                if (is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    $this->deleteDirectoryRecursive($path);
                }
            }
        } catch (Throwable) {
            // Suppress cleanup errors to avoid failing caller pipeline
        }
    }

    /**
     * Recursively delete a directory and its contents.
     */
    protected function deleteDirectoryRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = @scandir($dir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $current = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($current)) {
                $this->deleteDirectoryRecursive($current);
            } else {
                @unlink($current);
            }
        }
        @rmdir($dir);
    }
}
