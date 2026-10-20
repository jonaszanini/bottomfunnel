<?php

namespace App\Services\Copy;

use Illuminate\Support\Facades\Http;
use Throwable;

class BaseCopyGeneratorService
{
    public function __construct(
        protected SalesPageScraperService $scraper,
        protected CopyCharacterValidator $validator
    ) {}

    /**
     * Generate validated base ad copy matching copyGeneratorService.ts.
     *
     * @param  array{
     *     salesPageUrl: string,
     *     manualContext?: ?string,
     *     targetKeyword?: ?string,
     *     timeoutMs?: ?int
     * }  $input
     * @return array{
     *     adCopy: array{
     *         detectedLanguage: string,
     *         productSummary: string,
     *         headlines: array<int, array{id: string|int, text: string, category: string, isLocked: bool, charCount: int, isValid: bool}>,
     *         descriptions: array<int, array{id: string|int, text: string, angle: string, isLocked: bool, charCount: int, isValid: bool}>,
     *         competitorAnalysisSummary?: array<int, string>
     *     },
     *     usedScraperFallback: bool,
     *     scraperWarning: ?string,
     *     scraped: array<string, mixed>
     * }
     */
    public function generateValidatedBaseAdCopy(array $input): array
    {
        $scraped = $this->scraper->scrape(
            $input['salesPageUrl'],
            $input['manualContext'] ?? null,
            $input['timeoutMs'] ?? 12000
        );

        $formattedContext = implode("\n\n", [
            "URL: {$scraped['url']}",
            "Language Hint: {$scraped['detectedLanguageHint']}",
            'Target Keyword: '.($input['targetKeyword'] ?? 'Not specified'),
            "Title: {$scraped['pageTitle']}",
            "Meta Description: {$scraped['metaDescription']}",
            "Headings:\n".implode("\n", $scraped['headings']),
            "Guarantee Signals:\n".implode("\n", $scraped['guaranteeSignals']),
            "Bonus & Offer Signals:\n".implode("\n", $scraped['bonusAndOfferSignals']),
            "Key Bullets:\n".implode("\n", $scraped['bulletPoints']),
            "Body Excerpt:\n{$scraped['bodyExcerpt']}",
        ]);

        $rawBatch = $this->callInitialLlm($formattedContext, $scraped['detectedLanguageHint']);

        $compressCallback = function (array $params): array {
            return $this->compressOverLimitAssetsViaLlm($params);
        };

        $validatedBatch = $this->validator->enforceCharacterLimitsWithRetry($rawBatch, $compressCallback);

        return [
            'adCopy' => $validatedBatch,
            'usedScraperFallback' => $scraped['usedFallback'],
            'scraperWarning' => $scraped['warningMessage'],
            'scraped' => $scraped,
        ];
    }

    /**
     * Shorten a single asset on demand matching copyGeneratorService.ts.
     *
     * @param  array{
     *     id: string|int,
     *     type: 'headline'|'description',
     *     currentText: string,
     *     detectedLanguage?: string,
     *     productSummary?: string
     * }  $params
     * @return array{text: string, charCount: int}
     */
    public function shortenSingleAssetOnDemand(array $params): array
    {
        $id = (string) $params['id'];
        $type = $params['type'];
        $currentText = (string) $params['currentText'];
        $language = (string) ($params['detectedLanguage'] ?? 'en');
        $productSummary = (string) ($params['productSummary'] ?? '');

        $batch = [
            'detectedLanguage' => $language,
            'productSummary' => $productSummary,
            'headlines' => $type === 'headline' ? [
                ['id' => $id, 'text' => $currentText, 'category' => 'Benefit', 'isLocked' => false],
            ] : [],
            'descriptions' => $type === 'description' ? [
                ['id' => $id, 'text' => $currentText, 'angle' => 'Direct', 'isLocked' => false],
            ] : [],
        ];

        $compressCallback = function (array $compressParams): array {
            return $this->compressOverLimitAssetsViaLlm($compressParams);
        };

        $validated = $this->validator->enforceCharacterLimitsWithRetry($batch, $compressCallback);

        $item = $type === 'headline'
            ? ($validated['headlines'][0] ?? ['text' => $currentText, 'charCount' => mb_strlen($currentText)])
            : ($validated['descriptions'][0] ?? ['text' => $currentText, 'charCount' => mb_strlen($currentText)]);

        return [
            'text' => $item['text'],
            'charCount' => $item['charCount'],
        ];
    }

    /**
     * Compress over limit assets via Gemini or OpenAI LLM.
     *
     * @param  array{
     *     detectedLanguage: string,
     *     productSummary: string,
     *     itemsToCompress: array<int, array<string, mixed>>
     * }  $params
     * @return array<int, array{id: string, text: string}>
     */
    public function compressOverLimitAssetsViaLlm(array $params): array
    {
        $prompt = <<<PROMPT
You are a Google Ads Responsive Search Ad character compression engine.
Rewrite every item below in {$params['detectedLanguage']} so that its total character count (including spaces and punctuation) is strictly at or below its maxLength.

Rules:
1. Preserve the core persuasive hook (Authority, Guarantee, Bonus, Benefit, or Security).
2. Never use exclamation marks in headlines.
3. Aim for 2 to 4 characters under maxLength so it fits comfortably.

Product Context: {$params['productSummary']}

Items to compress:
PROMPT;

        $prompt .= "\n".json_encode($params['itemsToCompress'], JSON_PRETTY_PRINT);

        // Try Gemini if configured
        $geminiKey = (string) config('flowhub.gemini.key');
        if ($geminiKey !== '') {
            try {
                $geminiModel = config('flowhub.gemini.model', 'gemini-2.5-flash');
                $response = Http::timeout(15)
                    ->acceptJson()
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$geminiModel}:generateContent?key={$geminiKey}", [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'temperature' => 0.2,
                        ],
                    ]);

                if ($response->successful()) {
                    $text = $response->json('candidates.0.content.parts.0.text');
                    $decoded = json_decode((string) $text, true);
                    if (isset($decoded['rewrittenItems']) && is_array($decoded['rewrittenItems'])) {
                        return $decoded['rewrittenItems'];
                    }
                }
            } catch (Throwable) {
                // fall through to OpenAI
            }
        }

        // Try OpenAI if configured
        $openaiKey = (string) config('flowhub.openai.key');
        if ($openaiKey !== '') {
            try {
                $response = Http::withToken($openaiKey)
                    ->timeout(15)
                    ->acceptJson()
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => config('flowhub.openai.model', 'gpt-4o-mini'),
                        'temperature' => 0.2,
                        'response_format' => [
                            'type' => 'json_schema',
                            'json_schema' => [
                                'name' => 'compression_response',
                                'strict' => true,
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['rewrittenItems'],
                                    'properties' => [
                                        'rewrittenItems' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'required' => ['id', 'text'],
                                                'properties' => [
                                                    'id' => ['type' => 'string'],
                                                    'text' => ['type' => 'string'],
                                                ],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                    ],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ]);

                if ($response->successful()) {
                    $content = $response->json('choices.0.message.content');
                    $decoded = json_decode((string) $content, true);
                    if (isset($decoded['rewrittenItems']) && is_array($decoded['rewrittenItems'])) {
                        return $decoded['rewrittenItems'];
                    }
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return [];
    }

    /**
     * Initial 20x10 generation from formatted context.
     *
     * @return array{
     *     detectedLanguage: string,
     *     productSummary: string,
     *     headlines: array<int, array{id: string, text: string, category: string, isLocked: bool}>,
     *     descriptions: array<int, array{id: string, text: string, angle: string, isLocked: bool}>
     * }
     */
    protected function callInitialLlm(string $formattedContext, string $languageHint): array
    {
        $lang = $languageHint !== 'auto' && $languageHint !== '' ? $languageHint : 'en';

        // Check for active LLM keys
        $openaiKey = (string) config('flowhub.openai.key');
        if ($openaiKey !== '') {
            try {
                $llmResult = $this->callOpenAiChat($formattedContext, $lang, $openaiKey);
                if ($llmResult !== null) {
                    return $llmResult;
                }
            } catch (Throwable) {
                // fall back to template generator
            }
        }

        return $this->generateContextualTemplateBatch($formattedContext, $lang);
    }

    /**
     * OpenAI initial chat completion call.
     *
     * @return ?array<string, mixed>
     */
    protected function callOpenAiChat(string $formattedContext, string $language, string $key): ?array
    {
        $systemPrompt = 'Create 20 headlines and 10 descriptions for the attached product, in the same language as the product. '
            .'Headlines must be no longer than 30 characters (including spaces). Descriptions must be no longer than 90 characters (including spaces). '
            .'These ads are for bottom-of-funnel Google Ads. Focus on authority, guarantees, bonuses, benefits, and security. '
            .'Content must be Google Ads compliant (no exclamation marks in headlines, no ALL CAPS words except standard acronyms, no unsubstantiated miracle claims).';

        $schema = [
            'type' => 'object',
            'properties' => [
                'detectedLanguage' => ['type' => 'string'],
                'productSummary' => ['type' => 'string'],
                'headlines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                            'category' => [
                                'type' => 'string',
                                'enum' => ['Authority', 'Guarantee', 'Bonus', 'Benefit', 'Security'],
                            ],
                            'isLocked' => ['type' => 'boolean'],
                        ],
                        'required' => ['id', 'text', 'category', 'isLocked'],
                        'additionalProperties' => false,
                    ],
                ],
                'descriptions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                            'angle' => ['type' => 'string'],
                            'isLocked' => ['type' => 'boolean'],
                        ],
                        'required' => ['id', 'text', 'angle', 'isLocked'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['detectedLanguage', 'productSummary', 'headlines', 'descriptions'],
            'additionalProperties' => false,
        ];

        $response = Http::withToken($key)
            ->timeout(35)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('flowhub.openai.model', 'gpt-4o-mini'),
                'temperature' => 0.4,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'google_ads_copy',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Target Language: {$language}\n\nContext:\n{$formattedContext}"],
                ],
            ]);

        if ($response->successful()) {
            $content = $response->json('choices.0.message.content');
            $decoded = is_string($content) ? json_decode($content, true) : null;
            if (is_array($decoded) && isset($decoded['headlines'], $decoded['descriptions'])) {
                foreach ($decoded['headlines'] as &$h) {
                    $h['id'] = (string) $h['id'];
                    $h['isLocked'] = false;
                }
                unset($h);
                foreach ($decoded['descriptions'] as &$d) {
                    $d['id'] = (string) $d['id'];
                    $d['isLocked'] = false;
                }
                unset($d);

                return $decoded;
            }
        }

        return null;
    }

    /**
     * Contextual template fallback.
     *
     * @return array{
     *     detectedLanguage: string,
     *     productSummary: string,
     *     headlines: array<int, array{id: string, text: string, category: string, isLocked: bool}>,
     *     descriptions: array<int, array{id: string, text: string, angle: string, isLocked: bool}>
     * }
     */
    protected function generateContextualTemplateBatch(string $context, string $lang): array
    {
        // Extract title line
        preg_match('/Title:\s*(.+)/u', $context, $m);
        $rawTitle = trim($m[1] ?? 'Official Product');
        $cleanTitle = trim((string) preg_replace('/[-–|:•].*$/u', '', $rawTitle));
        if (mb_strlen($cleanTitle, 'UTF-8') > 16 || $cleanTitle === '') {
            $cleanTitle = 'Official Store';
        }

        $templates = match ($lang) {
            'pt' => [
                'Authority' => [
                    $cleanTitle.' Site Oficial',
                    $cleanTitle.' Original Direto',
                    'Portal Oficial '.$cleanTitle,
                    'Distribuidor Autorizado',
                ],
                'Guarantee' => [
                    'Garantia de 60 Dias',
                    'Satisfação Garantida',
                    '100% Risco Zero',
                    'Reembolso Garantido',
                ],
                'Bonus' => [
                    'Bônus Exclusivo Liberado',
                    'Frete Grátis Disponível',
                    'E-book Grátis Incluso',
                    'Acesso Imediato ao Bônus',
                ],
                'Benefit' => [
                    'Fórmula Original Segura',
                    'Resultados Comprovados',
                    'Entrega Rápida Express',
                    'Estoque Regional Ativo',
                ],
                'Security' => [
                    'Compra 100% Segura SSL',
                    'Site Seguro e Verificado',
                    'Checkout Criptografado',
                    'Privacidade Total',
                ],
                'descriptions' => [
                    ['Compre o '.$cleanTitle.' direto do fabricante. Estoque original com despacho expresso.', 'Autoridade e Procedência'],
                    ['Garantia incondicional de 60 dias. Se não amar os resultados, receba 100% de volta.', 'Garantia Risco Zero'],
                    ['Condição promocional exclusiva por tempo limitado com bônus grátis inclusos.', 'Bônus e Escassez'],
                    ['Fórmula autêntica com ingredientes testados e aprovação rigorosa de qualidade.', 'Benefício Central'],
                    ['Ambiente de compra seguro com criptografia bancária 256-bit SSL. Peça o seu.', 'Segurança do Checkout'],
                    ['Acesso direto ao lote oficial com rastreio imediato dos correios para sua região.', 'Logística e Envio'],
                    ['Consumidores verificados recomendam. Acesse o portal oficial e confira a oferta.', 'Prova Social'],
                    ['Não compre réplicas. Garanta o produto original com nota fiscal e garantia total.', 'Aviso Anti-Pirataria'],
                    ['Desconto especial aplicado no carrinho oficial. Aproveite os últimos frascos.', 'Urgência e Oferta'],
                    ['Suporte prioritário e satisfação avaliada em 4.9 estrelas. Garanta agora.', 'Confiança e Suporte'],
                ],
            ],
            default => [
                'Authority' => [
                    $cleanTitle.' Official Store',
                    'Official '.$cleanTitle.' Site',
                    'Authorized Direct Source',
                    $cleanTitle.' Manufacturer',
                ],
                'Guarantee' => [
                    '60-Day Money-Back Guarantee',
                    '100% Risk-Free Trial',
                    'Satisfaction Guaranteed',
                    'Full Refund Guarantee',
                ],
                'Bonus' => [
                    'Free Digital Bonuses Added',
                    'Free Express Regional Shipping',
                    'Instant Bonus Downloads',
                    'VIP Bonus Guide Included',
                ],
                'Benefit' => [
                    '100% Authentic Direct Sourcing',
                    'Clinically Tested Formula',
                    'Fast Priority Dispatch',
                    'Regional Stocks In Hand',
                ],
                'Security' => [
                    '256-Bit SSL Secure Checkout',
                    'Encrypted Bank-Level Safety',
                    'Verified Official Merchant',
                    '100% Safe & Private Order',
                ],
                'descriptions' => [
                    ['Order '.$cleanTitle.' direct from the official manufacturer with priority tracked dispatch.', 'Authority & Sourcing'],
                    ['Try risk-free with an ironclad 60-day satisfaction evaluation. 100% refund guaranteed.', 'Risk-Free Guarantee'],
                    ['Exclusive direct pricing locked for a limited time with free digital bonuses included.', 'Bonus & Special Value'],
                    ['Authentic verified batch direct from GMP-certified facilities. High quality purity.', 'Clinical Quality'],
                    ['Bank-grade 256-bit encrypted checkout safeguards your privacy on every official order.', 'Checkout Security'],
                    ['Priority warehouse shipping delivers straight to your doorstep with tracking.', 'Express Dispatch'],
                    ['Rated 4.9/5 stars by thousands of verified customers. Claim verified batch today.', 'Social Proof'],
                    ['Avoid unauthorized marketplace imitations. Secure guaranteed authentic formula now.', 'Authenticity Warning'],
                    ['Promotional conditions unlocked for regional buyers while warehouse stocks remain.', 'Urgency & Scarcity'],
                    ['Dedicated customer support and 100% money-back peace of mind. Complete your order.', 'Support & Confidence'],
                ],
            ],
        };

        $headlines = [];
        $id = 1;

        foreach (['Authority', 'Guarantee', 'Bonus', 'Benefit', 'Security'] as $cat) {
            $pool = $templates[$cat] ?? $templates['Authority'];
            foreach ($pool as $text) {
                $headlines[] = [
                    'id' => (string) ($id++),
                    'text' => $text,
                    'category' => $cat,
                    'isLocked' => false,
                ];
            }
        }

        $descriptions = [];
        $descId = 1;
        foreach ($templates['descriptions'] as $item) {
            $descriptions[] = [
                'id' => (string) ($descId++),
                'text' => $item[0],
                'angle' => $item[1],
                'isLocked' => false,
            ];
        }

        return [
            'detectedLanguage' => $lang,
            'productSummary' => "Official direct campaign assets for {$cleanTitle}. Bottom-of-funnel conversion copy highlighting manufacturer authority, guarantees, bonuses, and SSL security.",
            'headlines' => $headlines,
            'descriptions' => $descriptions,
        ];
    }
}
