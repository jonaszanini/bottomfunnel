const fs = require('fs');
const path = require('path');

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

    const timeoutMs = options.timeoutMs || 25000;
    const engineInfo = resolveBrowserEngine();

    if (!engineInfo) {
        console.error(JSON.stringify({
            error: 'No headless browser engine (Playwright or Puppeteer) could be resolved.',
            fallbackToHttp: true
        }));
        process.exit(1);
    }

    const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    try {
        if (engineInfo.type === 'playwright') {
            const { chromium } = engineInfo.engine;
            const browser = await chromium.launch({
                headless: true,
                args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
            });

            const context = await browser.newContext({
                userAgent: userAgent,
                viewport: { width: 1280, height: 900 },
                locale: 'en-US',
                extraHTTPHeaders: {
                    'Accept-Language': 'en-US,en;q=0.9,pt-BR;q=0.8,pt;q=0.7',
                    'Sec-Ch-Ua': '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
                    'Sec-Ch-Ua-Mobile': '?0',
                    'Sec-Ch-Ua-Platform': '"Windows"',
                }
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
                            // Scroll back to top
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

            await browser.close();

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
            const browser = await puppeteer.launch({
                headless: 'new',
                args: ['--no-sandbox', '--disable-setuid-sandbox']
            });

            const page = await browser.newPage();
            await page.setUserAgent(userAgent);
            await page.setViewport({ width: 1280, height: 900 });

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

            await browser.close();

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
    }
}

run();
