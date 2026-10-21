<?php

namespace App\Models;

use App\Enums\NegativeKeywordMatchType;
use App\Enums\NegativeKeywordSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductNegativeKeyword extends Model
{
    use HasFactory;

    protected $table = 'product_negative_keywords';

    protected $fillable = [
        'product_id',
        'term',
        'match_type',
        'source',
        'enabled',
        'strategy_rules',
        'manually_overridden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_type' => NegativeKeywordMatchType::class,
            'source' => NegativeKeywordSource::class,
            'enabled' => 'boolean',
            'strategy_rules' => 'array',
            'manually_overridden' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WatchedProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(WatchedProduct::class, 'product_id');
    }

    /**
     * Format the keyword in Google Ads format: [exact], "phrase", broad.
     */
    public function formattedTerm(): string
    {
        $match = $this->match_type instanceof NegativeKeywordMatchType
            ? $this->match_type
            : NegativeKeywordMatchType::from($this->match_type ?? 'broad');

        return match ($match) {
            NegativeKeywordMatchType::Exact => '['.$this->term.']',
            NegativeKeywordMatchType::Phrase => '"'.$this->term.'"',
            NegativeKeywordMatchType::Broad => $this->term,
        };
    }
}
