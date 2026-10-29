<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Loja da 99Food ligada a uma filial. O app parceiro é um só pra plataforma
 * (config/food99.php); aqui fica o que é da loja: shop_id, o nosso app_shop_id e o auth_token.
 */
class Food99Integration extends Model
{
    use BelongsToCompany;

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_PENDING_AUTHORIZATION = 'pending_authorization';

    public const STATUS_PENDING_SHOP_SELECTION = 'pending_shop_selection';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'company_id',
        'branch_id',
        'app_shop_id',
        'shop_id',
        'shop_name',
        'available_shops',
        'auth_token',
        'token_expires_at',
        'last_token_refreshed_at',
        'authorization_url',
        'authorization_url_expires_at',
        'status',
        'order_confirm_method',
        'receive_cancel_apply',
        'receive_refund_apply',
        'store_status',
        'sub_biz_status',
        'store_status_updated_at',
        'webhook_status',
        'last_webhook_received_at',
        'last_request_id',
        'catalog_published_at',
        'catalog_sync_status',
        'catalog_sync_error',
        'catalog_task_id',
        'catalog_synced_at',
        'alerts_sent',
    ];

    protected $hidden = ['auth_token'];

    protected $casts = [
        'auth_token' => 'encrypted',
        'available_shops' => 'array',
        'token_expires_at' => 'datetime',
        'last_token_refreshed_at' => 'datetime',
        'authorization_url_expires_at' => 'datetime',
        'receive_cancel_apply' => 'boolean',
        'receive_refund_apply' => 'boolean',
        'store_status' => 'integer',
        'sub_biz_status' => 'integer',
        'order_confirm_method' => 'integer',
        'store_status_updated_at' => 'datetime',
        'last_webhook_received_at' => 'datetime',
        'catalog_published_at' => 'datetime',
        'catalog_synced_at' => 'datetime',
        'alerts_sent' => 'array',
    ];

    /** Id da filial na 99 (o "app_shop_id" do parceiro): estável, sem espaço, único na plataforma. */
    public static function appShopIdFor(int $companyId, int $branchId): string
    {
        return "veddi-{$companyId}-{$branchId}";
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(Food99WebhookEvent::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Food99Request::class);
    }

    /**
     * Integrações que podem operar fora de request (webhook, jobs, cardápio): ativas, com loja
     * escolhida e de empresa liberada. Mesmo critério do iFood (IfoodIntegration::scopeOperational);
     * o gate do plano fica em isOperational(), que não cabe em SQL.
     *
     * $includePaused: suspensa não recebe pedido novo, mas eventos de pedido já em andamento
     * (cancelamento, solicitação do cliente, conclusão) ainda valem.
     */
    public function scopeOperational(Builder $query, bool $includePaused = false): Builder
    {
        return $query->withoutGlobalScopes()
            ->whereIn('status', $includePaused ? [self::STATUS_ACTIVE, self::STATUS_PAUSED] : [self::STATUS_ACTIVE])
            ->whereNotNull('shop_id')
            ->whereHas('company', fn (Builder $company) => $company->whereIn('status', ['ACTIVE', 'OVERDUE']));
    }

    public function isOperational(bool $includePaused = false): bool
    {
        $company = $this->company;

        return in_array($this->status, $includePaused ? [self::STATUS_ACTIVE, self::STATUS_PAUSED] : [self::STATUS_ACTIVE], true)
            && $this->shop_id !== null
            && $company !== null
            && ($company->isActive() || $company->isOverdue())
            && $company->canUseMarketplaceIntegrations();
    }

    /** Cardápio liberado pelo restaurante para envio automático (publicação é opt-in). */
    public function hasPublishedCatalog(): bool
    {
        return $this->catalog_published_at !== null;
    }

    /** Token ausente ou vencendo dentro da margem de renovação. */
    public function tokenNeedsRefresh(?int $marginSeconds = null): bool
    {
        $margin = $marginSeconds ?? (int) config('food99.token_refresh_margin_seconds', 300);

        return $this->auth_token === null
            || $this->token_expires_at === null
            || $this->token_expires_at->lte(now()->addSeconds($margin));
    }

    public function isAuthorizationUrlValid(): bool
    {
        return $this->authorization_url !== null
            && ($this->authorization_url_expires_at === null || $this->authorization_url_expires_at->isFuture());
    }

    public function isPendingShopSelection(): bool
    {
        return $this->shop_id === null && $this->status === self::STATUS_PENDING_SHOP_SELECTION;
    }

    /** Loja aberta na 99 (sub_biz_status 1). Sem informação ainda, considera aberta. */
    public function isStoreOpen(): bool
    {
        return $this->sub_biz_status === null || $this->sub_biz_status === 1;
    }
}
