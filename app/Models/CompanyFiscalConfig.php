<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFiscalConfig extends Model
{
    protected $fillable = [
        'company_id',
        'enabled',
        'inscricao_estadual',
        'inscricao_municipal',
        'crt',
        'provider',
        'provider_token',
        'token_producao',
        'token_homologacao',
        'focus_nfe_company_id',
        'focus_nfe_webhook_id',
        'webhook_token',
        'focus_nfe_registered_at',
        'csc_nfce_producao',
        'id_token_nfce_producao',
        'environment',
        'nfce_serie',
        'certificate_path',
        'certificate_password',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'crt' => 'integer',
        'nfce_serie' => 'integer',
        'provider_token' => 'encrypted',
        'token_producao' => 'encrypted',
        'token_homologacao' => 'encrypted',
        'certificate_password' => 'encrypted',
        'csc_nfce_producao' => 'encrypted',
        'webhook_token' => 'encrypted',
        'focus_nfe_registered_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Token específico do ambiente (Focus NFe emite token_producao e token_homologacao
     * distintos ao registrar a empresa via /v2/empresas). Cai para provider_token
     * quando a empresa ainda não foi registrada por essa via (fallback legado).
     */
    public function tokenFor(string $environment): ?string
    {
        $token = $environment === 'producao' ? $this->token_producao : $this->token_homologacao;

        return $token ?: $this->provider_token;
    }

    public function isRegisteredWithFocus(): bool
    {
        return filled($this->focus_nfe_company_id);
    }

    /**
     * Segredo esperado no header do webhook Focus NFe para esta empresa. Cai para o
     * token global (config/fiscal.php) quando a empresa ainda não tem um próprio —
     * fallback legado/registro em andamento, não pensado como padrão permanente.
     */
    public function resolveWebhookToken(): ?string
    {
        return $this->webhook_token ?: config('fiscal.focus_nfe.webhook_token');
    }
}
