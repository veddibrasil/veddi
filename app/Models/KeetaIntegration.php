<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Loja da Keeta ligada a uma filial. O app parceiro e o token são um só pra plataforma
 * (config/keeta.php, KeetaAuthService); aqui fica o que é da loja: o nosso merchantId
 * (merchant_ref), o id da loja na Keeta e a chave do endpoint de cardápio.
 */
class KeetaIntegration extends Model
{
    use BelongsToCompany;

    public const STATUS_DISCONNECTED = 'disconnected';

    /** Link de autorização gerado, esperando o lojista autorizar no portal da Keeta. */
    public const STATUS_PENDING_AUTHORIZATION = 'pending_authorization';

    /** Autorizou: falta escolher qual loja da autorização é desta filial. */
    public const STATUS_PENDING_MAPPING = 'pending_mapping';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    /** O lojista revogou a autorização no portal da Keeta (webhook 1302). */
    public const STATUS_REVOKED = 'revoked';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'company_id',
        'branch_id',
        'merchant_ref',
        'keeta_merchant_id',
        'shop_name',
        'shop_address',
        'auth_id',
        'available_shops',
        'authorization_state',
        'status',
        'activated_at',
        'menu_api_key',
        'auto_accept',
        'store_status',
        'store_status_updated_at',
        'webhook_status',
        'last_webhook_received_at',
        'last_polled_at',
        'last_menu_fetched_at',
        'catalog_published_at',
        'catalog_sync_status',
        'catalog_sync_error',
        'catalog_synced_at',
        'alerts_sent',
    ];

    protected $hidden = ['menu_api_key', 'authorization_state'];

    protected $casts = [
        'menu_api_key' => 'encrypted',
        'available_shops' => 'array',
        'auto_accept' => 'boolean',
        'activated_at' => 'datetime',
        'store_status_updated_at' => 'datetime',
        'last_webhook_received_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'last_menu_fetched_at' => 'datetime',
        'catalog_published_at' => 'datetime',
        'catalog_synced_at' => 'datetime',
        'alerts_sent' => 'array',
    ];

    /**
     * Nosso merchantId no Open Delivery: a Keeta exige 36 a 100 caracteres e recomenda UUID.
     * Leva empresa e filial pra ficar legível no suporte e um UUID pra nunca repetir (reenviar o
     * onboarding com um id já usado apaga o mapeamento anterior na Keeta).
     */
    public static function merchantRefFor(int $companyId, int $branchId): string
    {
        return "veddi-{$companyId}-{$branchId}-".Str::uuid();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(KeetaWebhookEvent::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(KeetaRequest::class);
    }

    /**
     * Integrações que podem operar fora de request (webhook, jobs, cardápio): ativas, com loja
     * ligada e de empresa liberada. O gate do plano fica em isOperational(), que não cabe em SQL.
     *
     * $includePaused: suspensa não recebe pedido novo, mas eventos de pedido já em andamento
     * (cancelamento, solicitação do cliente, conclusão) ainda valem.
     */
    public function scopeOperational(Builder $query, bool $includePaused = false): Builder
    {
        return $query->withoutGlobalScopes()
            ->whereIn('status', $includePaused ? [self::STATUS_ACTIVE, self::STATUS_PAUSED] : [self::STATUS_ACTIVE])
            ->whereNotNull('keeta_merchant_id')
            ->whereHas('company', fn (Builder $company) => $company->whereIn('status', ['ACTIVE', 'OVERDUE']));
    }

    public function isOperational(bool $includePaused = false): bool
    {
        $company = $this->company;

        return in_array($this->status, $includePaused ? [self::STATUS_ACTIVE, self::STATUS_PAUSED] : [self::STATUS_ACTIVE], true)
            && $this->keeta_merchant_id !== null
            && $company !== null
            && ($company->isActive() || $company->isOverdue())
            && $company->canUseMarketplaceIntegrations();
    }

    public function isLinked(): bool
    {
        return $this->keeta_merchant_id !== null;
    }

    /** Sem nenhum evento há $hours horas; conta da ativação até chegar o primeiro. */
    public function isWebhookSilent(int $hours): bool
    {
        $last = $this->last_webhook_received_at ?? $this->activated_at;

        return $last !== null && $last->lt(now()->subHours($hours));
    }

    /** Cardápio liberado pelo restaurante para a Keeta buscar (publicação é opt-in). */
    public function hasPublishedCatalog(): bool
    {
        return $this->catalog_published_at !== null;
    }

    /** Loja aberta na Keeta pelo último merchantStatus enviado. Sem informação, considera aberta. */
    public function isStoreOpen(): bool
    {
        return $this->store_status === null || $this->store_status === 'AVAILABLE';
    }

    /** URL do nosso GET merchant desta loja (a Keeta busca o cardápio aqui). */
    public function menuUrl(): string
    {
        return route('integrations.keeta.merchant', ['merchantRef' => $this->merchant_ref]);
    }
}
