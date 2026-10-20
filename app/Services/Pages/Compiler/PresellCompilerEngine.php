<?php

namespace App\Services\Pages\Compiler;

use App\Support\CopyGuard;

class PresellCompilerEngine
{
    /**
     * Compile a variation and its overrides into a standalone, single-file HTML5 document.
     *
     * @param  array<string, mixed>  $variation
     */
    public function compile(array $variation): string
    {
        $hash = $this->generateUniqueHash();
        $p = 'fp_'.$hash.'_';

        $productName = trim((string) ($variation['productName'] ?? 'Product Offer'));
        $language = strtolower(trim((string) ($variation['language'] ?? 'en')));
        $affiliateUrl = trim((string) ($variation['affiliateUrl'] ?? '#'));
        $strategyType = (string) ($variation['strategyType'] ?? 'flash_sale');
        $seoEnabled = (bool) ($variation['seoEnabled'] ?? false);
        $trackingCode = (string) ($variation['trackingCode'] ?? '');

        // Extract theme tokens
        $theme = $variation['themeConfig'] ?? [];
        $primaryColor = $theme['primaryColor'] ?? '#0f766e';
        $accentColor = $theme['accentColor'] ?? '#14b8a6';
        $bgColor = $theme['bgColor'] ?? '#f8fafc';
        $surfaceColor = $theme['surfaceColor'] ?? '#ffffff';
        $textColor = $theme['textColor'] ?? '#0f172a';
        $mutedColor = $theme['mutedColor'] ?? '#64748b';
        $fontStack = $theme['fontStack'] ?? 'system-ui, -apple-system, sans-serif';
        $radius = $theme['radius'] ?? '8px';

        // Extract editable parameters with fallback defaults
        $params = $variation['editableParams'] ?? [];
        $behaviorType = (string) ($params['behaviorType'] ?? 'special_discount');
        $headline = CopyGuard::clean((string) ($params['headline'] ?? $productName));
        $subheadline = CopyGuard::clean((string) ($params['subheadline'] ?? ''));
        $ctaText = CopyGuard::clean((string) ($params['ctaText'] ?? 'Claim Offer Now'));
        $discountPercentage = CopyGuard::clean((string) ($params['discountPercentage'] ?? '50% OFF'));
        $timerMinutes = max(1, (int) ($params['timerMinutes'] ?? 11));
        $features = $params['features'] ?? [];
        $socialProof = CopyGuard::clean((string) ($params['socialProof'] ?? ''));

        // SEO metadata
        $seo = $variation['seoMeta'] ?? [];
        $metaTitle = e($seo['title'] ?? ($productName.' - Official Verification & Regional Access'));
        $metaDescription = e($seo['description'] ?? ('Official presell verification and special promotional conditions for '.$productName.'. Fast priority dispatch.'));
        $metaKeywords = e($seo['keywords'] ?? ($productName.', official offer, review, discount, verification'));

        // Build Strategy Specific Content and Behavior
        $bodyHtml = $this->renderStrategyBody(
            $strategyType,
            $behaviorType,
            $p,
            $productName,
            $language,
            $headline,
            $subheadline,
            $ctaText,
            $discountPercentage,
            $features,
            $socialProof,
            $timerMinutes
        );

        // Build Modals and Overlays (Cookie Consent, Exit Intent, Discount Banner)
        $overlaysHtml = $this->renderOverlays(
            $strategyType,
            $behaviorType,
            $p,
            $productName,
            $language,
            $ctaText,
            $discountPercentage,
            $timerMinutes
        );

        // Build Inline CSS
        $css = $this->buildInlineCss($p, $primaryColor, $accentColor, $bgColor, $surfaceColor, $textColor, $mutedColor, $fontStack, $radius);

        // Build Client Vanilla JavaScript (Countdown, Outbound Attribution, Exit Intent, Modal Handling)
        $js = $this->buildVanillaJs($p, $affiliateUrl, $timerMinutes, $behaviorType, $strategyType);

        // Build SEO Tags if enabled
        $seoTags = '';
        if ($seoEnabled) {
            $seoTags = <<<HTML
<title>{$metaTitle}</title>
<meta name="description" content="{$metaDescription}">
<meta name="keywords" content="{$metaKeywords}">
<meta name="robots" content="index, follow">
<meta property="og:title" content="{$metaTitle}">
<meta property="og:description" content="{$metaDescription}">
<meta property="og:type" content="website">
<link rel="canonical" href="./">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "{$productName}",
  "description": "{$metaDescription}",
  "offers": {
    "@type": "AggregateOffer",
    "priceCurrency": "USD",
    "availability": "https://schema.org/InStock"
  }
}
</script>
HTML;
        } else {
            $seoTags = "<title>{$metaTitle}</title>\n<meta name=\"robots\" content=\"noindex, nofollow\">";
        }

        // Clean user tracking code
        $headTracking = '';
        $bodyTracking = '';
        if ($trackingCode !== '') {
            if (str_contains($trackingCode, '<noscript')) {
                // Separate script and noscript if present
                $headTracking = preg_replace('/<noscript.*?<\/noscript>/is', '', $trackingCode);
                preg_match('/<noscript.*?<\/noscript>/is', $trackingCode, $matches);
                $bodyTracking = $matches[0] ?? '';
            } else {
                $headTracking = $trackingCode;
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="{$language}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
{$seoTags}
<style>
{$css}
</style>
{$headTracking}
</head>
<body class="{$p}body">
{$bodyTracking}
{$bodyHtml}
{$overlaysHtml}
<script>
{$js}
</script>
</body>
</html>
HTML;
    }

    /**
     * Generate a unique 6-character anti-footprint hash prefix.
     */
    protected function generateUniqueHash(): string
    {
        return substr(md5(uniqid((string) mt_rand(), true)), 0, 6);
    }

    /**
     * Render the specific body HTML structure for the strategy.
     */
    protected function renderStrategyBody(
        string $strategy,
        string $behavior,
        string $p,
        string $productName,
        string $lang,
        string $headline,
        string $subheadline,
        string $ctaText,
        string $discount,
        array $features,
        string $socialProof,
        int $timerMinutes
    ): string {
        switch ($strategy) {
            case 'country_select':
                return $this->renderCountrySelectBody($p, $productName, $headline, $subheadline, $ctaText, $discount, $lang);

            case 'cookie_popup':
                return $this->renderCookiePopupBody($p, $productName, $headline, $subheadline, $ctaText, $discount, $features, $socialProof);

            case 'ai_express':
                return $this->renderAiExpressBody($p, $productName, $headline, $subheadline, $ctaText, $discount, $features, $socialProof, $timerMinutes);

            case 'flash_sale':
            default:
                return $this->renderFlashSaleBody($p, $productName, $headline, $subheadline, $ctaText, $discount, $features, $socialProof, $timerMinutes);
        }
    }

    protected function renderFlashSaleBody(
        string $p,
        string $productName,
        string $headline,
        string $subheadline,
        string $ctaText,
        string $discount,
        array $features,
        string $socialProof,
        int $timerMinutes
    ): string {
        $featureList = '';
        if (empty($features)) {
            $features = [
                '100% Authentic Manufacturer Sourcing',
                'Priority Express Regional Dispatch with Tracking',
                'Risk-Free 60-Day Satisfaction Evaluation',
                'Encrypted 256-Bit SSL Checkout Security',
            ];
        }
        foreach ($features as $f) {
            $featureList .= '<li class="'.$p.'feature_item"><span class="'.$p.'check">✓</span> '.e($f).'</li>';
        }

        $proofHtml = $socialProof !== ''
            ? '<div class="'.$p.'social_proof">★ ★ ★ ★ ★ <span class="'.$p.'proof_text">'.e($socialProof).'</span></div>'
            : '<div class="'.$p.'social_proof">★ ★ ★ ★ ★ <span class="'.$p.'proof_text">Rated 4.9/5 by 14,800+ Verified Buyers</span></div>';

        return <<<HTML
<div class="{$p}banner">
    <div class="{$p}container {$p}banner_flex">
        <span class="{$p}urgent_badge">LIMITED TIME</span>
        <span>Special Promotion Concludes In: <strong class="{$p}timer_display">11:00</strong></span>
        <span class="{$p}banner_discount">{$discount}</span>
    </div>
</div>

<main class="{$p}main_wrap">
    <div class="{$p}container">
        <header class="{$p}header">
            <span class="{$p}pill_category">OFFICIAL CONSUMER ADVISORY</span>
            <h1 class="{$p}main_headline">{$headline}</h1>
            <p class="{$p}sub_headline">{$subheadline}</p>
            {$proofHtml}
        </header>

        <section class="{$p}card {$p}hero_card">
            <div class="{$p}grid_2col">
                <div class="{$p}content_col">
                    <div class="{$p}deal_tag">VERIFIED BATCH &bull; EXCLUSIVE ACCESS</div>
                    <h2 class="{$p}card_title">Authentic {$productName} Direct Allocation</h2>
                    <p class="{$p}text">
                        Due to high demand and strict regional quotas, promotional allocations are released on a first-come, first-served basis. Secure your order through the authorized warehouse below.
                    </p>
                    <ul class="{$p}feature_list">
                        {$featureList}
                    </ul>
                </div>
                <div class="{$p}action_col">
                    <div class="{$p}cta_box">
                        <div class="{$p}badge_discount">{$discount}</div>
                        <div class="{$p}cta_note">Promotional Conditions Applied</div>
                        <button type="button" class="{$p}btn {$p}btn_primary {$p}btn_pulse" data-outbound="1">
                            {$ctaText} &rarr;
                        </button>
                        <div class="{$p}guarantee_text">🔒 256-Bit Secure Official Checkout</div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="{$p}footer">
            <p class="{$p}footer_text">
                &copy; All Rights Reserved. This independent review page provides consumer information and directs to the manufacturer official store.
            </p>
        </footer>
    </div>
</main>
HTML;
    }

    protected function renderCountrySelectBody(
        string $p,
        string $productName,
        string $headline,
        string $subheadline,
        string $ctaText,
        string $discount,
        string $lang
    ): string {
        $countries = [
            ['code' => 'US', 'name' => 'United States', 'flag' => '🇺🇸'],
            ['code' => 'CA', 'name' => 'Canada', 'flag' => '🇨🇦'],
            ['code' => 'GB', 'name' => 'United Kingdom', 'flag' => '🇬🇧'],
            ['code' => 'AU', 'name' => 'Australia', 'flag' => '🇦🇺'],
            ['code' => 'NZ', 'name' => 'New Zealand', 'flag' => '🇳🇿'],
            ['code' => 'INTL', 'name' => 'Other / International', 'flag' => '🌍'],
        ];

        if ($lang === 'pt') {
            $countries = [
                ['code' => 'BR', 'name' => 'Brasil', 'flag' => '🇧🇷'],
                ['code' => 'PT', 'name' => 'Portugal', 'flag' => '🇵🇹'],
                ['code' => 'US', 'name' => 'Estados Unidos', 'flag' => '🇺🇸'],
                ['code' => 'AO', 'name' => 'Angola', 'flag' => '🇦🇴'],
                ['code' => 'MZ', 'name' => 'Moçambique', 'flag' => '🇲🇿'],
                ['code' => 'INTL', 'name' => 'Outro País', 'flag' => '🌍'],
            ];
        } elseif ($lang === 'es') {
            $countries = [
                ['code' => 'ES', 'name' => 'España', 'flag' => '🇪🇸'],
                ['code' => 'MX', 'name' => 'México', 'flag' => '🇲🇽'],
                ['code' => 'US', 'name' => 'Estados Unidos', 'flag' => '🇺🇸'],
                ['code' => 'CO', 'name' => 'Colombia', 'flag' => '🇨🇴'],
                ['code' => 'AR', 'name' => 'Argentina', 'flag' => '🇦🇷'],
                ['code' => 'INTL', 'name' => 'Otro País', 'flag' => '🌍'],
            ];
        } elseif ($lang === 'de') {
            $countries = [
                ['code' => 'DE', 'name' => 'Deutschland', 'flag' => '🇩🇪'],
                ['code' => 'AT', 'name' => 'Österreich', 'flag' => '🇦🇹'],
                ['code' => 'CH', 'name' => 'Schweiz', 'flag' => '🇨🇭'],
                ['code' => 'US', 'name' => 'Vereinigte Staaten', 'flag' => '🇺🇸'],
                ['code' => 'UK', 'name' => 'Vereinigtes Königreich', 'flag' => '🇬🇧'],
                ['code' => 'INTL', 'name' => 'Anderes Land', 'flag' => '🌍'],
            ];
        }

        $countryButtons = '';
        foreach ($countries as $c) {
            $countryButtons .= <<<HTML
<button type="button" class="{$p}country_btn" data-country="{$c['code']}" data-outbound="1">
    <span class="{$p}flag">{$c['flag']}</span>
    <span class="{$p}country_name">{$c['name']}</span>
    <span class="{$p}stock_pill">In Stock</span>
</button>
HTML;
        }

        return <<<HTML
<main class="{$p}main_wrap {$p}center_layout">
    <div class="{$p}container {$p}narrow">
        <header class="{$p}header {$p}text_center">
            <span class="{$p}pill_category">REGIONAL INVENTORY ALLOCATION</span>
            <h1 class="{$p}main_headline">{$headline}</h1>
            <p class="{$p}sub_headline">{$subheadline}</p>
        </header>

        <section class="{$p}card {$p}country_card">
            <div class="{$p}card_header">
                <span class="{$p}step_dot">1</span>
                <strong>Select your destination region to confirm stock and unlock {$discount}:</strong>
            </div>

            <div class="{$p}country_grid">
                {$countryButtons}
            </div>

            <div id="{$p}selection_status" class="{$p}status_msg" style="display:none;">
                <div class="{$p}spinner"></div>
                <span>Verifying regional allocation and unlocking priority discount...</span>
            </div>
        </section>

        <footer class="{$p}footer {$p}text_center">
            <div class="{$p}trust_row">
                <span>🔒 Secure SSL</span> &bull; 
                <span>⚡ Priority Dispatch</span> &bull; 
                <span>✓ Authorized {$productName} Gateway</span>
            </div>
        </footer>
    </div>
</main>
HTML;
    }

    protected function renderCookiePopupBody(
        string $p,
        string $productName,
        string $headline,
        string $subheadline,
        string $ctaText,
        string $discount,
        array $features,
        string $socialProof
    ): string {
        $featureList = '';
        if (empty($features)) {
            $features = [
                'Direct-to-consumer authorized distributor portal',
                'Official manufacturer warranty & satisfaction guarantee',
                'Special conditions with priority warehouse dispatch',
            ];
        }
        foreach ($features as $f) {
            $featureList .= '<li class="'.$p.'feature_item">✓ '.e($f).'</li>';
        }

        return <<<HTML
<main class="{$p}main_wrap">
    <div class="{$p}container">
        <header class="{$p}header">
            <span class="{$p}pill_category">OFFICIAL PORTAL ACCESS</span>
            <h1 class="{$p}main_headline">{$headline}</h1>
            <p class="{$p}sub_headline">{$subheadline}</p>
        </header>

        <section class="{$p}card">
            <div class="{$p}preview_content">
                <h2>Product Overview & Specifications</h2>
                <p>
                    {$productName} is distributed strictly through authorized distribution networks to prevent counterfeiting and ensure customer safety. Complete verified details below:
                </p>
                <ul class="{$p}feature_list">
                    {$featureList}
                </ul>
            </div>
            <div class="{$p}action_center">
                <button type="button" class="{$p}btn {$p}btn_primary" data-outbound="1">
                    {$ctaText} &rarr;
                </button>
            </div>
        </section>
    </div>
</main>
HTML;
    }

    protected function renderAiExpressBody(
        string $p,
        string $productName,
        string $headline,
        string $subheadline,
        string $ctaText,
        string $discount,
        array $features,
        string $socialProof,
        int $timerMinutes
    ): string {
        $featureCards = '';
        if (empty($features)) {
            $features = [
                'Rapid Efficacy Profile' => 'Clinically verified results observed within the initial 14 to 21 days of consistent usage.',
                'Certified Purity Standards' => 'Manufactured in registered, GMP-compliant facilities adhering to rigorous quality protocols.',
                'Risk-Free Protection' => 'Backed by an unconditional manufacturer guarantee ensuring total customer confidence.',
            ];
        }
        foreach ($features as $title => $desc) {
            $cardTitle = is_string($title) ? $title : 'Core Advantage';
            $cardDesc = is_string($desc) ? $desc : (string) $title;
            $featureCards .= <<<HTML
<div class="{$p}card {$p}benefit_box">
    <h3 class="{$p}benefit_title">{$cardTitle}</h3>
    <p class="{$p}benefit_desc">{$cardDesc}</p>
</div>
HTML;
        }

        return <<<HTML
<div class="{$p}editorial_bar">
    <div class="{$p}container {$p}flex_between">
        <span>Verified Consumer Review &bull; Updated for 2026</span>
        <span>Rating: ★★★★★ <strong>4.9 / 5.0</strong></span>
    </div>
</div>

<main class="{$p}main_wrap">
    <div class="{$p}container">
        <header class="{$p}header">
            <div class="{$p}meta_line">
                <span class="{$p}badge_verified">VERIFIED EVALUATION</span>
                <span class="{$p}badge_date">Published: October 2026</span>
            </div>
            <h1 class="{$p}main_headline">{$headline}</h1>
            <p class="{$p}sub_headline">{$subheadline}</p>
        </header>

        <section class="{$p}summary_box">
            <h2 class="{$p}summary_title">The Executive Summary:</h2>
            <p class="{$p}summary_text">
                After evaluating independent testing reports and verified user outcomes, {$productName} demonstrates high performance and reliability. To protect buyers from third-party counterfeits, direct ordering via the official supplier below is recommended.
            </p>
            <div class="{$p}summary_cta">
                <span class="{$p}deal_pill">{$discount}</span>
                <button type="button" class="{$p}btn {$p}btn_primary" data-outbound="1">
                    {$ctaText} &rarr;
                </button>
            </div>
        </section>

        <section class="{$p}grid_3col">
            {$featureCards}
        </section>

        <section class="{$p}sticky_cta">
            <div class="{$p}container {$p}flex_between">
                <div>
                    <strong>{$productName} Official Access</strong>
                    <div class="{$p}text_muted">Special conditions ending soon</div>
                </div>
                <button type="button" class="{$p}btn {$p}btn_primary" data-outbound="1">
                    {$ctaText}
                </button>
            </div>
        </section>
    </div>
</main>
HTML;
    }

    /**
     * Render Modals, Overlays, and Exit Intent Dialogs.
     */
    protected function renderOverlays(
        string $strategy,
        string $behavior,
        string $p,
        string $productName,
        string $lang,
        string $ctaText,
        string $discount,
        int $timerMinutes
    ): string {
        $overlays = '';

        // Strategy 1: Cookie Popup Modal
        if ($strategy === 'cookie_popup' || $behavior === 'cookie_wall') {
            $modalTitle = $lang === 'pt' ? 'Aviso de Privacidade e Acesso Exclusivo' : 'Privacy Notice & Exclusive Access';
            $modalBody = $lang === 'pt'
                ? "Este portal utiliza cookies de verificação para garantir sua elegibilidade ao desconto especial de {$discount} para o {$productName}. Clique abaixo para aceitar e acessar a página oficial."
                : "This official portal uses verification cookies to validate your regional eligibility for the {$discount} promotion on {$productName}. Click below to confirm and proceed.";
            $acceptBtn = $lang === 'pt' ? 'Aceitar e Acessar Oferta' : 'Confirm & Proceed to Official Store';

            $overlays .= <<<HTML
<div id="{$p}cookie_modal" class="{$p}modal_overlay">
    <div class="{$p}modal_box">
        <div class="{$p}modal_header">
            <span class="{$p}modal_icon">🔒</span>
            <h3 class="{$p}modal_title">{$modalTitle}</h3>
        </div>
        <p class="{$p}modal_body">{$modalBody}</p>
        <div class="{$p}modal_actions">
            <button type="button" class="{$p}btn {$p}btn_primary" data-outbound="1">
                {$acceptBtn} &rarr;
            </button>
        </div>
    </div>
</div>
HTML;
        }

        // Exit Intent Modal (for exit_intent or flash_sale)
        if ($behavior === 'exit_intent' || $strategy === 'flash_sale') {
            $exitTitle = $lang === 'pt' ? 'ESPERE! Não Perca Sua Condição Especial' : 'WAIT! Do Not Miss Your Exclusive Access';
            $exitBody = $lang === 'pt'
                ? "Sua reserva com {$discount} para o {$productName} expira quando você fechar esta aba. Deseja aplicar o cupom oficial agora?"
                : "Your {$discount} reservation on {$productName} will expire if you leave this session. Would you like to claim your verified coupon now?";
            $exitBtn = $lang === 'pt' ? 'Sim, Aplicar Desconto Agora' : 'Yes, Claim My Verified Discount';

            $overlays .= <<<HTML
<div id="{$p}exit_modal" class="{$p}modal_overlay" style="display:none;">
    <div class="{$p}modal_box {$p}exit_box">
        <button type="button" class="{$p}close_btn" onclick="document.getElementById('{$p}exit_modal').style.display='none';">&times;</button>
        <div class="{$p}exit_badge">PROMOTIONAL RESERVATION</div>
        <h3 class="{$p}modal_title">{$exitTitle}</h3>
        <p class="{$p}modal_body">{$exitBody}</p>
        <div class="{$p}timer_row">
            <span>Coupon Expires in:</span>
            <strong class="{$p}timer_display">{$timerMinutes}:00</strong>
        </div>
        <div class="{$p}modal_actions">
            <button type="button" class="{$p}btn {$p}btn_primary {$p}btn_pulse" data-outbound="1">
                {$exitBtn} &rarr;
            </button>
        </div>
    </div>
</div>
HTML;
        }

        return $overlays;
    }

    /**
     * Build self-contained, inline, minified CSS.
     */
    protected function buildInlineCss(
        string $p,
        string $primary,
        string $accent,
        string $bg,
        string $surface,
        string $text,
        string $muted,
        string $font,
        string $radius
    ): string {
        return <<<CSS
*,:after,:before{box-sizing:border-box;margin:0;padding:0}
body.{$p}body{font-family:{$font};background-color:{$bg};color:{$text};line-height:1.55;-webkit-font-smoothing:antialiased}
.{$p}container{width:100%;max-width:960px;margin:0 auto;padding:0 16px}
.{$p}narrow{max-width:680px}
.{$p}banner{background:{$primary};color:#fff;padding:8px 0;font-size:13px;font-weight:600;text-align:center}
.{$p}banner_flex{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap}
.{$p}urgent_badge{background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px;font-size:11px;letter-spacing:1px}
.{$p}banner_discount{background:#f59e0b;color:#000;padding:2px 8px;border-radius:4px;font-weight:700}
.{$p}main_wrap{padding:40px 0 60px}
.{$p}center_layout{display:flex;align-items:center;min-height:80vh}
.{$p}header{margin-bottom:32px}
.{$p}text_center{text-align:center}
.{$p}pill_category{display:inline-block;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:1px;background:rgba(15,118,110,.1);color:{$primary};margin-bottom:12px}
.{$p}main_headline{font-size:2.2rem;font-weight:800;line-height:1.2;color:{$text};margin-bottom:12px}
.{$p}sub_headline{font-size:1.15rem;color:{$muted};max-width:760px}
.{$p}social_proof{display:flex;align-items:center;gap:8px;color:#f59e0b;font-size:14px;font-weight:600;margin-top:12px}
.{$p}proof_text{color:{$muted};font-size:13px;font-weight:500}
.{$p}card{background:{$surface};border:1px solid rgba(0,0,0,.08);border-radius:{$radius};padding:28px;box-shadow:0 4px 6px -1px rgba(0,0,0,.05),0 2px 4px -2px rgba(0,0,0,.05);margin-bottom:24px}
.{$p}grid_2col{display:grid;grid-template-columns:1fr;gap:24px}
@media(min-width:768px){.{$p}grid_2col{grid-template-columns:3fr 2fr}}
.{$p}deal_tag{font-size:11px;font-weight:700;color:{$primary};letter-spacing:1px;margin-bottom:6px}
.{$p}card_title{font-size:1.4rem;font-weight:700;margin-bottom:12px}
.{$p}text{color:{$muted};font-size:15px;margin-bottom:16px}
.{$p}feature_list{list-style:none;display:flex;flex-direction:column;gap:10px}
.{$p}feature_item{display:flex;align-items:flex-start;gap:8px;font-size:14px;color:{$text}}
.{$p}check{color:{$primary};font-weight:800}
.{$p}action_col{display:flex;align-items:center;justify-content:center}
.{$p}cta_box{background:{$bg};border:1px solid rgba(0,0,0,.06);border-radius:{$radius};padding:24px;text-align:center;width:100%}
.{$p}badge_discount{display:inline-block;background:#ef4444;color:#fff;font-size:16px;font-weight:800;padding:4px 14px;border-radius:999px;margin-bottom:8px}
.{$p}cta_note{font-size:12px;color:{$muted};margin-bottom:16px}
.{$p}btn{display:inline-flex;align-items:center;justify-content:center;padding:14px 28px;font-size:16px;font-weight:700;border-radius:{$radius};border:none;cursor:pointer;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease;width:100%}
.{$p}btn:hover{transform:translateY(-1px);box-shadow:0 6px 12px -2px rgba(0,0,0,.15)}
.{$p}btn:active{transform:translateY(0)}
.{$p}btn_primary{background:{$primary};color:#fff}
.{$p}btn_pulse{animation:{$p}pulse 2s infinite}
@keyframes {$p}pulse{0%{box-shadow:0 0 0 0 rgba(15,118,110,.5)}70%{box-shadow:0 0 0 12px rgba(15,118,110,0)}100%{box-shadow:0 0 0 0 rgba(15,118,110,0)}}
.{$p}guarantee_text{font-size:12px;color:{$muted};margin-top:10px}
.{$p}footer{padding-top:24px;text-align:center;font-size:12px;color:{$muted}}
.{$p}country_grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}
@media(max-width:540px){.{$p}country_grid{grid-template-columns:1fr}}
.{$p}country_btn{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:{$bg};border:1px solid rgba(0,0,0,.1);border-radius:{$radius};cursor:pointer;font-size:15px;font-weight:600;color:{$text};transition:all .15s ease}
.{$p}country_btn:hover{border-color:{$primary};background:#fff;transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,0,0,.08)}
.{$p}flag{font-size:20px;margin-right:8px}
.{$p}stock_pill{font-size:11px;font-weight:700;color:#16a34a;background:rgba(22,163,74,.1);padding:2px 6px;border-radius:4px}
.{$p}status_msg{margin-top:16px;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:{$radius};display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600}
.{$p}spinner{width:16px;height:16px;border:2px solid #bbf7d0;border-top-color:#16a34a;border-radius:50%;animation:{$p}spin .8s linear infinite}
@keyframes {$p}spin{to{transform:rotate(360deg)}}
.{$p}modal_overlay{position:fixed;inset:0;background:rgba(15,23,42,.75);backdrop-filter:blur(3px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}
.{$p}modal_box{background:#fff;border-radius:{$radius};max-width:480px;width:100%;padding:28px;box-shadow:0 20px 25px -5px rgba(0,0,0,.2);position:relative}
.{$p}modal_header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.{$p}modal_icon{font-size:24px}
.{$p}modal_title{font-size:1.25rem;font-weight:700;color:#0f172a}
.{$p}modal_body{font-size:14px;color:#475569;margin-bottom:20px;line-height:1.5}
.{$p}close_btn{position:absolute;top:12px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#94a3b8}
.{$p}close_btn:hover{color:#0f172a}
.{$p}exit_badge{display:inline-block;font-size:11px;font-weight:800;background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:4px;margin-bottom:8px}
.{$p}timer_row{display:flex;align-items:center;justify-content:space-between;background:#f8fafc;padding:8px 12px;border-radius:4px;font-size:13px;font-weight:600;margin-bottom:16px}
.{$p}editorial_bar{background:#0f172a;color:#94a3b8;font-size:12px;padding:6px 0}
.{$p}flex_between{display:flex;align-items:center;justify-content:space-between}
.{$p}badge_verified{background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;margin-right:8px}
.{$p}badge_date{font-size:12px;color:{$muted}}
.{$p}summary_box{background:#f8fafc;border-left:4px solid {$primary};padding:20px;border-radius:4px;margin-bottom:28px}
.{$p}summary_title{font-size:1.1rem;font-weight:700;margin-bottom:8px}
.{$p}summary_text{font-size:14px;color:{$muted};margin-bottom:16px}
.{$p}summary_cta{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.{$p}deal_pill{background:#ef4444;color:#fff;font-size:12px;font-weight:700;padding:4px 10px;border-radius:4px}
.{$p}grid_3col{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-bottom:40px}
.{$p}benefit_box{padding:20px}
.{$p}benefit_title{font-size:1.1rem;font-weight:700;margin-bottom:6px}
.{$p}benefit_desc{font-size:13px;color:{$muted}}
.{$p}sticky_cta{position:sticky;bottom:16px;background:{$surface};border:1px solid rgba(0,0,0,.1);box-shadow:0 10px 15px -3px rgba(0,0,0,.1);border-radius:{$radius};padding:12px 20px;margin-top:40px}
CSS;
    }

    /**
     * Build policy-compliant vanilla JavaScript for tracking, countdown, modal, and outbound binding.
     */
    protected function buildVanillaJs(
        string $p,
        string $affiliateUrl,
        int $timerMinutes,
        string $behavior,
        string $strategy
    ): string {
        $encodedUrl = json_encode($affiliateUrl, JSON_UNESCAPED_SLASHES);
        $totalSeconds = $timerMinutes * 60;

        return <<<JS
(function() {
    var rawAffiliateUrl = {$encodedUrl};
    var timerKey = "{$p}t_" + window.location.pathname.replace(/[^a-zA-Z0-9]/g, "");
    var totalSeconds = {$totalSeconds};

    // Forward incoming query params (gclid, wbraid, gbraid, utm_*) to outbound affiliate link
    function getAttributedUrl(base) {
        if (!base || base === "#" || base.indexOf("http") !== 0) return base;
        try {
            var url = new URL(base);
            var currentParams = new URLSearchParams(window.location.search);
            var forwardKeys = ["gclid", "wbraid", "gbraid", "utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term", "tid", "subid", "sid", "subid1"];
            forwardKeys.forEach(function(k) {
                if (currentParams.has(k)) {
                    url.searchParams.set(k, currentParams.get(k));
                }
            });
            return url.toString();
        } catch(e) {
            return base;
        }
    }

    function navigateOutbound(customUrl) {
        var destination = getAttributedUrl(customUrl || rawAffiliateUrl);
        if (destination && destination !== "#") {
            window.location.href = destination;
        }
    }

    // 1. Scarcity Countdown Timer
    function initTimer() {
        var endTime = null;
        try {
            var stored = sessionStorage.getItem(timerKey);
            if (stored && Number(stored) > Date.now()) {
                endTime = Number(stored);
            }
        } catch(e) {}

        if (!endTime) {
            endTime = Date.now() + (totalSeconds * 1000);
            try { sessionStorage.setItem(timerKey, endTime.toString()); } catch(e) {}
        }

        function tick() {
            var remaining = Math.max(0, Math.floor((endTime - Date.now()) / 1000));
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            var str = (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
            var displays = document.querySelectorAll(".{$p}timer_display");
            displays.forEach(function(el) { el.textContent = str; });
            if (remaining > 0) {
                setTimeout(tick, 1000);
            }
        }
        tick();
    }
    initTimer();

    // 2. Outbound Action Button Binding
    document.addEventListener("DOMContentLoaded", function() {
        var outboundEls = document.querySelectorAll("[data-outbound]");
        outboundEls.forEach(function(el) {
            el.addEventListener("click", function(e) {
                e.preventDefault();
                var countryCode = el.getAttribute("data-country");
                if (countryCode) {
                    var status = document.getElementById("{$p}selection_status");
                    if (status) status.style.display = "flex";
                    setTimeout(function() {
                        navigateOutbound();
                    }, 500);
                    return;
                }
                navigateOutbound();
            });
        });
    });

    // 3. Exit Intent Detection
    var exitModal = document.getElementById("{$p}exit_modal");
    if (exitModal) {
        var hasTriggered = false;
        document.addEventListener("mouseleave", function(e) {
            if (e.clientY <= 10 && !hasTriggered) {
                hasTriggered = true;
                exitModal.style.display = "flex";
            }
        });
    }
})();
JS;
    }
}
