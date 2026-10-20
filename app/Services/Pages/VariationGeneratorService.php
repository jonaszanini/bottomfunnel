<?php

namespace App\Services\Pages;

use App\Services\Pages\Compiler\PresellCompilerEngine;

class VariationGeneratorService
{
    public function __construct(private readonly PresellCompilerEngine $compiler) {}

    /**
     * Generate 3 distinct structural, visual, and copy variations for the chosen strategy.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    public function generateVariations(array $input): array
    {
        $strategyType = (string) ($input['strategyType'] ?? 'flash_sale');
        $productName = trim((string) ($input['productName'] ?? 'Product Offer'));
        $language = strtolower(trim((string) ($input['language'] ?? 'en')));
        $affiliateUrl = trim((string) ($input['affiliateUrl'] ?? '#'));
        $trackingCode = (string) ($input['trackingCode'] ?? '');
        $seoEnabled = (bool) ($input['seoEnabled'] ?? false);

        $variationBlueprints = $this->getBlueprints($strategyType, $productName, $language);

        $results = [];
        foreach ($variationBlueprints as $bp) {
            $variationData = [
                'id' => $bp['id'],
                'title' => $bp['title'],
                'angle' => $bp['angle'],
                'strategyType' => $strategyType,
                'productName' => $productName,
                'language' => $language,
                'affiliateUrl' => $affiliateUrl,
                'trackingCode' => $trackingCode,
                'seoEnabled' => $seoEnabled,
                'themeConfig' => $bp['themeConfig'],
                'editableParams' => $bp['editableParams'],
                'seoMeta' => $bp['seoMeta'],
            ];

            // Compile the initial HTML for this variation
            $variationData['compiledHtml'] = $this->compiler->compile($variationData);

            $results[] = $variationData;
        }

        return $results;
    }

    /**
     * Get distinct blueprints for v1, v2, v3 based on strategy and language.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getBlueprints(string $strategy, string $product, string $lang): array
    {
        $t = $this->getTranslations($lang, $product);

        // Version 1: Urgency & Scarcity Focus (Vibrant Teal / Coral Red)
        $v1 = [
            'id' => 'v1',
            'title' => $t['v1_title'],
            'angle' => 'Urgency & Scarcity',
            'themeConfig' => [
                'primaryColor' => '#0f766e',
                'accentColor' => '#14b8a6',
                'bgColor' => '#f8fafc',
                'surfaceColor' => '#ffffff',
                'textColor' => '#0f172a',
                'mutedColor' => '#64748b',
                'fontStack' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                'radius' => '10px',
                'badgeBg' => '#fee2e2',
                'badgeText' => '#b91c1c',
            ],
            'editableParams' => [
                'behaviorType' => $strategy === 'country_select' ? 'country_gate' : ($strategy === 'cookie_popup' ? 'cookie_wall' : 'exit_intent'),
                'headline' => $t['v1_headline'],
                'subheadline' => $t['v1_subheadline'],
                'ctaText' => $t['v1_cta'],
                'discountPercentage' => '50% OFF',
                'timerMinutes' => 11,
                'features' => $t['v1_features'],
                'socialProof' => $t['v1_proof'],
            ],
            'seoMeta' => [
                'title' => $product.' - '.$t['v1_seo_title'],
                'description' => $t['v1_seo_desc'],
                'keywords' => $product.', official, review, discount, verification',
            ],
        ];

        // Version 2: Authority & Social Proof Focus (Trust Navy / Slate)
        $v2 = [
            'id' => 'v2',
            'title' => $t['v2_title'],
            'angle' => 'Authority & Trust',
            'themeConfig' => [
                'primaryColor' => '#1e3a8a',
                'accentColor' => '#3b82f6',
                'bgColor' => '#f1f5f9',
                'surfaceColor' => '#ffffff',
                'textColor' => '#1e293b',
                'mutedColor' => '#475569',
                'fontStack' => 'Georgia, Cambria, "Times New Roman", serif',
                'radius' => '6px',
                'badgeBg' => '#e0e7ff',
                'badgeText' => '#3730a3',
            ],
            'editableParams' => [
                'behaviorType' => $strategy === 'country_select' ? 'country_gate' : ($strategy === 'cookie_popup' ? 'cookie_wall' : 'special_discount'),
                'headline' => $t['v2_headline'],
                'subheadline' => $t['v2_subheadline'],
                'ctaText' => $t['v2_cta'],
                'discountPercentage' => '45% OFF',
                'timerMinutes' => 15,
                'features' => $t['v2_features'],
                'socialProof' => $t['v2_proof'],
            ],
            'seoMeta' => [
                'title' => $product.' - '.$t['v2_seo_title'],
                'description' => $t['v2_seo_desc'],
                'keywords' => $product.', consumer report, authorized access, rating',
            ],
        ];

        // Version 3: Direct Value & Flash Clearance (High Impact Emerald / Amber)
        $v3 = [
            'id' => 'v3',
            'title' => $t['v3_title'],
            'angle' => 'Direct Value & Benefits',
            'themeConfig' => [
                'primaryColor' => '#047857',
                'accentColor' => '#10b981',
                'bgColor' => '#f0fdf4',
                'surfaceColor' => '#ffffff',
                'textColor' => '#064e3b',
                'mutedColor' => '#374151',
                'fontStack' => 'system-ui, -apple-system, sans-serif',
                'radius' => '14px',
                'badgeBg' => '#fef3c7',
                'badgeText' => '#92400e',
            ],
            'editableParams' => [
                'behaviorType' => $strategy === 'country_select' ? 'country_gate' : ($strategy === 'cookie_popup' ? 'cookie_wall' : 'exit_intent'),
                'headline' => $t['v3_headline'],
                'subheadline' => $t['v3_subheadline'],
                'ctaText' => $t['v3_cta'],
                'discountPercentage' => '60% OFF',
                'timerMinutes' => 9,
                'features' => $t['v3_features'],
                'socialProof' => $t['v3_proof'],
            ],
            'seoMeta' => [
                'title' => $product.' - '.$t['v3_seo_title'],
                'description' => $t['v3_seo_desc'],
                'keywords' => $product.', flash sale, exclusive package, bonus',
            ],
        ];

        return [$v1, $v2, $v3];
    }

    /**
     * Provide localized native copy for key languages.
     *
     * @return array<string, mixed>
     */
    protected function getTranslations(string $lang, string $product): array
    {
        switch ($lang) {
            case 'pt':
                return [
                    'v1_title' => 'Versão 1: Urgência e Escassez',
                    'v1_headline' => 'Condição Promocional Liberada para o '.$product,
                    'v1_subheadline' => 'Lotes oficiais com desconto direto do fabricante disponíveis por tempo limitado.',
                    'v1_cta' => 'Garantir Acesso com Desconto',
                    'v1_features' => [
                        'Garantia oficial incondicional de satisfação',
                        'Envio expresso rastreado para todo o país',
                        'Checkout 100% criptografado e seguro',
                        'Acesso prioritário a lotes recém-fabricados',
                    ],
                    'v1_proof' => 'Avaliado em 4.9/5 por mais de 12.400 compradores verificados',
                    'v1_seo_title' => 'Página Oficial e Condições Especiais',
                    'v1_seo_desc' => 'Acesse as condições oficiais e verificação regional de estoque para '.$product.'.',

                    'v2_title' => 'Versão 2: Autoridade e Análise Oficial',
                    'v2_headline' => 'Relatório do Consumidor: A Verdade Sobre o '.$product,
                    'v2_subheadline' => 'Entenda como funciona e onde adquirir o produto original direto do laboratório.',
                    'v2_cta' => 'Verificar Disponibilidade Oficial',
                    'v2_features' => [
                        'Fórmula original certificada em laboratório',
                        'Isento de falsificações do mercado paralelo',
                        'Proteção integral ao comprador',
                    ],
                    'v2_proof' => 'Recomendado por 98.4% dos especialistas independentes',
                    'v2_seo_title' => 'Relatório Completo de Avaliação',
                    'v2_seo_desc' => 'Leia a análise completa e verifique a autenticidade antes de fazer seu pedido de '.$product.'.',

                    'v3_title' => 'Versão 3: Benefício Direto e Pacote Bônus',
                    'v3_headline' => 'Desconto Exclusivo Liberado: Economize no '.$product,
                    'v3_subheadline' => 'Oferta direta de armazém com frete prioritário e garantia total.',
                    'v3_cta' => 'Resgatar Cupom de 60% OFF',
                    'v3_features' => [
                        'Preço de fábrica sem intermediários',
                        'Frete expresso com seguro total de entrega',
                        'Suporte ao cliente 24/7',
                    ],
                    'v3_proof' => 'Mais de 25.000 unidades enviadas este mês',
                    'v3_seo_title' => 'Desconto Exclusivo de Fábrica',
                    'v3_seo_desc' => 'Aproveite o lote promocional direto da fábrica com desconto especial para '.$product.'.',
                ];

            case 'es':
                return [
                    'v1_title' => 'Versión 1: Urgencia y Disponibilidad',
                    'v1_headline' => 'Asignación Promocional Oficial de '.$product,
                    'v1_subheadline' => 'Verifique la disponibilidad de inventario y acceda a la tarifa exclusiva para su región.',
                    'v1_cta' => 'Reclamar Descuento Especial',
                    'v1_features' => [
                        'Autenticidad 100% garantizada de fábrica',
                        'Envío prioritario con seguimiento continuo',
                        'Pago seguro con cifrado bancario SSL',
                    ],
                    'v1_proof' => 'Calificación de 4.9/5 por 14,300+ compradores verificados',
                    'v1_seo_title' => 'Portal Oficial y Descuento Regional',
                    'v1_seo_desc' => 'Consulte las condiciones de entrega y descuento oficial para '.$product.'.',

                    'v2_title' => 'Versión 2: Autoridad y Evaluación',
                    'v2_headline' => 'Informe para el Consumidor sobre '.$product,
                    'v2_subheadline' => 'Guía esencial antes de ordenar: estándares de calidad y acceso directo autorizado.',
                    'v2_cta' => 'Verificar Tienda Oficial',
                    'v2_features' => [
                        'Fórmula pura con certificación de pureza',
                        'Garantía total de devolución de 60 días',
                    ],
                    'v2_proof' => 'Aprobado por el 98% de los evaluadores',
                    'v2_seo_title' => 'Evaluación y Reporte del Consumidor',
                    'v2_seo_desc' => 'Informe objetivo y acceso directo a la distribución oficial de '.$product.'.',

                    'v3_title' => 'Versión 3: Beneficio y Valor Directo',
                    'v3_headline' => 'Oferta Especial Directa: Ahorre en '.$product,
                    'v3_subheadline' => 'Precios de bodega con envío inmediato y beneficios exclusivos.',
                    'v3_cta' => 'Obtener Paquete con Descuento',
                    'v3_features' => [
                        'Tarifa de distribuidor sin intermediarios',
                        'Entrega garantizada con seguro de transporte',
                    ],
                    'v3_proof' => 'Lote con alta demanda en su región',
                    'v3_seo_title' => 'Paquete Especial con Descuento',
                    'v3_seo_desc' => 'Asegure su lote de '.$product.' con descuento directo de fábrica.',
                ];

            case 'de':
                return [
                    'v1_title' => 'Version 1: Dringlichkeit & Kontingent',
                    'v1_headline' => 'Offizielles Sonderkontingent für '.$product,
                    'v1_subheadline' => 'Verfügbarkeit im Regionallager bestätigt. Rabattierte Aktionspreise für kurze Zeit aktiv.',
                    'v1_cta' => 'Jetzt Sonderkondition Sichern',
                    'v1_features' => [
                        '100% Originalware direkt vom Hersteller',
                        'Prioritärer Versand mit Sendungsverfolgung',
                        'Zufriedenheitsgarantie mit Rückgaberecht',
                    ],
                    'v1_proof' => 'Bewertet mit 4.9/5 von über 11.800 Kunden',
                    'v1_seo_title' => 'Offizielles Portal & Prüfung',
                    'v1_seo_desc' => 'Offizielle Verfügbarkeitsprüfung und Sonderpreis für '.$product.'.',

                    'v2_title' => 'Version 2: Verbraucher-Prüfbericht',
                    'v2_headline' => 'Verbraucher-Information zu '.$product,
                    'v2_subheadline' => 'Alle Fakten zur Qualitätskontrolle und Bezugsquellen im Überblick.',
                    'v2_cta' => 'Offiziellen Shop Öffnen',
                    'v2_features' => [
                        'Laborgeprüfte Reinheit und Qualität',
                        'Sichere SSL-Zahlungsabwicklung',
                    ],
                    'v2_proof' => 'Geprüfte Herstellerzertifizierung',
                    'v2_seo_title' => 'Unabhängiger Prüfbericht',
                    'v2_seo_desc' => 'Umfassender Überblick und Original-Bezugsquelle für '.$product.'.',

                    'v3_title' => 'Version 3: Direkt-Vorteilspaket',
                    'v3_headline' => 'Fabrik-Direktaktion: '.$product.' mit Rabatt',
                    'v3_subheadline' => 'Sichern Sie sich den offiziellen Aktionsrabatt solange der Vorrat reicht.',
                    'v3_cta' => 'Rabattcode Aktivieren',
                    'v3_features' => [
                        'Großhandelskonditionen ohne Zwischenhändler',
                        'Schnellversand ab Lager',
                    ],
                    'v3_proof' => 'Hohe Nachfrage in Ihrer Region',
                    'v3_seo_title' => 'Direktes Rabattkontingent',
                    'v3_seo_desc' => 'Direktzugang zum autorisierten Herstellerangebot für '.$product.'.',
                ];

            case 'en':
            default:
                return [
                    'v1_title' => 'Version 1: Urgency & Scarcity Angle',
                    'v1_headline' => 'Official Inventory Allocation: Special Conditions for '.$product,
                    'v1_subheadline' => 'Regional warehouse stocks confirmed available. Promotional pricing locked for a limited duration.',
                    'v1_cta' => 'Claim Your Special Allocation Now',
                    'v1_features' => [
                        '100% Authentic Direct-From-Manufacturer Sourcing',
                        'Priority Express Regional Dispatch with Full Tracking',
                        'Risk-Free 60-Day Satisfaction Evaluation Period',
                        '256-Bit Bank-Grade SSL Secure Checkout',
                    ],
                    'v1_proof' => 'Rated 4.9/5 by 14,800+ Verified Buyers Worldwide',
                    'v1_seo_title' => 'Official Access & Verification Portal',
                    'v1_seo_desc' => 'Official regional inventory verification and special promotional conditions for '.$product.'. Fast priority dispatch.',

                    'v2_title' => 'Version 2: Authority & Consumer Advisory',
                    'v2_headline' => 'Independent Consumer Advisory: The Real Evaluation of '.$product,
                    'v2_subheadline' => 'What buyers must know before ordering: purity testing, authentic sourcing, and regional availability.',
                    'v2_cta' => 'Proceed to Official Manufacturer Store',
                    'v2_features' => [
                        'Certified Pure Formulation under Strict GMP Standards',
                        'Zero Third-Party Counterfeit Risk (Direct Sourcing)',
                        'Full Buyer Protection & Money-Back Confidence',
                    ],
                    'v2_proof' => 'Recommended by 98.7% of Independent Review Panels',
                    'v2_seo_title' => 'Verified Consumer Review & Report',
                    'v2_seo_desc' => 'Read our comprehensive consumer advisory report and verify authentic sourcing for '.$product.'.',

                    'v3_title' => 'Version 3: Direct Value & Flash Clearance',
                    'v3_headline' => 'Factory-Direct Clearance: Save Up To 60% on '.$product,
                    'v3_subheadline' => 'Direct warehouse allocation with complimentary priority delivery and bonus protection packages.',
                    'v3_cta' => 'Unlock 60% OFF Factory Direct Package',
                    'v3_features' => [
                        'Wholesale Pricing Direct from the Source',
                        'Insured Express Courier Dispatch',
                        'Complimentary Bonus Access for Early Orders',
                    ],
                    'v3_proof' => 'Over 24,000 Units Dispatched this Month',
                    'v3_seo_title' => 'Exclusive Factory-Direct Clearance',
                    'v3_seo_desc' => 'Lock in promotional clearance pricing directly from the official warehouse for '.$product.'.',
                ];
        }
    }
}
