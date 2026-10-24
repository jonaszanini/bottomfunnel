<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\GoogleAccountPasswordCast;
use App\Enums\GoogleLoginAccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model representing manually managed Google Ads login credentials.
 *
 * NOTE: Passwords are encrypted with AES-256-CBC using the dedicated
 * GOOGLE_ACCOUNTS_ENCRYPTION_KEY environment key. Losing this key makes
 * stored passwords permanently unrecoverable.
 */
class GoogleLoginAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'password',
        'customer_id',
        'login_customer_id',
        'status',
        'currency',
        'notes',
        'cancelled_at',
        'linked_google_ads_account_id',
    ];

    /**
     * Never serialize the password into arrays or JSON responses.
     */
    protected $hidden = [
        'password',
    ];

    protected $appends = [
        'has_password',
        'formatted_customer_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => GoogleAccountPasswordCast::class,
            'status' => GoogleLoginAccountStatus::class,
            'cancelled_at' => 'datetime',
            'linked_google_ads_account_id' => 'integer',
        ];
    }

    /**
     * Linked campaigns for this login account.
     *
     * @return HasMany<GoogleLoginAccountCampaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(GoogleLoginAccountCampaign::class, 'google_login_account_id')->orderBy('campaign_id');
    }

    /**
     * Optional link to OAuth GoogleAdsAccount.
     *
     * @return BelongsTo<GoogleAdsAccount, $this>
     */
    public function linkedGoogleAdsAccount(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'linked_google_ads_account_id');
    }

    /**
     * Check if a password is set in the database.
     */
    protected function hasPassword(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => ! empty($this->getRawOriginal('password'))
        );
    }

    /**
     * Formats 10-digit customer ID as XXX-XXX-XXXX for display.
     */
    protected function formattedCustomerId(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $raw = (string) $this->customer_id;
                $clean = preg_replace('/[^0-9]/', '', $raw) ?? '';
                if (strlen($clean) === 10) {
                    return substr($clean, 0, 3).'-'.substr($clean, 3, 3).'-'.substr($clean, 6, 4);
                }

                return $raw;
            }
        );
    }

    /**
     * Explicitly decrypt and return the raw password.
     * Must only be called from authenticated, authorized endpoints.
     */
    public function revealPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Scope query to active accounts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GoogleLoginAccountStatus::Active);
    }

    /**
     * Scope query to filter by status or search keyword.
     *
     * @param  Builder<GoogleLoginAccount>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, function (Builder $query, string $status): void {
            if ($status !== 'all' && $status !== '') {
                $query->where('status', $status);
            }
        });

        $query->when($filters['search'] ?? null, function (Builder $query, string $search): void {
            $clean = trim($search);
            $digits = preg_replace('/[^0-9]/', '', $clean) ?? '';

            $query->where(function (Builder $q) use ($clean, $digits): void {
                $q->where('email', 'like', '%'.$clean.'%')
                    ->orWhere('notes', 'like', '%'.$clean.'%');

                if ($digits !== '') {
                    $q->orWhere('customer_id', 'like', '%'.$digits.'%')
                        ->orWhere('login_customer_id', 'like', '%'.$digits.'%');
                }
            });
        });
    }
}
