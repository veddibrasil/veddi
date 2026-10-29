<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido do cliente na 99Food pra cancelar (orderCancelApply) ou reembolsar (orderRefundApply)
 * um pedido. A loja aceita ou recusa até expires_at; sem resposta, a 99 decide: cancelamento
 * vira recusado, reembolso vira aceito.
 */
class Food99Request extends Model
{
    use BelongsToCompany;

    public const KIND_CANCEL = 'cancel';

    public const KIND_REFUND = 'refund';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    /** Prazo da loja pra responder, a partir do primeiro callback. */
    public const DEADLINE_MINUTES = [
        self::KIND_CANCEL => 5,
        self::KIND_REFUND => 24 * 60,
    ];

    protected $fillable = [
        'company_id',
        'food99_integration_id',
        'order_id',
        'external_order_id',
        'kind',
        'apply_id',
        'apply_reason',
        'reason_options',
        'images',
        'expires_at',
        'last_seen_at',
        'status',
        'responded_by',
        'responded_at',
        'response_reason',
    ];

    protected $casts = [
        'reason_options' => 'array',
        'images' => 'array',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function food99Integration(): BelongsTo
    {
        return $this->belongsTo(Food99Integration::class);
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_CANCEL ? 'Cancelamento' : 'Reembolso';
    }

    /** O que a 99 faz se a loja não responder a tempo. */
    public function timeoutOutcome(): string
    {
        return $this->kind === self::KIND_CANCEL ? self::STATUS_REJECTED : self::STATUS_ACCEPTED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => $this->isExpired() ? 'Prazo encerrado' : 'Aguardando resposta',
            self::STATUS_ACCEPTED => 'Aceita',
            self::STATUS_REJECTED => 'Recusada',
            self::STATUS_EXPIRED => $this->timeoutOutcome() === self::STATUS_ACCEPTED
                ? 'Sem resposta: aceita automaticamente pela 99Food'
                : 'Sem resposta: recusada automaticamente pela 99Food',
            default => $this->status,
        };
    }
}
