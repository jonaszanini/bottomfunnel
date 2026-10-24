<?php

namespace App\Services\Pages;

class HtmlDocument
{
    protected HtmlSanitizer $sanitizer;

    public function __construct(?HtmlSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?: app(HtmlSanitizer::class);
    }

    public function wrap(string $body, string $title, string $script = ''): string
    {
        $safeTitle = e($title);
        // Strip third-party tracking scripts, competitor pixels, and meta-refresh from body before wrapping
        $cleanBody = $this->sanitizer->stripThirdPartyTrackers($body);
        // First-party TrackScript is appended after sanitization so tracking remains intact
        $scriptBlock = trim($script) === '' ? '' : "\n".$script;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle}</title>
<style>
body{margin:0;font-family:Georgia,serif;color:#1c1917;background:#faf7f2}
.wrap{max-width:760px;margin:0 auto;padding:32px 20px 80px}
h1,h2{font-family:"Segoe UI",sans-serif;line-height:1.2}
h1{font-size:2.2rem;margin:0 0 12px}
.sub{font-size:1.15rem;color:#44403c}
.nav-buttons{display:flex;flex-wrap:wrap;gap:8px;margin:20px 0}
.nav-buttons a{text-decoration:none;color:#1c1917;border:1px solid #d6d3d1;border-radius:999px;padding:8px 14px;font-family:"Segoe UI",sans-serif}
.proof{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:24px 0}
.proof div{background:#fff;border:1px solid #e7e5e4;border-radius:12px;padding:14px}
.proof strong{display:block;font-size:1.2rem}
.block{margin:28px 0}
.benefits{padding-left:18px}
blockquote{margin:24px 0;padding:16px 18px;background:#fff;border-left:4px solid #0f766e}
button{font-family:"Segoe UI",sans-serif;background:#0f766e;color:#fff;border:0;border-radius:999px;padding:12px 18px;font-size:1rem;cursor:pointer}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:28px}
.gate,.popup{position:fixed;inset:0;background:rgba(28,25,23,.72);display:flex;align-items:center;justify-content:center;padding:20px;z-index:20}
.gate-card,.popup-card{background:#fff;max-width:420px;border-radius:16px;padding:24px}
.popup{z-index:30}
</style>
</head>
<body>
<main class="wrap">
{$cleanBody}
</main>{$scriptBlock}
</body>
</html>
HTML;
    }
}
