<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IfoodIntegration extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'branch_id',
        'merchant_id',
        'available_merchants',
        'catalog_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'user_code',
        'authorization_code_verifier',
        'verification_url',
        'user_code_expires_at',
        'status',
        'webhook_status',
        'last_synced_at',
        'last_webhook_received_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'authorization_code_verifier' => 'encrypted',
        'available_merchants' => 'array',
        'token_expires_at' => 'datetime',
        'user_code_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_webhook_received_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orderEvents(): HasMany
    {
        return $this->hasMany(IfoodOrderEvent::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->isPast();
    }

    public function isPendingAuthorization(): bool
    {
        return $this->user_code !== null && $this->merchant_id === null;
    }

    /** Token trocado com sucesso mas a autorização cobriu mais de uma loja — precisa escolher qual antes de ativar. */
    public function isPendingMerchantSelection(): bool
    {
        return $this->merchant_id === null && ! empty($this->available_merchants);
    }

    public function isUserCodeExpired(): bool
    {
        return $this->user_code_expires_at !== null && $this->user_code_expires_at->isPast();
    }
}
