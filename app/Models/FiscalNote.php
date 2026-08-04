<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalNote extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'order_id',
        'status',
        'provider_reference',
        'access_key',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function getDanfeUrlAttribute(): ?string
    {
        return $this->data['danfe_url'] ?? null;
    }

    public function getXmlUrlAttribute(): ?string
    {
        return $this->data['xml_url'] ?? null;
    }

    public function getErrorMessageAttribute(): ?string
    {
        // Focus NFe preenche mensagem_sefaz mesmo em nota autorizada (ex.: "Autorizado
        // o uso da NF-e"), então só é erro de fato quando o status também é de falha.
        if (! in_array($this->status, ['rejected', 'error'], true)) {
            return null;
        }

        return $this->data['error_message'] ?? null;
    }

    /**
     * Resposta bruta da Focus NFe (JSON completo do body) para inspeção de erro —
     * mensagem curta em error_message costuma esconder detalhes como o campo
     * específico rejeitado ou o código do erro da SEFAZ.
     *
     * @return array<string, mixed>
     */
    public function getRawResponseAttribute(): array
    {
        return $this->data['raw_response'] ?? [];
    }

    /**
     * Payload enviado à Focus NFe na tentativa de emissão — só existe quando a nota
     * já foi para o provider (não em falhas anteriores, como validação local).
     *
     * @return array<string, mixed>
     */
    public function getRequestPayloadAttribute(): array
    {
        return $this->data['request_payload'] ?? [];
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'authorized']);
    }

    /**
     * Cancelamento na SEFAZ só se aplica a nota já autorizada — "pending" ainda está
     * em processamento assíncrono na Focus/SEFAZ, cancelar nesse estado só rende um
     * erro genérico da API em vez de deixar claro que é preciso esperar a autorização.
     */
    public function isCancellable(): bool
    {
        return $this->status === 'authorized';
    }
}
