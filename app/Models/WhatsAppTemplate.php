<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_PAUSED = 'PAUSED';

    public const STATUS_DISABLED = 'DISABLED';

    /**
     * Status que a Meta informa (webhook e listagem) → status guardado. FLAGGED, LIMIT_EXCEEDED
     * e afins ficam de fora: não mudam a possibilidade de uso do template.
     */
    private const META_STATUS_MAP = [
        'APPROVED' => self::STATUS_APPROVED,
        'REINSTATED' => self::STATUS_APPROVED,
        'REJECTED' => self::STATUS_REJECTED,
        'PENDING' => self::STATUS_PENDING,
        'IN_APPEAL' => self::STATUS_PENDING,
        'PAUSED' => self::STATUS_PAUSED,
        'DISABLED' => self::STATUS_DISABLED,
        'PENDING_DELETION' => self::STATUS_DISABLED,
        'DELETED' => self::STATUS_DISABLED,
        'ARCHIVED' => self::STATUS_DISABLED,
    ];

    /** Evento do template (chave de config/whatsapp_templates.php) → rótulo para o restaurante. */
    public const EVENT_LABELS = [
        'new_order' => 'Pedido recebido',
        'awaiting_payment' => 'Aguardando pagamento',
        'paid' => 'Pagamento confirmado',
        'scheduled' => 'Pedido agendado',
        'preparing' => 'Em preparo',
        'ready' => 'Pronto',
        'ready_pickup' => 'Pronto para retirada',
        'ready_delivery' => 'Pronto para entrega',
        'out_for_delivery' => 'Saiu para entrega',
        'delivered' => 'Entregue',
        'cancelled' => 'Cancelado',
        'refunded' => 'Reembolsado',
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Em análise',
        self::STATUS_APPROVED => 'Aprovado',
        self::STATUS_REJECTED => 'Rejeitado',
        self::STATUS_PAUSED => 'Pausado',
        self::STATUS_DISABLED => 'Desativado',
    ];

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'whatsapp_connection_id',
        'event',
        'name',
        'language',
        'meta_template_id',
        'status',
        'rejection_reason',
    ];

    /** Status da Meta traduzido para o nosso, ou null se não deve alterar o template. */
    public static function mapMetaStatus(string $metaStatus): ?string
    {
        return self::META_STATUS_MAP[strtoupper($metaStatus)] ?? null;
    }

    public static function eventLabel(string $event): string
    {
        return self::EVENT_LABELS[$event] ?? $event;
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
