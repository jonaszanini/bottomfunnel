<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleLoginAccountCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'google_login_account_id',
        'campaign_id',
        'campaign_name',
        'status',
    ];

    /**
     * @return BelongsTo<GoogleLoginAccount, $this>
     */
    public function googleLoginAccount(): BelongsTo
    {
        return $this->belongsTo(GoogleLoginAccount::class, 'google_login_account_id');
    }
}
