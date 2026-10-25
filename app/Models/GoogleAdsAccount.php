<?php

namespace App\Models;

use App\Services\Google\GoogleAdsClient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleAdsAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'login_customer_id',
        'refresh_token',
        'name',
        'email',
        'status',
        'is_default',
    ];

    protected $hidden = [
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'is_default' => 'boolean',
        ];
    }

    public function conversionActions(): HasMany
    {
        return $this->hasMany(ConversionAction::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(GoogleAdsCampaign::class);
    }

    public function ipBlocks(): HasMany
    {
        return $this->hasMany(IpBlock::class);
    }

    public function googleLoginAccounts(): HasMany
    {
        return $this->hasMany(GoogleLoginAccount::class, 'linked_google_ads_account_id');
    }

    /**
     * Resolve the active default Google Ads account using user preference, session,
     * database default flag, or config fallback.
     */
    public static function getDefault(?User $user = null): ?self
    {
        if ($user && $user->default_google_ads_account_id) {
            $account = self::query()->find($user->default_google_ads_account_id);
            if ($account) {
                return $account;
            }
        }

        if (session()?->has('default_google_ads_account_id')) {
            $account = self::query()->find(session('default_google_ads_account_id'));
            if ($account) {
                return $account;
            }
        }

        $account = self::query()->where('is_default', true)->first();
        if ($account) {
            return $account;
        }

        if ($configId = config('flowhub.google_ads.default_account_id')) {
            $account = self::query()->find($configId);
            if ($account) {
                return $account;
            }
        }

        if ($configCustomer = config('flowhub.google_ads.default_customer_id')) {
            $digits = preg_replace('/\D/', '', (string) $configCustomer);
            $account = self::query()->where('customer_id', $digits)->first();
            if ($account) {
                return $account;
            }
        }

        // If only one account is linked, default to it
        if (self::query()->count() === 1) {
            return self::query()->first();
        }

        return null;
    }

    /**
     * Mark this account as default across the database, user preferences, and session.
     */
    public function makeDefault(?User $user = null): void
    {
        self::query()->where('id', '!=', $this->id)->where('is_default', true)->update(['is_default' => false]);
        $this->update(['is_default' => true]);

        if ($user) {
            $user->update(['default_google_ads_account_id' => $this->id]);
        }

        if (session()) {
            session(['default_google_ads_account_id' => $this->id]);
        }
    }

    /**
     * Build the standard Google tag (gtag.js) HTML snippet for this account.
     */
    public function globalSiteTag(): string
    {
        return app(GoogleAdsClient::class)->buildGlobalSiteTag((string) $this->customer_id);
    }
}
