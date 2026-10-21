<?php

namespace App\Enums;

enum NegativeKeywordSource: string
{
    case Base = 'base';
    case Competitor = 'competitor';
    case Homonym = 'homonym';
    case GenericMeaning = 'generic_meaning';
    case SerpIntruder = 'serp_intruder';
    case QualifierRule = 'qualifier_rule';
    case Manual = 'manual';
    case Llm = 'llm';

    public function label(): string
    {
        return match ($this) {
            self::Base => 'Base Seed',
            self::Competitor => 'Competitor',
            self::Homonym => 'Homonym',
            self::GenericMeaning => 'Generic Meaning',
            self::SerpIntruder => 'SERP Intruder',
            self::QualifierRule => 'Qualifier Rule',
            self::Manual => 'Manual',
            self::Llm => 'LLM',
        };
    }
}
