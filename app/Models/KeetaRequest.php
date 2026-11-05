<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido do cliente na Keeta pra cancelar (CANCELLATION_REQUESTED) ou reembolsar
 * (USER_REFUND_REQUEST) um pedido. Reembolso: a loja aceita (acceptRefund) ou recusa
 * (rejectRefund) e vale a primeira resposta, pela API ou pelo portal. Cancelamento: a decisão
 * é da Keeta; o painel só avisa e o evento CANCELLED é que cancela o pedido.
 */
class KeetaRequest extends Model
{
    use BelongsToCompany;

    public const KIND_CANCEL = 'cancel';

    public const KIND_REFUND = 'refund';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /** Decidida na Keeta (REFUNDED/REFUND_FAILED/CANCELLED) sem passar por esta tela. */
    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Prazo exibido pra loja responder. [VALIDAR Keeta] a doc não informa o prazo do reembolso;
     * 24 h segue o da 99Food até a SIT confirmar.
     */
    public const DEADLINE_MINUTES = [
        self::KIND_CANCEL => 5,
        self::KIND_REFUND => 24 * 60,
    ];

    /** Motivos de recusa de reembolso aceitos pela Keeta (RequestDenied.code). */
    public const REJECT_CODES = [
        'DISH_ALREADY_DONE' => 'Pedido já preparado',
        'OUT_FOR_DELIVERY' => 'Pedido já saiu para entrega',
        'OTHER' => 'Outro motivo',
    ];

    protected $fillable = [
        'company_id',
        'keeta_integration_id',
        'order_id',
        'external_order_id',
        'kind',
        'event_id',
        'reason',
        'amount',
        'items',
        'expires_at',
        'status',
        'responded_by',
        'responded_at',
        'response_reason',
    ];

    protected $casts = [
        'items' => 'array',
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function keetaIntegration(): BelongsTo
    {
        return $this->belongsTo(KeetaIntegration::class);
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

    /** Só reembolso tem resposta da loja pela API. */
    public function isAnswerable(): bool
    {
        return $this->kind === self::KIND_REFUND && $this->isOpen();
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_CANCEL ? 'Cancelamento' : 'Reembolso';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => match (true) {
                $this->kind === self::KIND_CANCEL => 'Em análise na Keeta',
                $this->isExpired() => 'Prazo encerrado',
                default => 'Aguardando resposta',
            },
            self::STATUS_ACCEPTED => 'Aceita',
            self::STATUS_REJECTED => 'Recusada',
            self::STATUS_RESOLVED => 'Decidida pela Keeta',
            self::STATUS_EXPIRED => 'Sem resposta no prazo',
            default => $this->status,
        };
    }
}
