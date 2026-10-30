<?php

namespace App\Livewire\Admin\Settings;

use App\Contracts\Food99GatewayContract;
use App\Exceptions\Food99ApiException;
use App\Jobs\ProcessFood99EventJob;
use App\Jobs\SyncFood99CatalogJob;
use App\Livewire\Admin\Settings\Concerns\ManagesFood99Store;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Food99Integration;
use App\Models\Food99WebhookEvent;
use App\Services\Food99\Food99AuthService;
use App\Services\Food99\Food99CatalogSyncService;
use App\Services\Food99\Food99ConnectionMonitor;
use App\Services\Food99\Food99ShopEventHandler;
use App\Services\Food99\Food99StoreHours;
use App\Services\Food99\Food99StoreStatusSync;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Integração 99Food por filial: conexão (link de autorização + ID da loja), status, pausas,
 * horários, cardápio, solicitações do cliente e diagnóstico. Toda ação relê a integração do
 * banco pela empresa atual; o estado público serve só pra desenhar a tela.
 */
class Food99IntegrationSettings extends Component
{
    use ManagesFood99Store;

    public const TABS = ['loja', 'pausas', 'horarios', 'cardapio', 'pedidos', 'diagnostico'];

    public ?int $branchId = null;

    /** @var array<int, array{id: int, name: string, state: string}> */
    #[Locked]
    public array $branchOptions = [];

    /** not_connected | pending_authorization | activating | connected | error */
    #[Locked]
    public string $connectionState = 'not_connected';

    #[Url(as: 'aba', except: 'loja')]
    public string $tab = 'loja';

    #[Locked]
    public ?string $authorizationUrl = null;

    #[Locked]
    public ?string $authorizationUrlExpiresAt = null;

    public string $shopIdInput = '';

    /**
     * Loja encontrada pelo ID, aguardando o admin confirmar que é a dele.
     *
     * @var array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}|null
     */
    #[Locked]
    public ?array $pendingShop = null;

    /** @var array<int, array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}> */
    #[Locked]
    public array $availableShops = [];

    #[Locked]
    public array $integrationData = [];

    public int $pauseTime = 1;

    public int $pauseReason = Food99StoreStatusSync::DEFAULT_PAUSE_REASON;

    public int $busyMinutes = 30;

    public int $busyDelay = 10;

    public bool $receiveCancelApply = true;

    public bool $receiveRefundApply = true;

    public bool $showDisconnectModal = false;

    public bool $showSuspendModal = false;

    public function mount(): void
    {
        $company = app('current.company');

        abort_unless(config('food99.enabled'), 404);
        abort_unless($company->canUseFood99Integration(), 403, 'Módulo de Integrações não está habilitado para esta empresa.');

        $branches = Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get();
        $integrations = Food99Integration::where('company_id', $company->id)->get()->keyBy('branch_id');

        $existing = $integrations->sortBy('id')->firstWhere(fn (Food99Integration $integration) => $integration->shop_id !== null) ?? $integrations->sortBy('id')->first();
        $branch = $existing ? $branches->firstWhere('id', $existing->branch_id) : $branches->first();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'loja';
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

    public function connect(): void
    {
        [$company, $branch, $integration] = $this->context(createIntegration: true);

        try {
            app(Food99AuthService::class)->authorizationUrl($integration, force: $integration->status === Food99Integration::STATUS_ERROR);
        } catch (Throwable $e) {
            $this->flashFailure($e);
        }

        $this->loadForBranch($company, $branch);
    }

    /** Desiste da conexão em andamento (ainda sem loja ligada). */
    public function cancelConnection(): void
    {
        [$company, $branch, $integration] = $this->context();

        if ($integration && $integration->shop_id === null) {
            $integration->update([
                'status' => Food99Integration::STATUS_DISCONNECTED,
                'authorization_url' => null,
                'authorization_url_expires_at' => null,
            ]);
        }

        $this->loadForBranch($company, $branch);
    }

    /**
     * Procura a loja pelo ID informado (o restaurante vê no app/portal da 99Food) e pede
     * confirmação: o ID não prova de quem é a loja, então o nome precisa passar pelo olho do
     * admin antes de os pedidos dela começarem a chegar nesta filial.
     */
    public function linkShop(): void
    {
        [$company, $branch, $integration] = $this->context(createIntegration: true);

        if (! $this->canChangeShop($integration)) {
            $this->loadForBranch($company, $branch);

            return;
        }

        $this->validate(['shopIdInput' => ['required', 'regex:/^\d{5,20}$/']], [
            'shopIdInput.required' => 'Informe o ID da loja na 99Food.',
            'shopIdInput.regex' => 'O ID da loja tem só números.',
        ]);

        try {
            $this->pendingShop = app(Food99AuthService::class)->connectableShop($integration, $this->shopIdInput);
        } catch (Throwable $e) {
            $this->flashFailure($e);
        }
    }

    public function cancelLinkShop(): void
    {
        $this->pendingShop = null;
    }

    public function confirmLinkShop(): void
    {
        [$company, $branch, $integration] = $this->context(createIntegration: true);
        $shop = $this->pendingShop;

        if (! $shop || ! $this->canChangeShop($integration)) {
            $this->loadForBranch($company, $branch);

            return;
        }

        try {
            $warnings = app(Food99AuthService::class)->selectShop($integration, $shop['shop_id']);
            session()->flash('status', 'Loja da 99Food conectada a esta filial.'.($warnings !== [] ? ' '.implode(' ', $warnings) : ''));
            Log::channel('food99')->info('99Food: loja ligada pelo admin', [
                'food99_integration_id' => $integration->id,
                'company_id' => $company->id,
                'user_id' => auth()->id(),
            ]);
        } catch (Throwable $e) {
            $this->flashFailure($e);
        }

        $this->loadForBranch($company, $branch);
    }

    /** Homologação: lista as lojas que autorizaram o app (a lista é da plataforma inteira). */
    public function loadAuthorizedShops(): void
    {
        $this->context();
        abort_unless(config('food99.homologation_mode') || auth()->user()->isSuperAdmin(), 403);

        try {
            $this->availableShops = app(Food99AuthService::class)->authorizedShops($this->currentIntegrationId());
        } catch (Throwable $e) {
            $this->flashFailure($e);
        }
    }

    public function chooseShop(string $shopId): void
    {
        abort_unless(config('food99.homologation_mode') || auth()->user()->isSuperAdmin(), 403);

        $this->shopIdInput = preg_replace('/\D/', '', $shopId);
        $this->linkShop();
    }

    public function reapplySettings(): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);

        try {
            $warnings = app(Food99AuthService::class)->activate($integration);
            session()->flash('status', $warnings === [] ? 'Configuração da loja reaplicada na 99Food.' : implode(' ', $warnings));
        } catch (Throwable $e) {
            $this->flashFailure($e);
        }

        $this->loadForBranch($company, $branch);
    }

    /** Suspende: a loja fecha na 99 e pedidos novos deixam de entrar; os em andamento seguem. */
    public function suspend(): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);
        $this->showSuspendModal = false;

        $integration->update(['status' => Food99Integration::STATUS_PAUSED]);
        $closed = $this->tryStoreAction(fn () => app(Food99StoreStatusSync::class)->close($integration));

        session()->flash($closed ? 'status' : 'error', $closed
            ? 'Integração suspensa. A loja fica fechada na 99Food até retomar.'
            : 'Integração suspensa, mas a 99Food não confirmou o fechamento da loja. Feche pelo app da 99Food.');
        $this->loadForBranch($company, $branch);
    }

    public function resume(): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);

        try {
            // Enquanto estava suspensa, outra empresa pode ter conectado a mesma loja.
            app(Food99AuthService::class)->ensureShopAvailable($integration, $integration->shop_id, $integration->app_shop_id);
        } catch (Throwable $e) {
            $this->flashFailure($e);
            $this->loadForBranch($company, $branch);

            return;
        }

        $integration->update(['status' => Food99Integration::STATUS_ACTIVE]);
        $this->tryStoreAction(fn () => app(Food99StoreStatusSync::class)->revokeClose($integration));
        if ($integration->hasPublishedCatalog()) {
            SyncFood99CatalogJob::debounce($integration->branch_id);
        }

        session()->flash('status', 'Integração retomada. A loja volta a abrir na 99Food no horário de funcionamento.');
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
            app(Food99AuthService::class)->disconnect($integration);
            session()->flash('status', 'Integração com a 99Food desconectada.');
        }

        $this->loadForBranch($company, $branch);
    }

    // ── Loja ──────────────────────────────────────────────────────────────

    public function refreshStore(): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);

        $this->tryStoreAction(fn () => app(Food99AuthService::class)->syncShopDetail($integration));
        $this->loadForBranch($company, $branch);
    }

    public function storeAction(string $action): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);
        $sync = app(Food99StoreStatusSync::class);

        $done = $this->tryStoreAction(fn () => match ($action) {
            'open' => $sync->open($integration),
            'pause' => $sync->pause($integration, $this->pauseTime, $this->pauseReason),
            'close' => $sync->close($integration),
            'revoke' => $sync->revokeClose($integration),
            'busy' => $sync->busy($integration, $this->busyMinutes, $this->busyDelay),
            'busy-off' => $sync->endBusy($integration),
            default => abort(404),
        });

        if ($done) {
            session()->flash('status', 'Pedido enviado à 99Food. O status da loja atualiza assim que ela confirmar.');
        }
        $this->loadForBranch($company, $branch);
    }

    // ── Cardápio ──────────────────────────────────────────────────────────

    /** @return array{items: int, groups: int, categories: int, issues: array<int, array{name: string, reason: string}>, warnings: array<int, array{name: string, reason: string}>}|null */
    #[Computed]
    public function menuPreview(): ?array
    {
        [, , $integration] = $this->context();

        if (! $integration?->shop_id || $this->tab !== 'cardapio') {
            return null;
        }

        try {
            $built = app(Food99CatalogSyncService::class)->buildMenu($integration);
        } catch (Throwable $e) {
            return ['items' => 0, 'groups' => 0, 'categories' => 0, 'issues' => [['name' => 'Cardápio', 'reason' => $e->getMessage()]], 'warnings' => []];
        }

        $names = \App\Models\Product::withoutGlobalScopes()->whereIn('id', array_keys($built['issues'] + $built['warnings']))->pluck('name', 'id');
        $label = fn (array $list) => collect($list)->map(fn ($reason, $id) => ['name' => $names[$id] ?? "#{$id}", 'reason' => $reason])->values()->all();

        return [
            'items' => count($built['products']),
            'groups' => count($built['menu']['modifier_groups']),
            'categories' => count($built['menu']['categories']),
            'issues' => $label($built['issues']),
            'warnings' => $label($built['warnings']),
        ];
    }

    public function publishCatalog(): void
    {
        $this->queueCatalog(publish: true);
    }

    public function syncCatalogNow(): void
    {
        $this->queueCatalog(publish: false);
    }

    /** Enquanto o envio roda na fila, a aba Cardápio relê o resultado sozinha. */
    public function refreshCatalogStatus(): void
    {
        [$company, $branch] = $this->context();

        $this->loadForBranch($company, $branch);
    }

    public function checkCatalogTask(): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);

        $this->tryStoreAction(fn () => app(Food99CatalogSyncService::class)->refreshTaskStatus($integration));
        $this->loadForBranch($company, $branch);
    }

    // ── Pedidos ───────────────────────────────────────────────────────────

    public function saveApplySettings(): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);

        $done = $this->tryStoreAction(fn () => app(Food99GatewayContract::class)->setApplyConfig($integration, $this->receiveCancelApply, $this->receiveRefundApply));
        if ($done) {
            $integration->update(['receive_cancel_apply' => $this->receiveCancelApply, 'receive_refund_apply' => $this->receiveRefundApply]);
            session()->flash('status', 'Preferências de solicitações do cliente salvas na 99Food.');
        }
        $this->loadForBranch($company, $branch);
    }

    // ── Diagnóstico ───────────────────────────────────────────────────────

    #[Computed]
    public function recentEvents()
    {
        [, , $integration] = $this->context();

        return $integration && $this->tab === 'diagnostico'
            ? Food99WebhookEvent::where('food99_integration_id', $integration->id)->latest('id')->limit(30)
                ->get(['id', 'event_type', 'external_order_id', 'status', 'attempts', 'error', 'request_id', 'created_at'])
            : collect();
    }

    public function reprocessEvent(int $eventId): void
    {
        [, , $integration] = $this->context(requireShop: true);

        $event = Food99WebhookEvent::where('food99_integration_id', $integration->id)
            ->where('status', Food99WebhookEvent::STATUS_FAILED)
            ->findOrFail($eventId);

        $event->update(['status' => Food99WebhookEvent::STATUS_PENDING, 'attempts' => 0, 'error' => null]);
        ProcessFood99EventJob::dispatch($event->id);

        Log::channel('food99')->info('99Food: evento reprocessado pelo admin', [
            'food99_webhook_event_id' => $event->id,
            'user_id' => auth()->id(),
        ]);
        session()->flash('status', 'Evento enviado para reprocessamento.');
        unset($this->recentEvents);
    }

    // ── Internos ──────────────────────────────────────────────────────────

    private function queueCatalog(bool $publish): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);

        if ($integration->status !== Food99Integration::STATUS_ACTIVE) {
            session()->flash('error', 'Retome a integração antes de enviar o cardápio.');

            return;
        }
        if (! $publish && ! $integration->hasPublishedCatalog()) {
            session()->flash('error', 'Publique o cardápio primeiro.');

            return;
        }
        if ($integration->isCatalogSyncing()) {
            session()->flash('status', 'O cardápio já está sendo enviado à 99Food.');

            return;
        }

        $integration->update(['catalog_sync_status' => 'queued', 'catalog_sync_error' => null, 'catalog_queued_at' => now()]);

        try {
            SyncFood99CatalogJob::dispatch($integration->branch_id, $publish);
        } catch (Throwable $e) {
            // Fila fora do ar não chega a rodar o job: sem isto o envio ficaria "na fila" com o botão travado.
            report($e);
            Food99Integration::whereKey($integration->id)->where('catalog_sync_status', 'queued')->update([
                'catalog_sync_status' => 'failed',
                'catalog_sync_error' => 'Não foi possível iniciar o envio do cardápio. Tente de novo em instantes.',
            ]);
            $this->loadForBranch($company, $branch);

            return;
        }

        session()->flash('status', $publish
            ? 'Cardápio enviado para publicação. Ele substitui o cardápio atual da loja na 99Food.'
            : 'Envio do cardápio na fila. O resultado aparece aqui em instantes.');

        $this->loadForBranch($company, $branch);
    }

    /** @return array{0: Company, 1: ?Branch, 2: ?Food99Integration} */
    private function context(bool $createIntegration = false, bool $requireShop = false): array
    {
        $user = auth()->user();
        $company = app('current.company');

        abort_unless($company->canUseFood99Integration(), 403);
        abort_unless($user && ($user->isSuperAdmin() || $user->isCompanyAdmin($company)), 403);

        $branch = $this->currentBranch($company);
        abort_unless($branch !== null, 404);

        $integration = Food99Integration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();

        if (! $integration && $createIntegration) {
            $integration = Food99Integration::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'app_shop_id' => Food99Integration::appShopIdFor($company->id, $branch->id),
                'status' => Food99Integration::STATUS_DISCONNECTED,
            ]);
        }

        abort_if($requireShop && ! $integration?->shop_id, 404);

        return [$company, $branch, $integration];
    }

    private function currentBranch(Company $company): ?Branch
    {
        return $this->branchId ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId) : null;
    }

    private function loadForBranch(Company $company, ?Branch $branch): void
    {
        $integration = $branch ? Food99Integration::where('company_id', $company->id)->where('branch_id', $branch->id)->first() : null;

        $this->connectionState = match (true) {
            $integration === null => 'not_connected',
            $integration->shop_id !== null && $integration->status === Food99Integration::STATUS_ERROR => 'error',
            $integration->shop_id !== null && in_array($integration->status, [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED], true) => 'connected',
            $integration->shop_id !== null => 'activating',
            $integration->status === Food99Integration::STATUS_PENDING_AUTHORIZATION && $integration->isAuthorizationUrlValid() => 'pending_authorization',
            default => 'not_connected',
        };

        // Ligar uma loja ou trocar de filial: nada digitado aqui pode valer na filial seguinte.
        $this->resetErrorBag();
        $this->shopIdInput = '';
        $this->pendingShop = null;
        $this->branchOptions = $this->branchOptionsFor($company);
        $this->loadStoreState($integration);

        $this->authorizationUrl = $integration?->isAuthorizationUrlValid() ? $integration->authorization_url : null;
        $this->authorizationUrlExpiresAt = $integration?->authorization_url_expires_at?->timezone(config('app.timezone'))->format('d/m/Y H:i');
        $this->receiveCancelApply = (bool) ($integration?->receive_cancel_apply ?? true);
        $this->receiveRefundApply = (bool) ($integration?->receive_refund_apply ?? true);
        $this->availableShops = [];

        $this->integrationData = $integration ? [
            'shop_id' => $integration->shop_id,
            'shop_name' => $integration->shop_name,
            'app_shop_id' => $integration->app_shop_id,
            'status' => $integration->status,
            'sub_biz_status' => $integration->sub_biz_status,
            'store_label' => $integration->sub_biz_status !== null ? (Food99ShopEventHandler::SUB_BIZ_STATUS_LABELS[$integration->sub_biz_status] ?? "Status {$integration->sub_biz_status}") : null,
            'webhook_silent' => $integration->isWebhookSilent(Food99ConnectionMonitor::WEBHOOK_SILENT_HOURS),
            'auto_switch' => $integration->auto_switch,
            'prepare_minutes' => $integration->promise_produce_time ? (int) round($integration->promise_produce_time / 60) : null,
            'busy' => $integration->isBusy(),
            'unavailable_reason' => $integration->store_unavailable_reason,
            'unavailable_until' => $integration->store_unavailable_until?->timezone(config('app.timezone'))->format('d/m H:i'),
            'store_updated_at' => $integration->store_status_updated_at?->timezone(config('app.timezone'))->format('d/m H:i'),
            'token_expires_at' => $integration->token_expires_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            'last_webhook_at' => $integration->last_webhook_received_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            'webhook_status' => $integration->webhook_status,
            'last_request_id' => $integration->last_request_id,
            'catalog_published' => $integration->hasPublishedCatalog(),
            'catalog_sync_status' => $integration->catalog_sync_status,
            'catalog_busy' => $integration->isCatalogSyncing(),
            'catalog_sync_error' => $integration->catalog_sync_error,
            'catalog_task_id' => $integration->catalog_task_id,
            'catalog_synced_at' => $integration->catalog_synced_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
        ] : [];

        unset($this->menuPreview, $this->recentEvents);
    }

    /** @return array<int, array{id: int, name: string, state: string}> */
    private function branchOptionsFor(Company $company): array
    {
        $integrations = Food99Integration::where('company_id', $company->id)->get()->keyBy('branch_id');

        return Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'state' => $this->branchStateLabel($integrations->get($branch->id)),
            ])->all();
    }

    private function branchStateLabel(?Food99Integration $integration): string
    {
        return match (true) {
            $integration === null => 'não conectada',
            $integration->shop_id === null && $integration->status === Food99Integration::STATUS_PENDING_AUTHORIZATION && $integration->isAuthorizationUrlValid() => 'aguardando autorização',
            $integration->shop_id === null => 'não conectada',
            $integration->status === Food99Integration::STATUS_PAUSED => 'suspensa',
            $integration->status === Food99Integration::STATUS_ERROR => 'reconexão necessária',
            $integration->status === Food99Integration::STATUS_ACTIVE => 'conectada',
            default => 'ativação pendente',
        };
    }

    /** Trocar a loja de quem está operando exige desconectar antes (o unbind passa por lá). */
    private function canChangeShop(Food99Integration $integration): bool
    {
        if ($integration->shop_id === null || $integration->status === Food99Integration::STATUS_ERROR) {
            return true;
        }

        session()->flash('error', 'Esta filial já tem uma loja da 99Food. Desconecte antes de trocar.');

        return false;
    }

    private function currentIntegrationId(): ?int
    {
        return $this->context()[2]?->id;
    }

    private function tryStoreAction(callable $action): bool
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
        if ($e instanceof QueryException || (! $e instanceof Food99ApiException && ! $e instanceof \RuntimeException && ! $e instanceof \InvalidArgumentException)) {
            report($e);
            session()->flash('error', 'Não foi possível concluir agora. Tente de novo em instantes.');

            return;
        }

        session()->flash('error', Food99ApiException::messageFor($e));
    }

    public function render()
    {
        return view('livewire.admin.settings.food99-integration-settings', [
            'homologationMode' => (bool) config('food99.homologation_mode'),
            'tabs' => [
                'loja' => 'Loja',
                'pausas' => 'Pausas',
                'horarios' => 'Horários',
                'cardapio' => 'Cardápio',
                'pedidos' => 'Pedidos',
                'diagnostico' => 'Diagnóstico',
            ],
            'pauseTimes' => Food99StoreStatusSync::PAUSE_TIMES,
            'pauseReasons' => Food99StoreStatusSync::PAUSE_REASONS,
            'weekDays' => Food99StoreHours::WEEK_DAYS,
        ])->layout('layouts.app', ['title' => 'Integração 99Food']);
    }
}
