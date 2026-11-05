<?php

namespace App\Livewire\Admin\Settings;

use App\Exceptions\KeetaApiException;
use App\Jobs\ProcessKeetaEventJob;
use App\Jobs\SyncKeetaCatalogJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\KeetaIntegration;
use App\Models\KeetaWebhookEvent;
use App\Services\Keeta\KeetaAuthService;
use App\Services\Keeta\KeetaCatalogSyncService;
use App\Services\Keeta\KeetaConnectionMonitor;
use App\Services\Keeta\KeetaStoreHours;
use App\Services\Keeta\KeetaStoreStatusSync;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Integração Keeta por filial: conexão (autorização no portal da Keeta → escolha da loja →
 * onboarding), loja, cardápio e diagnóstico. Toda ação relê a integração do banco pela empresa
 * atual; o estado público serve só pra desenhar a tela.
 */
class KeetaIntegrationSettings extends Component
{
    public const TABS = ['loja', 'cardapio', 'diagnostico'];

    #[Url(as: 'filial', except: null)]
    public ?int $branchId = null;

    /** @var array<int, array{id: int, name: string, state: string}> */
    #[Locked]
    public array $branchOptions = [];

    /** not_connected | pending_authorization | pending_mapping | connected | error | revoked */
    #[Locked]
    public string $connectionState = 'not_connected';

    #[Url(as: 'aba', except: 'loja')]
    public string $tab = 'loja';

    /** @var array<int, array{id: string, name: string, address: ?string}> */
    #[Locked]
    public array $availableShops = [];

    #[Locked]
    public array $integrationData = [];

    public string $selectedShopId = '';

    public bool $showDisconnectModal = false;

    public bool $showSuspendModal = false;

    public function mount(): void
    {
        $company = app('current.company');

        abort_unless(config('keeta.enabled'), 404);
        abort_unless($company->canUseKeetaIntegration(), 403, 'Módulo de Integrações não está habilitado para esta empresa.');

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'loja';
        }

        $branch = $this->currentBranch($company);
        if (! $branch) {
            $branches = Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get();
            $integrations = KeetaIntegration::where('company_id', $company->id)->get()->keyBy('branch_id');
            $existing = $integrations->sortBy('id')->first(fn (KeetaIntegration $integration) => $integration->isLinked() || $integration->status !== KeetaIntegration::STATUS_DISCONNECTED);
            $branch = $existing ? $branches->firstWhere('id', $existing->branch_id) : $branches->first();
        }

        $this->branchId = $branch?->id;
        $this->loadForBranch($company, $branch);
    }

    public function updatedBranchId(): void
    {
        $company = app('current.company');
        $branch = $this->currentBranch($company) ?? Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->first();
        $this->branchId = $branch?->id;

        $this->loadForBranch($company, $branch);
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'loja';
        }
    }

    // ── Conexão ───────────────────────────────────────────────────────────

    /** Gera o link de autorização (com o nosso state) e manda o admin pro portal da Keeta. */
    public function connect(): void
    {
        [, , $integration] = $this->context(createIntegration: true);

        try {
            $url = app(KeetaAuthService::class)->startAuthorization($integration);
        } catch (Throwable $e) {
            $this->flashFailure($e);

            return;
        }

        $this->redirect($url);
    }

    public function cancelConnection(): void
    {
        [$company, $branch, $integration] = $this->context();

        if ($integration && ! $integration->isLinked()) {
            $integration->forceFill([
                'status' => KeetaIntegration::STATUS_DISCONNECTED,
                'authorization_state' => null,
                'auth_id' => null,
                'available_shops' => null,
            ])->save();
        }

        $this->loadForBranch($company, $branch);
    }

    public function refreshShops(): void
    {
        [$company, $branch, $integration] = $this->context();
        abort_unless($integration !== null, 404);

        if ($this->tryAction(fn () => app(KeetaAuthService::class)->refreshShops($integration))) {
            session()->flash('status', 'Lista de lojas atualizada.');
        }

        $this->loadForBranch($company, $branch);
    }

    public function linkShop(): void
    {
        [$company, $branch, $integration] = $this->context();
        abort_unless($integration !== null, 404);

        if ($this->selectedShopId === '') {
            $this->addError('selectedShopId', 'Escolha a loja da Keeta desta filial.');

            return;
        }

        if ($integration->isLinked() && ! in_array($integration->status, [KeetaIntegration::STATUS_ERROR, KeetaIntegration::STATUS_REVOKED], true)) {
            session()->flash('error', 'Esta filial já tem uma loja da Keeta. Desconecte antes de trocar.');

            return;
        }

        if ($this->tryAction(fn () => app(KeetaAuthService::class)->linkShop($integration, $this->selectedShopId))) {
            session()->flash('status', 'Loja conectada. Os pedidos da Keeta passam a chegar ao painel desta filial.');
        }

        $this->loadForBranch($company, $branch);
    }

    /** Reaplica o onboarding (erro na ativação, troca de domínio, autorização refeita). */
    public function retryOnboarding(): void
    {
        [$company, $branch, $integration] = $this->context(requireLinked: true);

        if ($this->tryAction(fn () => app(KeetaAuthService::class)->onboard($integration))) {
            session()->flash('status', 'Vínculo com a Keeta reaplicado.');
        }

        $this->loadForBranch($company, $branch);
    }

    public function confirmDisconnect(): void
    {
        $this->context();
        $this->showDisconnectModal = true;
    }

    public function disconnect(): void
    {
        [$company, $branch, $integration] = $this->context();
        $this->showDisconnectModal = false;

        if ($integration) {
            app(KeetaAuthService::class)->disconnect($integration);
            session()->flash('status', 'Keeta desconectada desta filial. Para encerrar de vez, revogue também a autorização no portal da Keeta.');
        }

        $this->loadForBranch($company, $branch);
    }

    public function suspend(): void
    {
        [$company, $branch, $integration] = $this->context(requireLinked: true);
        $this->showSuspendModal = false;

        try {
            app(KeetaStoreStatusSync::class)->setOpen($integration, false);
        } catch (Throwable $e) {
            Log::channel('keeta')->warning('Keeta: não fechou a loja ao suspender', ['keeta_integration_id' => $integration->id, 'error' => KeetaApiException::forLog($e)]);
            session()->flash('error', 'A integração foi suspensa, mas a Keeta não confirmou o fechamento da loja. '.KeetaApiException::messageFor($e));
        }

        $integration->forceFill(['status' => KeetaIntegration::STATUS_PAUSED])->save();
        session()->flash('status', 'Integração suspensa. Pedidos já recebidos podem ser concluídos.');

        $this->loadForBranch($company, $branch);
    }

    public function resume(): void
    {
        [$company, $branch, $integration] = $this->context(requireLinked: true);

        $integration->forceFill(['status' => KeetaIntegration::STATUS_ACTIVE])->save();

        if ($this->tryAction(fn () => app(KeetaStoreStatusSync::class)->setOpen($integration, true))) {
            session()->flash('status', 'Integração retomada e loja aberta na Keeta.');
        }

        $this->loadForBranch($company, $branch);
    }

    // ── Loja ──────────────────────────────────────────────────────────────

    public function setStoreOpen(bool $open): void
    {
        [$company, $branch, $integration] = $this->context(requireLinked: true);

        if ($integration->status !== KeetaIntegration::STATUS_ACTIVE) {
            session()->flash('error', 'Retome a integração antes de abrir ou fechar a loja.');

            return;
        }

        if ($this->tryAction(fn () => app(KeetaStoreStatusSync::class)->setOpen($integration, $open))) {
            session()->flash('status', $open ? 'Loja aberta na Keeta.' : 'Loja fechada na Keeta até você abrir de novo.');
        }

        $this->loadForBranch($company, $branch);
    }

    public function toggleAutoAccept(): void
    {
        [$company, $branch, $integration] = $this->context(requireLinked: true);

        $integration->forceFill(['auto_accept' => ! $integration->auto_accept])->save();
        session()->flash('status', $integration->auto_accept
            ? 'Aceite automático ligado: pedidos da Keeta são confirmados assim que chegam.'
            : 'Aceite automático desligado: confirme cada pedido em até 5 minutos.');

        Log::channel('keeta')->info('Keeta: aceite automático alterado', ['keeta_integration_id' => $integration->id, 'auto_accept' => $integration->auto_accept, 'user_id' => auth()->id()]);

        $this->loadForBranch($company, $branch);
    }

    // ── Cardápio ──────────────────────────────────────────────────────────

    #[Computed]
    public function menuPreview(): ?array
    {
        [, , $integration] = $this->context();

        if (! $integration?->isLinked() || $this->tab !== 'cardapio') {
            return null;
        }

        try {
            $result = app(KeetaCatalogSyncService::class)->preview($integration);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $names = \App\Models\Product::withoutGlobalScopes()->whereIn('id', array_keys($result['issues']))->pluck('name', 'id');

        return [
            'items' => count($result['merchant']['itemOffers']),
            'categories' => count($result['merchant']['categories']),
            'groups' => count($result['merchant']['optionGroups']),
            'hours' => $result['merchant']['services'][0]['serviceHours']['weekHours'] ?? [],
            'issues' => collect($result['issues'])->map(fn (string $reason, int $id) => ['name' => $names[$id] ?? "Produto {$id}", 'reason' => $reason])->values()->all(),
        ];
    }

    public function publishCatalog(): void
    {
        [$company, $branch, $integration] = $this->context(requireLinked: true);

        if ($integration->status !== KeetaIntegration::STATUS_ACTIVE) {
            session()->flash('error', 'Retome a integração antes de publicar o cardápio.');

            return;
        }

        $integration->forceFill(['catalog_published_at' => now(), 'catalog_sync_status' => 'queued', 'catalog_sync_error' => null])->save();
        SyncKeetaCatalogJob::dispatch($integration->branch_id);

        session()->flash('status', 'Cardápio publicado. A Keeta busca o cardápio desta filial em instantes; a edição manual no portal da Keeta fica bloqueada.');
        $this->loadForBranch($company, $branch);
    }

    public function syncCatalogNow(): void
    {
        [$company, $branch, $integration] = $this->context(requireLinked: true);

        if (! $integration->hasPublishedCatalog()) {
            session()->flash('error', 'Publique o cardápio primeiro.');

            return;
        }

        if ($this->tryAction(fn () => app(KeetaCatalogSyncService::class)->notifyMenuChanged($integration))) {
            session()->flash('status', 'A Keeta foi avisada e vai buscar o cardápio atualizado.');
        }

        $this->loadForBranch($company, $branch);
    }

    // ── Diagnóstico ───────────────────────────────────────────────────────

    #[Computed]
    public function recentEvents()
    {
        [, , $integration] = $this->context();

        return $integration && $this->tab === 'diagnostico'
            ? KeetaWebhookEvent::where('keeta_integration_id', $integration->id)->latest('id')->limit(30)
                ->get(['id', 'event_type', 'external_order_id', 'source', 'status', 'attempts', 'error', 'created_at'])
            : collect();
    }

    public function reprocessEvent(int $eventId): void
    {
        [, , $integration] = $this->context(requireLinked: true);

        $event = KeetaWebhookEvent::where('keeta_integration_id', $integration->id)
            ->where('status', KeetaWebhookEvent::STATUS_FAILED)
            ->findOrFail($eventId);

        $event->update(['status' => KeetaWebhookEvent::STATUS_PENDING, 'attempts' => 0, 'error' => null]);
        ProcessKeetaEventJob::dispatch($event->id);

        Log::channel('keeta')->info('Keeta: evento reprocessado pelo admin', ['keeta_webhook_event_id' => $event->id, 'user_id' => auth()->id()]);
        session()->flash('status', 'Evento enviado para reprocessamento.');
        unset($this->recentEvents);
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /** @return array{0: Company, 1: ?Branch, 2: ?KeetaIntegration} */
    private function context(bool $createIntegration = false, bool $requireLinked = false): array
    {
        $user = auth()->user();
        $company = app('current.company');

        abort_unless($company->canUseKeetaIntegration(), 403);
        abort_unless($user && ($user->isSuperAdmin() || $user->isCompanyAdmin($company)), 403);

        $branch = $this->currentBranch($company);
        abort_unless($branch !== null, 404);

        $integration = KeetaIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();

        if (! $integration && $createIntegration) {
            $integration = KeetaIntegration::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'merchant_ref' => KeetaIntegration::merchantRefFor($company->id, $branch->id),
                'status' => KeetaIntegration::STATUS_DISCONNECTED,
                'auto_accept' => (bool) config('keeta.auto_accept_default', true),
            ]);
        }

        abort_if($requireLinked && ! $integration?->isLinked(), 404);

        return [$company, $branch, $integration];
    }

    private function currentBranch(Company $company): ?Branch
    {
        return $this->branchId ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId) : null;
    }

    private function loadForBranch(Company $company, ?Branch $branch): void
    {
        $integration = $branch ? KeetaIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first() : null;

        $this->connectionState = match (true) {
            $integration === null => 'not_connected',
            $integration->status === KeetaIntegration::STATUS_REVOKED => 'revoked',
            $integration->isLinked() && $integration->status === KeetaIntegration::STATUS_ERROR => 'error',
            $integration->isLinked() && in_array($integration->status, [KeetaIntegration::STATUS_ACTIVE, KeetaIntegration::STATUS_PAUSED], true) => 'connected',
            $integration->status === KeetaIntegration::STATUS_PENDING_MAPPING => 'pending_mapping',
            $integration->status === KeetaIntegration::STATUS_PENDING_AUTHORIZATION => 'pending_authorization',
            default => 'not_connected',
        };

        $this->resetErrorBag();
        $this->selectedShopId = '';
        $this->branchOptions = $this->branchOptionsFor($company);
        $this->availableShops = $this->connectionState === 'pending_mapping' || $this->connectionState === 'error' || $this->connectionState === 'revoked'
            ? array_values($integration->available_shops ?? [])
            : [];

        $tz = config('app.timezone');
        $this->integrationData = $integration ? [
            'keeta_merchant_id' => $integration->keeta_merchant_id,
            'merchant_ref' => $integration->merchant_ref,
            'shop_name' => $integration->shop_name,
            'shop_address' => $integration->shop_address,
            'status' => $integration->status,
            'store_open' => $integration->isStoreOpen(),
            'store_updated_at' => $integration->store_status_updated_at?->timezone($tz)->format('d/m H:i'),
            'auto_accept' => (bool) $integration->auto_accept,
            'webhook_status' => $integration->webhook_status,
            'webhook_silent' => $integration->isWebhookSilent(KeetaConnectionMonitor::WEBHOOK_SILENT_HOURS),
            'last_webhook_at' => $integration->last_webhook_received_at?->timezone($tz)->format('d/m/Y H:i'),
            'last_polled_at' => $integration->last_polled_at?->timezone($tz)->format('d/m/Y H:i'),
            'last_menu_fetched_at' => $integration->last_menu_fetched_at?->timezone($tz)->format('d/m/Y H:i'),
            'catalog_published' => $integration->hasPublishedCatalog(),
            'catalog_sync_status' => $integration->catalog_sync_status,
            'catalog_sync_error' => $integration->catalog_sync_error,
            'catalog_synced_at' => $integration->catalog_synced_at?->timezone($tz)->format('d/m/Y H:i'),
            'menu_url' => $integration->isLinked() ? $integration->menuUrl() : null,
        ] : [];

        unset($this->menuPreview, $this->recentEvents);
    }

    /** @return array<int, array{id: int, name: string, state: string}> */
    private function branchOptionsFor(Company $company): array
    {
        $integrations = KeetaIntegration::where('company_id', $company->id)->get()->keyBy('branch_id');

        return Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'state' => $this->branchStateLabel($integrations->get($branch->id)),
            ])->all();
    }

    private function branchStateLabel(?KeetaIntegration $integration): string
    {
        return match (true) {
            $integration === null => 'não conectada',
            $integration->status === KeetaIntegration::STATUS_PENDING_AUTHORIZATION => 'aguardando autorização',
            $integration->status === KeetaIntegration::STATUS_PENDING_MAPPING => 'escolher loja',
            $integration->status === KeetaIntegration::STATUS_PAUSED => 'suspensa',
            $integration->status === KeetaIntegration::STATUS_REVOKED => 'autorização revogada',
            $integration->status === KeetaIntegration::STATUS_ERROR => 'reconexão necessária',
            $integration->status === KeetaIntegration::STATUS_ACTIVE => 'conectada',
            default => 'não conectada',
        };
    }

    private function tryAction(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (Throwable $e) {
            $this->flashFailure($e);

            return false;
        }
    }

    private function flashFailure(Throwable $e): void
    {
        // QueryException é RuntimeException: não pode cair na mensagem pro usuário.
        if ($e instanceof QueryException || (! $e instanceof KeetaApiException && ! $e instanceof \RuntimeException && ! $e instanceof \InvalidArgumentException)) {
            report($e);
            session()->flash('error', 'Não foi possível concluir agora. Tente de novo em instantes.');

            return;
        }

        session()->flash('error', KeetaApiException::messageFor($e));
    }

    public function render()
    {
        return view('livewire.admin.settings.keeta-integration-settings', [
            'homologationMode' => (bool) config('keeta.homologation_mode'),
            'tabs' => [
                'loja' => 'Loja',
                'cardapio' => 'Cardápio',
                'diagnostico' => 'Diagnóstico',
            ],
            'weekDays' => KeetaStoreHours::DAYS,
        ])->layout('layouts.app', ['title' => 'Integração Keeta']);
    }
}
