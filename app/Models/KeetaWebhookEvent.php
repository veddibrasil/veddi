<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeetaWebhookEvent extends Model
{
    // Sem BelongsToCompany: o evento chega antes de qualquer contexto de empresa (e o do polling
    // nem traz a loja). O isolamento é feito via keeta_integration_id.

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_POLLING = 'polling';

    /** Webhook de autorização do Dev Portal (1301 nova autorização, 1302 revogação). */
    public const SOURCE_AUTHORIZATION = 'authorization';

    protected $fillable = [
        'event_id',
        'keeta_integration_id',
        'event_type',
        'external_order_id',
        'order_id',
        'source',
        'payload',
        'status',
        'attempts',
        'error',
        'acknowledged_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'acknowledged_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * Sem escopo de empresa: o evento é processado fora de request (job), onde o
     * current.company pode ainda não ser o da integração. O dono é a própria integração.
     */
    public function keetaIntegration(): BelongsTo
    {
        return $this->belongsTo(KeetaIntegration::class)->withoutGlobalScopes();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * metadata do evento: a Keeta manda um JSON em string (cancelamento, reembolso, previsão do
     * entregador). Vem decodificado aqui; ausente ou inválido vira [].
     */
    public function metadata(): array
    {
        $metadata = $this->payload['metadata'] ?? null;

        if (is_string($metadata) && $metadata !== '') {
            $metadata = json_decode($metadata, true, 512, JSON_BIGINT_AS_STRING);
        }

        return is_array($metadata) ? $metadata : [];
    }
}
