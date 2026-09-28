<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Negociação aberta pelo cliente na Plataforma de Negociação do iFood (evento
 * HANDSHAKE_DISPUTE): pedido de cancelamento, cancelamento parcial ou reembolso.
 * A loja aceita, recusa ou responde com uma das alternativas até expires_at; depois
 * disso vale o timeout_action. O desfecho chega no evento HANDSHAKE_SETTLEMENT.
 */
class IfoodDispute extends Model
{
    use BelongsToCompany;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'company_id',
        'ifood_integration_id',
        'order_id',
        'dispute_id',
        'ifood_order_id',
        'action',
        'handshake_type',
        'timeout_action',
        'message',
        'expires_at',
        'alternatives',
        'metadata',
        'status',
        'response',
        'response_reason',
        'responded_by',
        'responded_at',
        'settlement',
        'settled_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'alternatives' => 'array',
        'metadata' => 'array',
        'settlement' => 'array',
        'responded_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ifoodIntegration(): BelongsTo
    {
        return $this->belongsTo(IfoodIntegration::class);
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /** Ainda dá pra responder: sem resposta enviada, sem desfecho e dentro do prazo. */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'CANCELLATION' => 'Cliente pediu o cancelamento do pedido',
            'PARTIAL_CANCELLATION' => 'Cliente pediu o cancelamento de parte do pedido',
            'PROPOSED_AMOUNT_REFUND' => 'Cliente pediu reembolso de parte do valor',
            default => 'Cliente abriu uma negociação',
        };
    }

    /** O que o iFood faz se a loja não responder a tempo. */
    public function timeoutLabel(): ?string
    {
        return match (true) {
            $this->timeout_action === null => null,
            str_starts_with($this->timeout_action, 'ACCEPT') => 'Sem resposta no prazo, o iFood aceita o pedido do cliente.',
            str_starts_with($this->timeout_action, 'REJECT') => 'Sem resposta no prazo, o iFood recusa o pedido do cliente.',
            default => 'Sem resposta no prazo, o iFood encerra a negociação.',
        };
    }

    /** Motivos fechados que o iFood exige ao aceitar, quando a disputa traz essa lista. */
    public function acceptReasons(): array
    {
        return array_values(array_filter((array) ($this->metadata['acceptCancellationReasons'] ?? []), 'is_string'));
    }

    public function settlementLabel(): ?string
    {
        $status = $this->settlement['status'] ?? null;

        return match ($status) {
            null => null,
            'ACCEPTED' => 'Aceita',
            'REJECTED' => 'Recusada',
            'EXPIRED' => 'Expirada sem resposta',
            'ALTERNATIVE_REPLIED' => 'Contraproposta enviada ao cliente',
            default => $status,
        };
    }
}
