<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Track extends Model
{
    protected $fillable = [
        'product_name',
        'platform',
        'affiliate_url',
        'page_type',
        'strategy',
        'language',
        'typical_commission_cad',
        'postback_secret',
        'public_script_key',
        'google_ads_account_id',
        'conversion_action_id',
        'campaign_ids',
    ];

    protected function casts(): array
    {
        return [
            'typical_commission_cad' => 'decimal:2',
            'campaign_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Track $track): void {
            $track->postback_secret ??= Str::random(48);
            $track->public_script_key ??= Str::lower(Str::random(32));
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }

    public function conversionAction(): BelongsTo
    {
        return $this->belongsTo(ConversionAction::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    public function ipBlocks(): HasMany
    {
        return $this->hasMany(IpBlock::class);
    }

    public function adSpend(): HasMany
    {
        return $this->hasMany(AdSpend::class);
    }

    public function clickParam(): string
    {
        return config('flowhub.click_params.'.$this->platform, 'tid');
    }

    public function postbackUrl(): string
    {
        return url('/hooks/'.$this->platform.'/'.$this->postback_secret);
    }

    public function scriptTag(?int $pageId = null): string
    {
        $src = rtrim((string) config('app.url'), '/').'/t.js';
        $page = $pageId ? (string) $pageId : '';

        return '<script async src="'.$src.'" data-track="'.$this->public_script_key.'" data-page="'.$page.'"></script>';
    }

    public function affiliateUrlFor(string $sessionId): string
    {
        $parts = parse_url($this->affiliate_url);
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query[$this->clickParam()] = $sessionId;
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (! empty($parts['port'])) {
            $base .= ':'.$parts['port'];
        }
        $base .= $parts['path'] ?? '';
        $url = $base.'?'.http_build_query($query);
        if (! empty($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
