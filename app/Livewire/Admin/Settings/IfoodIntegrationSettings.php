<?php

namespace App\Livewire\Admin\Settings;

use App\Exceptions\IfoodInvalidAuthorizationCodeException;
use App\Exceptions\IfoodMerchantAlreadyLinkedException;
use App\Exceptions\IfoodNoAuthorizedMerchantsException;
use App\Jobs\PollIfoodEventsJob;
use App\Jobs\SyncIfoodCatalogJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\IfoodIntegration;
use App\Models\Product;
use App\Services\Ifood\IfoodAuthService;
use App\Services\Ifood\IfoodConnectionMonitor;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class IfoodIntegrationSettings extends Component
{
    use Concerns\ManagesIfoodMerchant;

    public const TABS = ['loja', 'pausas', 'horarios', 'cardapio', 'diagnostico'];

    // Cada filial tem sua própria conexão com o iFood (merchant_id/tokens próprios),
    // mesmo padrão de App\Livewire\Admin\Fiscal\Config — trocar a filial selecionada
    // troca o registro inteiro carregado.
    public ?int $branchId = null;

    /** @var array<int, array{id: int, name: string, state: string}> */
    public array $branchOptions = [];

    /** not_connected | reauthorization_required | pending_authorization | pending_merchant_selection | connected */
    public string $connectionState = 'not_connected';

    #[Url(as: 'aba', except: 'loja')]
    public string $tab = 'loja';

    public ?string $userCode = null;

    public ?string $verificationUrl = null;

    public ?string $userCodeExpiresAt = null;

    public string $authorizationCode = '';

    public ?string $merchantId = null;

    public ?string $merchantName = null;

    /** @var array<int, array{id: string, name: ?string}> */
    public array $availableMerchants = [];

    public string $selectedMerchantId = '';

    public string $status = 'active';

    public string $webhookStatus = 'unknown';

    public ?string $lastSyncedAt = null;

    public ?string $lastPolledAt = null;

    public bool $pollingStalled = false;

    public ?string $lastWebhookReceivedAt = null;

    public bool $catalogPublished = false;

    public ?string $catalogSyncStatus = null;

    public ?string $catalogSyncError = null;

    public bool $showDisconnectModal = false;

    public bool $showSuspendModal = false;

    /**
     * Entra no wire:key do painel da aba: loadForBranch zera os dados do iFood (suspender, retomar,
     * sincronizar), e o painel precisa remontar pro wire:init buscar de novo.
     */
    #[Locked]
    public int $panelVersion = 0;

    public function mount(): void
    {
        $company = app('current.company');

        abort_unless($company->canUseIfoodIntegration(), 403, 'Módulo de Integrações não está habilitado para esta empresa.');

        $branches = Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get();
        $integrations = IfoodIntegration::where('company_id', $company->id)->get()->keyBy('branch_id');
        $this->branchOptions = $branches->map(fn (Branch $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'state' => $this->branchStateLabel($integrations->get($b->id)),
        ])->all();

        // Começa pela filial já conectada (depois por qualquer integração cadastrada); sem
        // nenhuma, cai na primeira filial da empresa como ponto de partida.
        $ordered = $integrations->sortBy('id');
        $existing = $ordered->firstWhere(fn (IfoodIntegration $integration) => $integration->merchant_id !== null) ?? $ordered->first();
        $branch = $existing
            ? $branches->firstWhere('id', $existing->branch_id)
            : $branches->first();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'loja';
        }

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

    public function updatedTab(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'loja';
        }
    }

    /**
     * Cada aba busca o que mostra ao abrir (sem precisar clicar em "Consultar"): o painel da aba
     * tem wire:key por filial e aba, então o wire:init roda de novo a cada troca. Os dados do
     * iFood não ficam gravados aqui: a fonte é sempre a loja no iFood.
     */
    public function loadCurrentTab(): void
    {
        if ($this->connectionState !== 'connected' || ! in_array($this->status, ['active', 'paused'], true)) {
            return;
        }

        match ($this->tab) {
            'loja' => $this->merchantDetails === [] ? $this->loadStoreOverview() : null,
            'pausas' => $this->interruptionsLoaded ? null : $this->consultInterruptions(),
            'horarios' => $this->hoursBranchId === $this->branchId ? null : $this->consultOpeningHours(),
            default => null,
        };
    }

    public function connect(): void
    {
        $this->authorizeIfoodManagement();
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
        $this->authorizeIfoodManagement();
        session()->forget(['error', 'status']);
        $company = app('current.company');
        $branch = $this->currentBranch($company);
        $integration = $this->currentIntegration($company, $branch);

        if (! $integration || (! $integration->isPendingAuthorization() && ! $integration->isPendingMerchantSelection())) {
            $this->loadForBranch($company, $branch);

            return;
        }

        if ($integration->isPendingAuthorization() && $integration->isUserCodeExpired()) {
            session()->flash('error', 'O código expirou. Clique em conectar novamente para gerar um novo.');
            $this->loadForBranch($company, $branch);

            return;
        }

        $this->resetErrorBag();
        if ($integration->isPendingAuthorization()) {
            $this->validate(['authorizationCode' => ['required', 'string']], [
                'authorizationCode.required' => 'Cole o código de autorização que o iFood mostrou.',
            ]);
        }

        try {
            if ($integration->isPendingMerchantSelection()) {
                app(IfoodAuthService::class)->refreshAuthorizedMerchants($integration);
            } else {
                app(IfoodAuthService::class)->completeAuthorization($integration, trim($this->authorizationCode));
            }
        } catch (IfoodMerchantAlreadyLinkedException|IfoodNoAuthorizedMerchantsException $e) {
            $this->loadForBranch($company, $branch);
            session()->flash('error', $e->getMessage());

            return;
        } catch (IfoodInvalidAuthorizationCodeException) {
            $this->addError('authorizationCode', 'Código inválido ou expirado. Confira o que o iFood mostrou e tente de novo.');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->loadForBranch($company, $branch);
            if ($this->connectionState === 'pending_merchant_selection') {
                session()->flash('error', 'A autorização foi salva, mas não foi possível consultar as lojas no iFood. Clique em consultar lojas novamente.');
            } else {
                $this->addError('authorizationCode', 'Não foi possível concluir a conexão com o iFood. Tente novamente em instantes. Se o problema persistir, reinicie a conexão.');
            }

            return;
        }

        $this->authorizationCode = '';
        $integration->refresh();

        if ($integration->merchant_id !== null) {
            $this->afterActivation($integration, 'Integração com o iFood conectada.');
        } else {
            session()->flash('status', 'Autorização concluída — escolha a loja para ativar a integração.');
        }

        $this->loadForBranch($company, $branch);
    }

    public function selectMerchant(): void
    {
        $this->authorizeIfoodManagement();
        $company = app('current.company');
        $branch = $this->currentBranch($company);
        $integration = $this->currentIntegration($company, $branch);

        if (! $integration || ! $integration->isPendingMerchantSelection()) {
            $this->loadForBranch($company, $branch);

            return;
        }

        $this->validate(['selectedMerchantId' => ['required', 'string']], [
            'selectedMerchantId.required' => 'Escolha a loja do iFood que corresponde a esta filial.',
        ]);

        try {
            app(IfoodAuthService::class)->selectMerchant($integration, $this->selectedMerchantId);
        } catch (IfoodMerchantAlreadyLinkedException $e) {
            $this->addError('selectedMerchantId', $e->getMessage());

            return;
        } catch (\Throwable) {
            $this->addError('selectedMerchantId', 'Não foi possível confirmar essa loja. Tente novamente.');

            return;
        }

        $this->afterActivation($integration->refresh(), 'Loja confirmada — integração com o iFood ativa.');
        $this->loadForBranch($company, $branch);
    }

    /**
     * Envia o cardápio desta filial ao iFood em segundo plano (pode levar minutos). Na primeira
     * vez é a publicação: a partir dela, preço e disponibilidade seguem sincronizados sozinhos.
     */
    public function syncCatalogNow(): void
    {
        $this->authorizeIfoodManagement();
        $company = app('current.company');
        $branch = $this->currentBranch($company);
        $integration = $this->currentIntegration($company, $branch);

        if (! $integration || $integration->merchant_id === null) {
            return;
        }

        abort_unless($company->canUseIfoodIntegration(), 403);
        if ($integration->status !== 'active') {
            session()->flash('error', 'Retome a integração antes de enviar o cardápio.');

            return;
        }
        if (in_array($integration->catalog_sync_status, ['queued', 'running'], true)) {
            session()->flash('status', 'O cardápio já está sendo enviado ao iFood.');

            return;
        }

        session()->forget(['error', 'status']);
        $firstPublish = ! $integration->hasPublishedCatalog();
        $integration->update(['catalog_sync_status' => 'queued', 'catalog_sync_error' => null]);

        try {
            SyncIfoodCatalogJob::dispatch($branch->id, publish: true);
        } catch (\Throwable $e) {
            // Fila síncrona (dev/teste) roda o job aqui: a falha já ficou registrada na integração
            // pelo failed() do job e aparece na aba Cardápio. Fila assíncrona fora do ar não chega
            // a rodar o job: sem isto o envio ficava "na fila" com o botão travado.
            report($e);
            IfoodIntegration::whereKey($integration->id)->where('catalog_sync_status', 'queued')->update([
                'catalog_sync_status' => 'failed',
                'catalog_sync_error' => 'Não foi possível iniciar o envio do cardápio. Tente de novo em instantes.',
            ]);
            $this->loadForBranch($company, $branch);
            $this->tab = 'cardapio';

            return;
        }

        session()->flash('status', $firstPublish
            ? 'Publicação do cardápio iniciada. O andamento aparece abaixo.'
            : 'Sincronização do cardápio iniciada. O andamento aparece abaixo.');
        $this->loadForBranch($company, $branch);
        $this->tab = 'cardapio';
    }

    /** Atualiza só o andamento do envio do cardápio (wire:poll enquanto está na fila ou rodando). */
    public function refreshCatalogStatus(): void
    {
        $company = app('current.company');
        $integration = $this->currentIntegration($company, $this->currentBranch($company));

        $this->applyCatalogState($integration);
        unset($this->catalogPreview);
    }

    public function confirmSuspend(): void
    {
        $this->authorizeIfoodManagement();
        $this->showSuspendModal = true;
    }

    public function pause(): void
    {
        $this->authorizeIfoodManagement();
        $this->showSuspendModal = false;
        $this->setStatus('paused');
    }

    public function resume(): void
    {
        $this->authorizeIfoodManagement();
        $company = app('current.company');
        $branch = $this->currentBranch($company);
        $integration = $this->currentIntegration($company, $branch);

        // Enquanto esta estava suspensa, outra empresa pode ter conectado a mesma loja:
        // retomar deixaria as duas recebendo os pedidos da loja.
        if ($integration && $integration->merchant_id && $integration->status === 'paused') {
            try {
                app(IfoodAuthService::class)->ensureMerchantAvailable($integration, $integration->merchant_id);
            } catch (IfoodMerchantAlreadyLinkedException $e) {
                session()->flash('error', $e->getMessage());
                $this->loadForBranch($company, $branch);

                return;
            }
        }

        $this->setStatus('active');
    }

    public function confirmDisconnect(): void
    {
        $this->authorizeIfoodManagement();
        $this->showDisconnectModal = true;
    }

    public function disconnect(): void
    {
        $this->authorizeIfoodManagement();
        $this->showDisconnectModal = false;
        $company = app('current.company');
        $branch = $this->currentBranch($company);
        $integration = $this->currentIntegration($company, $branch);
        $wasConnected = $integration?->merchant_id !== null;

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
                'alerts_sent' => null,
                'status' => 'disconnected',
            ]);
        }

        session()->flash('status', $wasConnected ? 'Integração com o iFood desconectada.' : 'Conexão cancelada.');
        $this->loadForBranch($company, $branch);
    }

    /**
     * Prévia do que a publicação envia e do que vai ficar de fora (com o motivo), pra o
     * restaurante corrigir o cadastro antes. Mesmas regras do envio: canal iFood ligado,
     * produto ativo e disponível na filial, nome até 100 caracteres, foto JPG/PNG.
     *
     * @return array{total: int, categories: int, published: int, issues: array<int, array{id: int, name: string, problem: string}>}
     */
    #[Computed]
    public function catalogPreview(): array
    {
        if (! $this->branchId) {
            return ['total' => 0, 'categories' => 0, 'published' => 0, 'issues' => []];
        }

        $company = app('current.company');
        $pivots = DB::table('branch_product')->where('branch_id', $this->branchId)
            ->get(['product_id', 'available', 'ifood_item_id', 'ifood_sync_error', 'ifood_synced_at'])->keyBy('product_id');

        $products = Product::where('company_id', $company->id)
            ->whereIn('id', $pivots->keys())
            ->orderBy('name')
            ->get(['id', 'name', 'product_category_id', 'image_path', 'active', 'available_in_ifood']);

        // Produto desligado que já está no iFood também vai no envio (como indisponível), então o
        // erro dele entra na lista: senão o aviso "N produtos não foram enviados" não bate com ela.
        $eligible = $products->filter(fn (Product $product) => $product->active && $product->available_in_ifood);

        $issues = [];
        foreach ($products as $product) {
            $pivot = $pivots->get($product->id);
            $problem = $product->active && $product->available_in_ifood
                ? match (true) {
                    $product->product_category_id === null => 'Sem categoria.',
                    mb_strlen($product->name) > 100 => 'Nome com mais de 100 caracteres.',
                    $product->image_path && ! in_array(strtolower(pathinfo($product->image_path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true) => 'O iFood aceita fotos JPG ou PNG.',
                    (bool) $pivot?->ifood_sync_error => $pivot->ifood_sync_error,
                    default => null,
                }
            : ($pivot?->ifood_item_id && $pivot->ifood_sync_error ? $pivot->ifood_sync_error : null);
            if ($problem) {
                $issues[] = ['id' => $product->id, 'name' => $product->name, 'problem' => $problem];
            }
        }

        return [
            'total' => $eligible->count(),
            'categories' => $eligible->pluck('product_category_id')->filter()->unique()->count(),
            // ifood_item_id nasce aqui antes do envio; só ifood_synced_at prova que o iFood aceitou o item.
            'published' => $pivots->only($products->modelKeys())->whereNotNull('ifood_synced_at')->count(),
            'issues' => $issues,
        ];
    }

    private function afterActivation(IfoodIntegration $integration, string $message): void
    {
        PollIfoodEventsJob::dispatch();

        // Quem já publicou o cardápio nesta loja (reconexão) segue sincronizando; loja nova
        // passa pela prévia da aba Cardápio antes de enviar qualquer item.
        if ($integration->hasPublishedCatalog()) {
            SyncIfoodCatalogJob::dispatch($integration->branch_id);
            session()->flash('status', $message.' Sincronizando o cardápio...');

            return;
        }

        $this->tab = 'cardapio';
        session()->flash('status', $message.' Revise o cardápio e publique quando estiver pronto.');
    }

    private function setStatus(string $status): void
    {
        $company = app('current.company');
        $branch = $this->currentBranch($company);
        $integration = $this->currentIntegration($company, $branch);

        if ($integration && $integration->merchant_id && $integration->status !== 'reauthorization_required') {
            $resuming = $status === 'active' && $integration->status === 'paused';
            $integration->update($resuming
                // Suspensa não é consultada: o monitor conta os 10 minutos a partir da retomada.
                ? ['status' => $status, 'last_polled_at' => now()]
                : ['status' => $status]);
            session()->flash('status', $status === 'paused'
                ? 'Integração suspensa. A loja fica fechada no iFood até você retomar.'
                : 'Integração retomada. A loja volta a receber pedidos do iFood.');

            // Suspensa, a disponibilidade em tempo real não vai pro iFood (produto desligado,
            // estoque zerado): reenvia o cardápio pra loja não voltar vendendo o que acabou.
            if ($resuming && $integration->hasPublishedCatalog()) {
                SyncIfoodCatalogJob::dispatch($integration->branch_id);
            }
        }

        $this->loadForBranch($company, $branch);
    }

    /**
     * A rota já é só de company_admin, mas a ação roda sem passar por ela (o componente pode
     * estar aberto quando o papel muda): confere de novo antes de mexer na integração.
     */
    private function authorizeIfoodManagement(): void
    {
        $user = auth()->user();
        $company = app('current.company');

        abort_unless($user && ($user->isSuperAdmin() || $user->isCompanyAdmin($company)), 403);
    }

    private function currentBranch(Company $company): ?Branch
    {
        return $this->branchId
            ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId)
            : null;
    }

    private function currentIntegration(Company $company, ?Branch $branch): ?IfoodIntegration
    {
        return $branch
            ? IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first()
            : null;
    }

    private function branchStateLabel(?IfoodIntegration $integration): string
    {
        return match (true) {
            $integration === null => 'não conectada',
            $integration->status === 'reauthorization_required' => 'reconexão necessária',
            $integration->merchant_id !== null && $integration->status === 'paused' => 'suspensa',
            $integration->merchant_id !== null => 'conectada',
            $integration->isPendingMerchantSelection() => 'escolha a loja',
            $integration->isPendingAuthorization() && ! $integration->isUserCodeExpired() => 'aguardando aprovação',
            default => 'não conectada',
        };
    }

    private function loadForBranch(Company $company, ?Branch $branch): void
    {
        $this->reset('merchantStores', 'merchantStoresLoaded', 'merchantDetails', 'merchantStatus', 'interruptions', 'interruptionsLoaded', 'openingShifts', 'hoursBranchId', 'merchantCheckedAt', 'interruptionDescription', 'interruptionStart', 'interruptionEnd', 'confirmingInterruptionId');
        $this->panelVersion++;
        $this->resetErrorBag();
        $integration = $this->currentIntegration($company, $branch);

        $this->connectionState = match (true) {
            $integration?->status === 'reauthorization_required' => 'reauthorization_required',
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
        $this->merchantName = $integration?->merchant_name;
        $this->availableMerchants = $integration?->available_merchants ?? [];
        $this->selectedMerchantId = '';
        $this->status = $integration?->status ?? 'active';
        $this->webhookStatus = $integration?->webhook_status ?? 'unknown';
        $this->lastPolledAt = $integration?->last_polled_at?->diffForHumans();
        $this->pollingStalled = $integration !== null
            && $integration->status === 'active'
            && $integration->merchant_id !== null
            && (bool) $integration->last_polled_at?->lt(now()->subMinutes(IfoodConnectionMonitor::POLLING_STALE_MINUTES));
        $this->lastWebhookReceivedAt = $integration?->last_webhook_received_at?->diffForHumans();
        $this->applyCatalogState($integration);

        foreach ($this->branchOptions as $index => $option) {
            if ($branch && $option['id'] === $branch->id) {
                $this->branchOptions[$index]['state'] = $this->branchStateLabel($integration);
            }
        }

        unset($this->catalogPreview);
    }

    private function applyCatalogState(?IfoodIntegration $integration): void
    {
        $this->catalogPublished = (bool) $integration?->hasPublishedCatalog();
        $this->catalogSyncStatus = $integration?->catalog_sync_status;
        $this->catalogSyncError = $integration?->catalog_sync_error;
        $this->lastSyncedAt = $integration?->last_synced_at?->diffForHumans();
    }

    public function render()
    {
        return view('livewire.admin.settings.ifood-integration-settings', [
            'homologationMode' => (bool) config('ifood.homologation_mode'),
            'tabs' => [
                'loja' => 'Loja',
                'pausas' => 'Pausas',
                'horarios' => 'Horários',
                'cardapio' => 'Cardápio',
                'diagnostico' => 'Diagnóstico',
            ],
        ])->layout('layouts.app', ['title' => 'Integração iFood']);
    }
}
