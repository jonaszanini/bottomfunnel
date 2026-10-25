<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\GoogleAccountPasswordCast;
use App\Enums\AffiliateNetwork;
use App\Enums\AffiliateNetworkAccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model representing affiliate network logins (ClickBank, Digistore24, MaxWeb, etc.).
 *
 * NOTE: Passwords are encrypted with AES-256-CBC using the dedicated
 * GOOGLE_ACCOUNTS_ENCRYPTION_KEY environment key. Losing this key makes
 * stored passwords permanently unrecoverable.
 */
class AffiliateNetworkAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'network',
        'network_custom',
        'nickname',
        'email',
        'username',
        'password',
        'account_id',
        'status',
        'login_url',
        'notes',
        'default_currency',
        'payment_method_hint',
    ];

    /**
     * Never serialize the raw password into arrays or JSON responses.
     */
    protected $hidden = [
        'password',
    ];

    protected $appends = [
        'has_password',
        'display_network_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'network' => AffiliateNetwork::class,
            'status' => AffiliateNetworkAccountStatus::class,
            'password' => GoogleAccountPasswordCast::class,
        ];
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
     * Display label for network (resolves custom name if network is Other).
     */
    protected function displayNetworkName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if ($this->network === AffiliateNetwork::Other && ! empty($this->network_custom)) {
                    return $this->network_custom;
                }

                if ($this->network instanceof AffiliateNetwork) {
                    return $this->network->label();
                }

                return ucfirst((string) $this->getRawOriginal('network'));
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
     * Scope query to filter by network, status, or search query.
     *
     * @param  Builder<AffiliateNetworkAccount>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, function (Builder $query, string $status): void {
            if ($status !== 'all' && $status !== '') {
                $query->where('status', $status);
            }
        });

        $query->when($filters['network'] ?? null, function (Builder $query, string $network): void {
            if ($network !== 'all' && $network !== '') {
                $query->where('network', $network);
            }
        });

        $query->when($filters['search'] ?? null, function (Builder $query, string $search): void {
            $clean = trim($search);
            if ($clean === '') {
                return;
            }

            $query->where(function (Builder $q) use ($clean): void {
                $q->where('nickname', 'like', '%'.$clean.'%')
                    ->orWhere('email', 'like', '%'.$clean.'%')
                    ->orWhere('username', 'like', '%'.$clean.'%')
                    ->orWhere('account_id', 'like', '%'.$clean.'%')
                    ->orWhere('network_custom', 'like', '%'.$clean.'%')
                    ->orWhere('notes', 'like', '%'.$clean.'%');
            });
        });
    }
}
