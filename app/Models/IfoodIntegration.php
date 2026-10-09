<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
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
        'merchant_name',
        'available_merchants',
        'catalog_id',
        'catalog_merchant_id',
        'catalog_published_at',
        'catalog_sync_status',
        'catalog_sync_error',
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
        'last_polled_at',
        'last_webhook_received_at',
        'alerts_sent',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'authorization_code_verifier' => 'encrypted',
        'available_merchants' => 'array',
        'token_expires_at' => 'datetime',
        'user_code_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'last_webhook_received_at' => 'datetime',
        'catalog_published_at' => 'datetime',
        'alerts_sent' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orderEvents(): HasMany
    {
        return $this->hasMany(IfoodOrderEvent::class);
    }

    /**
     * Integrações que podem operar fora de request (polling, webhook, presença, cardápio):
     * ativas, com loja escolhida e de empresa liberada. Empresa bloqueada ou pendente de
     * pagamento cai no faturamento ao abrir o painel — ninguém aceitaria o pedido, então a loja
     * sai do ar no iFood em vez de receber pedidos que o próprio iFood cancelaria.
     * O gate do módulo (plano) é conferido em isOperational(), que não cabe em SQL.
     *
     * $includePaused: suspensa não recebe pedido novo nem fica presente no iFood, mas eventos de
     * pedido já em andamento (cancelamento do cliente, negociação, conclusão) ainda valem.
     */
    public function scopeOperational(Builder $query, bool $includePaused = false): Builder
    {
        return $query->withoutGlobalScopes()
            ->whereIn('status', $includePaused ? ['active', 'paused'] : ['active'])
            ->whereNotNull('merchant_id')
            ->whereHas('company', fn (Builder $company) => $company->whereIn('status', ['ACTIVE', 'OVERDUE']));
    }

    public function isOperational(bool $includePaused = false): bool
    {
        $company = $this->company;

        return in_array($this->status, $includePaused ? ['active', 'paused'] : ['active'], true)
            && $this->merchant_id !== null
            && $company !== null
            && ($company->isActive() || $company->isOverdue())
            && $company->canUseIfoodIntegration();
    }

    /** Cardápio liberado pelo restaurante para envio automático (publicação é opt-in). */
    public function hasPublishedCatalog(): bool
    {
        return $this->catalog_published_at !== null;
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->isPast();
    }

    public function isPendingAuthorization(): bool
    {
        return $this->user_code !== null && $this->merchant_id === null;
    }

    /** Autorização concluída; falta consultar ou selecionar a loja antes de ativar. */
    public function isPendingMerchantSelection(): bool
    {
        return $this->merchant_id === null && (! empty($this->available_merchants)
            || ($this->access_token !== null && $this->user_code === null && $this->status === 'disconnected'));
    }

    public function isUserCodeExpired(): bool
    {
        return $this->user_code_expires_at !== null && $this->user_code_expires_at->isPast();
    }
}
