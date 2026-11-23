<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFiscalConfig extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'legal_name',
        'issuer_cnpj',
        'emission_environment',
        'is_default',
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
        'nfe_enabled',
        'nfe_serie',
        'nfce_cnpj_enabled',
        'auto_issue_enabled',
        'auto_issue_enabled_at',
        'auto_issue_paused_at',
        'auto_issue_pause_reason',
        'auto_issue_resumed_at',
        'certificate_path',
        'certificate_password',
    ];

    /**
     * NF-e sempre habilitada. NFC-e com CNPJ só quando a filial for liberada
     * (FiscalEmissionPolicy); desligada, CNPJ no destinatário emite NF-e.
     * Emissão automática nasce ligada em filial nova; a valia real exige a emissão habilitada
     * (autoIssueActive), então ligar aqui não emite nada antes da configuração completa.
     */
    protected $attributes = [
        'nfe_enabled' => true,
        'nfce_cnpj_enabled' => false,
        'auto_issue_enabled' => true,
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_default' => 'boolean',
        'crt' => 'integer',
        'nfce_serie' => 'integer',
        'nfe_enabled' => 'boolean',
        'nfe_serie' => 'integer',
        'nfce_cnpj_enabled' => 'boolean',
        'auto_issue_enabled' => 'boolean',
        'auto_issue_enabled_at' => 'datetime',
        'auto_issue_paused_at' => 'datetime',
        'auto_issue_resumed_at' => 'datetime',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    /**
     * Emissão automática de NFC-e valendo agora nesta filial: configuração habilitada, chave
     * ligada e disjuntor fechado (FiscalAutoIssuePolicy, FiscalAutoIssueMonitor).
     */
    public function autoIssueActive(): bool
    {
        return $this->enabled && $this->auto_issue_enabled && $this->auto_issue_paused_at === null;
    }

    /** Início da contagem do disjuntor: a ativação ou a última retomada, o que vier por último. */
    public function autoIssueBreakerSince(): ?\Carbon\CarbonInterface
    {
        return collect([$this->auto_issue_enabled_at, $this->auto_issue_resumed_at])->filter()->max();
    }

    public function isRegisteredWithFocus(): bool
    {
        return filled($this->focus_nfe_company_id);
    }

    public function isIssuerLocked(): bool
    {
        return $this->isRegisteredWithFocus() || ($this->exists && FiscalNote::withoutGlobalScopes()
            ->where('company_id', $this->company_id)
            ->where(fn ($query) => $query->where('fiscal_config_id', $this->id)->orWhere('branch_id', $this->branch_id))
            ->exists());
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
