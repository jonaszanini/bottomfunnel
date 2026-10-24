const fs = require('fs');
const path = require('path');
const os = require('os');
const dns = require('dns');
const { promisify } = require('util');

const dnsLookup = promisify(dns.lookup);
const dnsCache = new Map();

// Ensure a dedicated, guaranteed writable temporary directory inside the project
const projectRoot = path.resolve(__dirname, '../../../../../');
let customTempDir = path.join(projectRoot, 'storage', 'app', 'playwright_temp');

try {
    fs.mkdirSync(customTempDir, { recursive: true });
} catch (e) {
    customTempDir = path.join(__dirname, '.temp');
    try {
        fs.mkdirSync(customTempDir, { recursive: true });
    } catch (err) {}
}

// Explicitly populate missing Windows and Node environment variables before Playwright initializes
process.env.TEMP = customTempDir;
process.env.TMP = customTempDir;
process.env.TMPDIR = customTempDir;

const userHome = os.homedir() || 'C:\\Users\\Default';
if (!process.env.USERPROFILE || process.env.USERPROFILE === 'undefined') {
    process.env.USERPROFILE = userHome;
}

if (!process.env.LOCALAPPDATA || process.env.LOCALAPPDATA === 'undefined') {
    process.env.LOCALAPPDATA = path.join(userHome, 'AppData', 'Local');
}

if (!process.env.APPDATA || process.env.APPDATA === 'undefined') {
    process.env.APPDATA = path.join(userHome, 'AppData', 'Roaming');
}

// Chromium launch arguments optimized for CloudLinux shared hosting (cPanel LVE memory limits)
const cloudLinuxChromiumArgs = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-software-rasterizer',
    '--no-zygote',
    '--disable-extensions',
    '--disable-background-networking',
    '--disable-background-timer-throttling',
    '--disable-backgrounding-occluded-windows',
    '--disable-breakpad',
    '--disable-client-side-phishing-detection',
    '--disable-component-update',
    '--disable-default-apps',
    '--disable-domain-reliability',
    '--disable-features=AudioServiceOutOfProcess,IsolateOrigins,site-per-process,Translate,BackForwardCache,AcceptCHFrame,MediaRouter',
    '--disable-hang-monitor',
    '--disable-ipc-flooding-protection',
    '--disable-popup-blocking',
    '--disable-prompt-on-repost',
    '--disable-renderer-backgrounding',
    '--disable-sync',
    '--force-color-profile=srgb',
    '--metrics-recording-only',
    '--no-first-run',
    '--password-store=basic',
    '--use-mock-keychain',
    '--export-tagged-pdf',
    '--js-flags=--max-old-space-size=256',
    '--ignore-certificate-errors',
];

/**
 * Check if an IP address falls into private, loopback, link-local, carrier-grade NAT, or reserved ranges.
 */
function isPrivateOrReservedIp(ip) {
    if (!ip) return true;
    if (ip.startsWith('::ffff:')) {
        ip = ip.substring(7);
    }

    const parts = ip.split('.').map(Number);
    if (parts.length === 4 && parts.every(n => !isNaN(n) && n >= 0 && n <= 255)) {
        const [a, b, c] = parts;
        if (a === 0) return true; // 0.0.0.0/8
        if (a === 10) return true; // 10.0.0.0/8
        if (a === 127) return true; // 127.0.0.0/8 (loopback)
        if (a === 169 && b === 254) return true; // 169.254.0.0/16 (link local, metadata)
        if (a === 172 && (b >= 16 && b <= 31)) return true; // 172.16.0.0/12
        if (a === 192 && b === 168) return true; // 192.168.0.0/16
        if (a === 100 && (b >= 64 && b <= 127)) return true; // 100.64.0.0/10 (CGNAT)
        if (a === 192 && b === 0 && c === 2) return true; // TEST-NET-1
        if (a === 198 && b === 51 && c === 100) return true; // TEST-NET-2
        if (a === 203 && b === 0 && c === 113) return true; // TEST-NET-3
        if (a >= 224) return true; // Multicast & Reserved
        return false;
    }

    const lower = ip.toLowerCase();
    if (lower === '::1' || lower === '::' || lower === '0:0:0:0:0:0:0:1') return true;
    if (lower.startsWith('fc') || lower.startsWith('fd')) return true; // IPv6 unique local
    if (lower.startsWith('fe8') || lower.startsWith('fe9') || lower.startsWith('fea') || lower.startsWith('feb')) return true; // Link local
    return false;
}

/**
 * Validate URL security against SSRF vulnerabilities (rejects private IPs and non-http schemes).
 */
async function isSafeUrl(rawUrl) {
    let parsed;
    try {
        parsed = new URL(rawUrl);
    } catch (e) {
        return false;
    }

    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return false;
    }

    const host = parsed.hostname.toLowerCase();
    if (!host || host === 'localhost' || host === '0.0.0.0' || host.endsWith('.localhost') || host.endsWith('.local') || host.endsWith('.internal') || host.endsWith('.lan') || host.endsWith('.corp')) {
        return false;
    }

    if (isPrivateOrReservedIp(host)) {
        return false;
    }

    if (dnsCache.has(host)) {
        return dnsCache.get(host);
    }

    try {
        const addresses = await dnsLookup(host, { all: true });
        for (const addr of addresses) {
            if (isPrivateOrReservedIp(addr.address)) {
                dnsCache.set(host, false);
                return false;
            }
        }
        dnsCache.set(host, true);
        return true;
    } catch (err) {
        dnsCache.set(host, false);
        return false;
    }
}

// Resolve Playwright or Puppeteer
function resolveBrowserEngine() {
    // 1. Try local playwright
    try {
        const pw = require('playwright');
        return { type: 'playwright', engine: pw };
    } catch (e) {}

    // 2. Try neighbor playwright (ZaniniRenovations)
    try {
        const pw = require('c:/xampp/htdocs/ZaniniRenovations/node_modules/playwright');
        return { type: 'playwright', engine: pw };
    } catch (e) {}

    // 3. Try local puppeteer
    try {
        const pt = require('puppeteer');
        return { type: 'puppeteer', engine: pt };
    } catch (e) {}

    // 4. Try neighbor puppeteer
    try {
        const pt = require('c:/xampp/htdocs/ZaniniRenovations/node_modules/puppeteer');
        return { type: 'puppeteer', engine: pt };
    } catch (e) {}

    return null;
}

async function run() {
    const rawArgs = process.argv.slice(2);
    if (!rawArgs.length) {
        console.error(JSON.stringify({ error: 'No arguments provided. Usage: node browser-crawler.cjs <url> [optionsJson]' }));
        process.exit(1);
    }

    const targetUrl = rawArgs[0];
    let options = {};
    if (rawArgs[1]) {
        try {
            options = JSON.parse(rawArgs[1]);
        } catch (e) {
            options = {};
        }
    }

    // Enforce strict execution timeout (capped at 25 seconds for shared hosting)
    const timeoutMs = Math.min(options.timeoutMs || 25000, 25000);
    const engineInfo = resolveBrowserEngine();

    if (!engineInfo) {
        console.error(JSON.stringify({
            error: 'No headless browser engine (Playwright or Puppeteer) could be resolved.',
            fallbackToHttp: true
        }));
        process.exit(1);
    }

    // SSRF pre-check on target URL
    const targetIsSafe = await isSafeUrl(targetUrl);
    if (!targetIsSafe) {
        console.error(JSON.stringify({
            success: false,
            error: `SSRF blocked: Disallowed or private target URL: ${targetUrl}`
        }));
        process.exit(1);
    }

    const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    let browser = null;
    let context = null;
    let tempUserDataDir = null;

    try {
        tempUserDataDir = path.join(customTempDir, `run_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`);
        try {
            fs.mkdirSync(tempUserDataDir, { recursive: true });
        } catch (e) {}

        if (engineInfo.type === 'playwright') {
            const { chromium } = engineInfo.engine;
            browser = await chromium.launch({
                headless: true,
                tracesDir: tempUserDataDir,
                args: cloudLinuxChromiumArgs
            });

            context = await browser.newContext({
                userAgent: userAgent,
                viewport: { width: 1280, height: 900 },
                locale: 'en-US',
                ignoreHTTPSErrors: true,
                extraHTTPHeaders: {
                    'Accept-Language': 'en-US,en;q=0.9,pt-BR;q=0.8,pt;q=0.7',
                    'Sec-Ch-Ua': '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
                    'Sec-Ch-Ua-Mobile': '?0',
                    'Sec-Ch-Ua-Platform': '"Windows"',
                }
            });

            // Route interception to block private IP ranges & SSRF attacks
            await context.route('**/*', async (route) => {
                const reqUrl = route.request().url();
                if (reqUrl.startsWith('data:')) {
                    return route.continue();
                }

                const safe = await isSafeUrl(reqUrl);
                if (!safe) {
                    return route.abort('blockedbyclient');
                }
                return route.continue();
            });

            const page = await context.newPage();
            page.setDefaultTimeout(timeoutMs);

            let finalUrl = targetUrl;
            let responseStatus = 200;

            const response = await page.goto(targetUrl, {
                waitUntil: 'domcontentloaded',
                timeout: timeoutMs
            });

            if (response) {
                responseStatus = response.status();
                finalUrl = response.url() || targetUrl;
            }

            // Simulate smooth scrolling to bottom to trigger lazy loaded images and scripts
            await page.evaluate(async () => {
                await new Promise((resolve) => {
                    let totalHeight = 0;
                    const distance = 400;
                    const timer = setInterval(() => {
                        const scrollHeight = document.body.scrollHeight;
                        window.scrollBy(0, distance);
                        totalHeight += distance;

                        if (totalHeight >= scrollHeight || totalHeight > 15000) {
                            clearInterval(timer);
                            window.scrollTo(0, 0);
                            resolve();
                        }
                    }, 100);
                });
            });

            // Brief wait for any final DOM updates triggered by scroll
            await page.waitForTimeout(1000);

            // Extract page details from live DOM
            const pageData = await page.evaluate(() => {
                const title = document.title || '';
                const descEl = document.querySelector('meta[name="description"]') || document.querySelector('meta[property="og:description"]');
                const metaDescription = descEl ? descEl.getAttribute('content') || '' : '';

                const imgEl = document.querySelector('meta[property="og:image"]') || document.querySelector('meta[name="twitter:image"]');
                let ogImage = imgEl ? imgEl.getAttribute('content') || '' : '';

                if (!ogImage) {
                    const firstImg = document.querySelector('img[src]');
                    if (firstImg) {
                        ogImage = firstImg.src || '';
                    }
                }

                // Extract all outbound links
                const links = [];
                document.querySelectorAll('a[href]').forEach(a => {
                    const href = a.getAttribute('href');
                    const text = (a.innerText || a.textContent || '').trim();
                    if (href && !href.startsWith('#') && !href.startsWith('javascript:')) {
                        links.push({ href: a.href, rawHref: href, text: text.slice(0, 100) });
                    }
                });

                return {
                    title,
                    metaDescription,
                    ogImage,
                    outboundLinks: links.slice(0, 50),
                    html: document.documentElement.outerHTML
                };
            });

            const result = {
                success: true,
                url: targetUrl,
                finalUrl: finalUrl,
                status: responseStatus,
                title: pageData.title,
                metaDescription: pageData.metaDescription,
                ogImage: pageData.ogImage,
                outboundLinks: pageData.outboundLinks,
                html: pageData.html,
                renderedVia: 'playwright'
            };

            process.stdout.write(JSON.stringify(result));
            process.exit(0);
        } else {
            // Puppeteer fallback
            const puppeteer = engineInfo.engine;
            browser = await puppeteer.launch({
                headless: 'new',
                ignoreHTTPSErrors: true,
                userDataDir: tempUserDataDir,
                args: cloudLinuxChromiumArgs
            });

            const page = await browser.newPage();
            page.setDefaultTimeout(timeoutMs);
            await page.setUserAgent(userAgent);
            await page.setViewport({ width: 1280, height: 900 });

            // Route interception to block private IP ranges & SSRF attacks
            await page.setRequestInterception(true);
            page.on('request', async (req) => {
                const reqUrl = req.url();
                if (reqUrl.startsWith('data:')) {
                    return req.continue();
                }

                const safe = await isSafeUrl(reqUrl);
                if (!safe) {
                    return req.abort('blockedbyclient');
                }
                return req.continue();
            });

            const response = await page.goto(targetUrl, {
                waitUntil: 'domcontentloaded',
                timeout: timeoutMs
            });

            const finalUrl = page.url() || targetUrl;
            const responseStatus = response ? response.status() : 200;

            await page.evaluate(async () => {
                await new Promise((resolve) => {
                    let totalHeight = 0;
                    const distance = 400;
                    const timer = setInterval(() => {
                        const scrollHeight = document.body.scrollHeight;
                        window.scrollBy(0, distance);
                        totalHeight += distance;

                        if (totalHeight >= scrollHeight || totalHeight > 15000) {
                            clearInterval(timer);
                            window.scrollTo(0, 0);
                            resolve();
                        }
                    }, 100);
                });
            });

            await new Promise(r => setTimeout(r, 1000));

            const pageData = await page.evaluate(() => {
                const title = document.title || '';
                const descEl = document.querySelector('meta[name="description"]') || document.querySelector('meta[property="og:description"]');
                const metaDescription = descEl ? descEl.getAttribute('content') || '' : '';

                const imgEl = document.querySelector('meta[property="og:image"]') || document.querySelector('meta[name="twitter:image"]');
                const ogImage = imgEl ? imgEl.getAttribute('content') || '' : '';

                const links = [];
                document.querySelectorAll('a[href]').forEach(a => {
                    const href = a.getAttribute('href');
                    const text = (a.innerText || a.textContent || '').trim();
                    if (href && !href.startsWith('#') && !href.startsWith('javascript:')) {
                        links.push({ href: a.href, rawHref: href, text: text.slice(0, 100) });
                    }
                });

                return {
                    title,
                    metaDescription,
                    ogImage,
                    outboundLinks: links.slice(0, 50),
                    html: document.documentElement.outerHTML
                };
            });

            const result = {
                success: true,
                url: targetUrl,
                finalUrl: finalUrl,
                status: responseStatus,
                title: pageData.title,
                metaDescription: pageData.metaDescription,
                ogImage: pageData.ogImage,
                outboundLinks: pageData.outboundLinks,
                html: pageData.html,
                renderedVia: 'puppeteer'
            };

            process.stdout.write(JSON.stringify(result));
            process.exit(0);
        }
    } catch (err) {
        console.error(JSON.stringify({
            success: false,
            error: err.message,
            stack: err.stack
        }));
        process.exit(1);
    } finally {
        if (context) {
            try {
                await context.close();
            } catch (e) {}
        }
        if (browser) {
            try {
                await browser.close();
            } catch (e) {}
        }
        if (tempUserDataDir && fs.existsSync(tempUserDataDir)) {
            try {
                fs.rmSync(tempUserDataDir, { recursive: true, force: true });
            } catch (e) {}
        }
    }
}

run();
