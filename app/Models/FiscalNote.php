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

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'authorized']);
    }
}
