<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductScoreHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'product_score_history';

    protected $fillable = [
        'product_id',
        'score',
        'breakdown',
        'flags',
        'inputs_snapshot',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'breakdown' => 'array',
            'flags' => 'array',
            'inputs_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WatchedProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(WatchedProduct::class, 'product_id');
    }
}
