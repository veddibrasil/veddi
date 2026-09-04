<?php

namespace App\Livewire\Admin\Settings;

use App\Jobs\SyncIfoodCatalogJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\IfoodIntegration;
use App\Services\Ifood\IfoodAuthService;
use Livewire\Component;

class IfoodIntegrationSettings extends Component
{
    // Cada filial tem sua própria conexão com o iFood (merchant_id/tokens próprios),
    // mesmo padrão de App\Livewire\Admin\Fiscal\Config — trocar a filial selecionada
    // troca o registro inteiro carregado.
    public ?int $branchId = null;

    /** @var array<int, array{id: int, name: string}> */
    public array $branchOptions = [];

    /** not_connected | pending_authorization | pending_merchant_selection | connected */
    public string $connectionState = 'not_connected';

    public ?string $userCode = null;

    public ?string $verificationUrl = null;

    public ?string $userCodeExpiresAt = null;

    public string $authorizationCode = '';

    public ?string $merchantId = null;

    /** @var array<int, array{id: string, name: ?string}> */
    public array $availableMerchants = [];

    public string $selectedMerchantId = '';

    public string $status = 'active';

    public string $webhookStatus = 'unknown';

    public ?string $lastSyncedAt = null;

    public ?string $lastWebhookReceivedAt = null;

    public function mount(): void
    {
        $company = app('current.company');

        $branches = Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get();
        $this->branchOptions = $branches->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->all();

        // Começa pela primeira filial que já tem integração cadastrada; sem nenhuma,
        // cai na primeira filial da empresa como ponto de partida.
        $existing = IfoodIntegration::where('company_id', $company->id)->first();
        $branch = $existing
            ? $branches->firstWhere('id', $existing->branch_id)
            : $branches->first();

        $this->branchId = $branch?->id;
        $this->loadForBranch($company, $branch);
    }

    public function updatedBranchId(): void
    {
        $company = app('current.company');
        $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId);

        if (! $branch) {
            $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->first();
            $this->branchId = $branch?->id;
        }

        $this->loadForBranch($company, $branch);
    }

    public function connect(): void
    {
        $company = app('current.company');

        $branch = $this->branchId
            ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId)
            : null;

        if (! $branch) {
            $this->addError('branchId', 'Selecione uma filial antes de conectar.');

            return;
        }

        $integration = IfoodIntegration::where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->first() ?? new IfoodIntegration(['company_id' => $company->id, 'branch_id' => $branch->id]);

        try {
            app(IfoodAuthService::class)->requestUserCode($integration);
        } catch (\Throwable) {
            session()->flash('error', 'Não foi possível iniciar a conexão com o iFood. Tente novamente em instantes.');

            return;
        }

        $this->loadForBranch($company, $branch);
    }

    public function confirmAuthorization(): void
    {
        $company = app('current.company');
        $branch = $this->branchId
            ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId)
            : null;

        $integration = $branch
            ? IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first()
            : null;

        if (! $integration || ! $integration->isPendingAuthorization()) {
            $this->loadForBranch($company, $branch);

            return;
        }

        if ($integration->isUserCodeExpired()) {
            session()->flash('error', 'O código expirou. Clique em conectar novamente pra gerar um novo.');
            $this->loadForBranch($company, $branch);

            return;
        }

        $this->validate(['authorizationCode' => ['required', 'string']]);

        try {
            app(IfoodAuthService::class)->completeAuthorization($integration, $this->authorizationCode);
        } catch (\Throwable) {
            $this->addError('authorizationCode', 'Código inválido ou expirado. Confira o que o iFood mostrou e tente de novo.');

            return;
        }

        $this->authorizationCode = '';
        $integration->refresh();

        if ($integration->merchant_id !== null) {
            // Cardápio começa vazio do lado do iFood até o próximo sync — dispara na
            // hora em vez de esperar o batch diário (routes/console.php, 05:00).
            SyncIfoodCatalogJob::dispatch($branch->id);
            session()->flash('status', 'Integração com o iFood conectada com sucesso. Sincronizando cardápio...');
        } else {
            session()->flash('status', 'Autorização concluída — escolha a loja pra ativar a integração.');
        }

        $this->loadForBranch($company, $branch);
    }

    public function selectMerchant(): void
    {
        $company = app('current.company');
        $branch = $this->branchId
            ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId)
            : null;

        $integration = $branch
            ? IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first()
            : null;

        if (! $integration || ! $integration->isPendingMerchantSelection()) {
            $this->loadForBranch($company, $branch);

            return;
        }

        $this->validate(['selectedMerchantId' => ['required', 'string']]);

        try {
            app(IfoodAuthService::class)->selectMerchant($integration, $this->selectedMerchantId);
        } catch (\Throwable) {
            $this->addError('selectedMerchantId', 'Não foi possível confirmar essa loja. Tente novamente.');

            return;
        }

        // Cardápio começa vazio do lado do iFood até o próximo sync — dispara na
        // hora em vez de esperar o batch diário (routes/console.php, 05:00).
        SyncIfoodCatalogJob::dispatch($branch->id);

        session()->flash('status', 'Loja confirmada — integração com o iFood ativa. Sincronizando cardápio...');
        $this->loadForBranch($company, $branch);
    }

    public function syncCatalogNow(): void
    {
        $company = app('current.company');
        $branch = $this->branchId
            ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId)
            : null;

        $integration = $branch
            ? IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first()
            : null;

        if (! $integration || $integration->merchant_id === null) {
            return;
        }

        SyncIfoodCatalogJob::dispatch($branch->id);
        session()->flash('status', 'Sincronização de cardápio disparada.');
        $this->loadForBranch($company, $branch);
    }

    public function pause(): void
    {
        $this->setStatus('paused');
    }

    public function resume(): void
    {
        $this->setStatus('active');
    }

    public function disconnect(): void
    {
        $company = app('current.company');
        $branch = $this->branchId
            ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId)
            : null;

        $integration = $branch
            ? IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first()
            : null;

        if ($integration) {
            $integration->update([
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'merchant_id' => null,
                'available_merchants' => null,
                'user_code' => null,
                'authorization_code_verifier' => null,
                'verification_url' => null,
                'user_code_expires_at' => null,
                'status' => 'disconnected',
            ]);
        }

        session()->flash('status', 'Integração com o iFood desconectada.');
        $this->loadForBranch($company, $branch);
    }

    private function setStatus(string $status): void
    {
        $company = app('current.company');
        $branch = $this->branchId
            ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId)
            : null;

        $integration = $branch
            ? IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first()
            : null;

        if ($integration && $integration->merchant_id) {
            $integration->update(['status' => $status]);
        }

        $this->loadForBranch($company, $branch);
    }

    private function loadForBranch(Company $company, ?Branch $branch): void
    {
        $integration = $branch
            ? IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first()
            : null;

        $this->connectionState = match (true) {
            $integration && $integration->merchant_id !== null => 'connected',
            $integration && $integration->isPendingMerchantSelection() => 'pending_merchant_selection',
            $integration && $integration->isPendingAuthorization() && ! $integration->isUserCodeExpired() => 'pending_authorization',
            default => 'not_connected',
        };

        $this->authorizationCode = '';
        $this->userCode = $integration?->user_code;
        $this->verificationUrl = $integration?->verification_url;
        $this->userCodeExpiresAt = $integration?->user_code_expires_at?->diffForHumans();
        $this->merchantId = $integration?->merchant_id;
        $this->availableMerchants = $integration?->available_merchants ?? [];
        $this->selectedMerchantId = '';
        $this->status = $integration?->status ?? 'active';
        $this->webhookStatus = $integration?->webhook_status ?? 'unknown';
        $this->lastSyncedAt = $integration?->last_synced_at?->diffForHumans();
        $this->lastWebhookReceivedAt = $integration?->last_webhook_received_at?->diffForHumans();
    }

    public function render()
    {
        return view('livewire.admin.settings.ifood-integration-settings')
            ->layout('layouts.app', ['title' => 'Integração iFood']);
    }
}
