<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NegativeKeywordSeedSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $reviewBuyerTerms = [
            'review',
            'reviews',
            'real reviews',
            'real people reviews',
            'does it work',
            'how does it work',
            'how it works',
            'scam',
            'scams',
            'side effect',
            'side effects',
            'ingredient',
            'ingredients',
            'ingredients list',
            'result',
            'results',
        ];

        $baseSeeds = [
            'affiliate',
            'affilliates',
            'after',
            'amazon',
            'before',
            'best buy',
            'bestbuy',
            'buygoods',
            'clickbank',
            'complaint',
            'complaints',
            'contact',
            'contact number',
            'cost',
            'customer',
            'customer service',
            'cvs',
            'digistore',
            'does it work',
            'download',
            'drink',
            'ebay',
            'email',
            'e-mail',
            'formula',
            'free',
            'hoax',
            'how does it work',
            'how it works',
            'how much',
            'how to affiliate',
            'how to sell',
            'how to use',
            'images',
            'info',
            'ingredient',
            'ingredients',
            'ingredients list',
            'is safe',
            'label',
            'local',
            'marketplace',
            'maxweb',
            'money back',
            'pdf',
            'phone',
            'phone number',
            'price',
            'producer',
            'real people reviews',
            'real reviews',
            'recipe',
            'reddit',
            'refund',
            'result',
            'results',
            'return',
            'return policy',
            'review',
            'reviews',
            'scam',
            'scams',
            'side effect',
            'side effects',
            'support',
            'testimonial',
            'testimonials',
            'video',
            'walmart',
            'work',
            'works',
            'youtube',
        ];

        // Emprego / Carreira
        $careerSeeds = [
            'career',
            'careers',
            'employment',
            'glassdoor',
            'hiring',
            'indeed',
            'internship',
            'job',
            'jobs',
            'recruiter',
            'remote job',
            'resume',
            'salaries',
            'salary',
            'work from home',
        ];

        // Grátis / Barato / Desconto
        $discountSeeds = [
            'bargain',
            'cheap',
            'cheapest',
            'clearance',
            'coupon',
            'coupons',
            'deal',
            'deals',
            'discount',
            'for free',
            'freebie',
            'giveaway',
            'inexpensive',
            'promo code',
            'refurbished',
            'second hand',
            'used',
        ];

        // DIY / Informacional / Como fazer
        $diySeeds = [
            'definition',
            'diy',
            'example',
            'guide',
            'homemade',
            'how to make',
            'instructions',
            'sample',
            'step by step',
            'template',
            'tutorial',
            'what is',
            'wiki',
            'wikipedia',
        ];

        // Educação / Curso
        $educationSeeds = [
            'certification',
            'class',
            'college',
            'course',
            'courses',
            'school',
            'student',
            'training',
            'university',
        ];

        // Comparação / Alternativas
        $comparisonSeeds = [
            'alternative',
            'alternatives',
            'best alternative',
            'compare',
            'comparison',
            'versus',
            'vs',
        ];

        // Atacado / Revenda
        $wholesaleSeeds = [
            'bulk',
            'dropship',
            'dropshipping',
            'manufacturer',
            'reseller',
            'supplier',
            'wholesale',
        ];

        // Pirata / Download ilegal
        $piracySeeds = [
            'crack',
            'cracked',
            'keygen',
            'pirated',
            'torrent',
        ];

        // Outros úteis / Redes sociais e plataformas
        $platformAndOtherSeeds = [
            'aliexpress',
            'cancel',
            'cancellation',
            'etsy',
            'facebook',
            'forum',
            'instagram',
            'login',
            'password',
            'quora',
            'sign in',
            'target',
            'tiktok',
            'warranty',
        ];

        $allSeeds = array_values(array_unique(array_merge(
            $baseSeeds,
            $careerSeeds,
            $discountSeeds,
            $diySeeds,
            $educationSeeds,
            $comparisonSeeds,
            $wholesaleSeeds,
            $piracySeeds,
            $platformAndOtherSeeds
        )));

        sort($allSeeds);

        $now = now();

        foreach ($allSeeds as $term) {
            $isReviewBuyerTerm = in_array(strtolower($term), $reviewBuyerTerms, true);
            $strategyRules = $isReviewBuyerTerm
                ? json_encode(['disabled_for_strategies' => ['bof_review', 'robusta']])
                : null;

            DB::table('negative_keyword_seeds')->updateOrInsert(
                ['term' => $term],
                [
                    'enabled_by_default' => true,
                    'strategy_rules' => $strategyRules,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
