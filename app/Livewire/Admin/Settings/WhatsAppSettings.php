<?php

namespace App\Livewire\Admin\Settings;

use App\Contracts\WhatsAppProviderInterface;
use App\DTOs\WhatsAppSender;
use App\Exceptions\WhatsAppApiException;
use App\Exceptions\WhatsAppOnboardingException;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\WhatsAppOnboardingService;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class WhatsAppSettings extends Component
{
    /** Estados em que a conexão ainda está sendo montada (a tela atualiza sozinha). */
    private const IN_PROGRESS = [
        WhatsAppConnection::STATUS_PENDING,
        WhatsAppConnection::STATUS_PROVISIONING,
        WhatsAppConnection::STATUS_TEMPLATES_PENDING,
    ];

    /** Máximo de mensagens de teste por empresa a cada 10 minutos (cada uma é um envio real). */
    private const TEST_LIMIT = 5;

    public bool $enabled = false;

    public bool $notifyOnNewOrder = true;

    public bool $notifyOnAwaitingPayment = true;

    public bool $notifyOnPaid = true;

    public bool $notifyOnScheduled = true;

    public bool $notifyOnPreparing = true;

    public bool $notifyOnReady = true;

    public bool $notifyOnOutForDelivery = true;

    public bool $notifyOnDelivered = true;

    public bool $notifyOnCancelled = true;

    public bool $notifyOnAdminMessage = false;

    // ── Conexão ──────────────────────────────────────────────────────────────

    /** Erro da última tentativa de conectar (validação/negócio). Erros da Meta ficam em connection.last_error. */
    public ?string $signupError = null;

    public bool $showDisconnectModal = false;

    public bool $showTestModal = false;

    public string $testPhone = '';

    public ?string $testResult = null;

    public bool $testFailed = false;

    public ?string $notice = null;

    public function mount(): void
    {
        $settings = app('current.company')->whatsappSetting;

        if ($settings) {
            $this->enabled = $settings->enabled;
            $this->notifyOnNewOrder = $settings->notify_on_new_order;
            $this->notifyOnAwaitingPayment = $settings->notify_on_awaiting_payment;
            $this->notifyOnPaid = $settings->notify_on_paid;
            $this->notifyOnScheduled = $settings->notify_on_scheduled;
            $this->notifyOnPreparing = $settings->notify_on_preparing;
            $this->notifyOnReady = $settings->notify_on_ready;
            $this->notifyOnOutForDelivery = $settings->notify_on_out_for_delivery;
            $this->notifyOnDelivered = $settings->notify_on_delivered;
            $this->notifyOnCancelled = $settings->notify_on_cancelled;
            $this->notifyOnAdminMessage = $settings->notify_on_admin_message;
        }
    }

    /** Conexão da empresa atual. Computed (não é propriedade pública): token e PIN nunca vão ao navegador. */
    #[Computed]
    public function connection(): ?WhatsAppConnection
    {
        return app('current.company')->whatsappConnection()->with('templates')->first();
    }

    /** Envio possível de fato (conexão ativa ou fallback da plataforma): só então os toggles valem. */
    #[Computed]
    public function canConfigure(): bool
    {
        return app(WhatsAppService::class)->resolveSender(app('current.company')) !== null;
    }

    /**
     * Templates exigidos para ativar a conexão, com o status de cada um na WABA do restaurante.
     *
     * @return array<int, array{event: string, label: string, name: string, status: ?string, reason: ?string}>
     */
    #[Computed]
    public function templateProgress(): array
    {
        $existing = $this->connection?->templates->keyBy('event') ?? collect();

        return collect(config('whatsapp_templates.templates'))
            ->map(fn (array $definition, string $event) => [
                'event' => $event,
                'label' => WhatsAppTemplate::eventLabel($event),
                'name' => $definition['name'],
                'status' => $existing->get($event)?->status,
                'reason' => $existing->get($event)?->rejection_reason,
            ])
            ->values()
            ->all();
    }

    public function save(): void
    {
        $company = app('current.company');

        WhatsAppSetting::updateOrCreate(
            ['company_id' => $company->id],
            [
                'enabled' => $this->enabled,
                'notify_on_new_order' => $this->notifyOnNewOrder,
                'notify_on_awaiting_payment' => $this->notifyOnAwaitingPayment,
                'notify_on_paid' => $this->notifyOnPaid,
                'notify_on_scheduled' => $this->notifyOnScheduled,
                'notify_on_preparing' => $this->notifyOnPreparing,
                'notify_on_ready' => $this->notifyOnReady,
                'notify_on_out_for_delivery' => $this->notifyOnOutForDelivery,
                'notify_on_delivered' => $this->notifyOnDelivered,
                'notify_on_cancelled' => $this->notifyOnCancelled,
                'notify_on_admin_message' => $this->notifyOnAdminMessage,
            ]
        );

        session()->flash('status', 'Configurações de WhatsApp salvas com sucesso.');
        $this->redirect(route('admin.settings.whatsapp'));
    }

    /**
     * Chamado pelo Alpine (whatsappSignup) ao fim do Embedded Signup. Os ids vêm do navegador e
     * são só dicas: o serviço os confere contra o token antes de qualquer efeito. O code é de uso
     * único e nunca é logado nem guardado aqui — vai direto para o job (payload criptografado).
     */
    public function completeSignup(string $code, ?string $wabaId = null, ?string $phoneNumberId = null, string $onboardingType = WhatsAppConnection::TYPE_CLOUD_API): void
    {
        $this->authorize('company.settings');

        $this->signupError = null;
        $this->notice = null;

        $validator = Validator::make(
            ['code' => $code, 'waba_id' => $wabaId, 'phone_number_id' => $phoneNumberId, 'type' => $onboardingType],
            [
                'code' => ['required', 'string', 'max:2048'],
                'waba_id' => ['nullable', 'regex:/^\d{5,32}$/'],
                'phone_number_id' => ['nullable', 'regex:/^\d{5,32}$/'],
                'type' => ['required', Rule::in([WhatsAppConnection::TYPE_CLOUD_API, WhatsAppConnection::TYPE_COEXISTENCE])],
            ],
        );

        if ($validator->fails()) {
            $this->signupError = 'Os dados recebidos da Meta são inválidos. Clique em "Conectar WhatsApp" para refazer.';

            return;
        }

        // Conexão em andamento ou ativa não é recomeçada por engano (duplo clique, aba antiga):
        // recomeçar apagaria o token e os templates dela. Para trocar de número, desconecte antes.
        $current = $this->connection;

        if ($current && ($current->isActive() || in_array($current->status, self::IN_PROGRESS, true))) {
            $this->signupError = 'Já existe uma conexão de WhatsApp em andamento ou ativa. Desconecte-a antes de conectar outro número.';

            return;
        }

        try {
            app(WhatsAppOnboardingService::class)->start(app('current.company'), $code, $wabaId, $phoneNumberId, $onboardingType);
        } catch (WhatsAppOnboardingException $e) {
            $this->signupError = $e->getMessage();

            return;
        }

        unset($this->connection, $this->templateProgress);
    }

    public function confirmDisconnect(): void
    {
        $this->authorize('company.settings');

        $this->showDisconnectModal = true;
    }

    public function disconnect(): void
    {
        $this->authorize('company.settings');

        $connection = $this->connection;

        if ($connection && $connection->status !== WhatsAppConnection::STATUS_DISCONNECTED) {
            app(WhatsAppOnboardingService::class)->disconnect($connection);
            $this->notice = 'WhatsApp desconectado. Os clientes deixam de receber notificações por este número.';
        }

        $this->showDisconnectModal = false;
        $this->signupError = null;

        unset($this->connection, $this->canConfigure, $this->templateProgress);
    }

    public function openTestModal(): void
    {
        $this->authorize('company.settings');

        $this->reset('testPhone', 'testResult', 'testFailed');
        $this->showTestModal = true;
    }

    /**
     * Envia o template "Em preparo" com dados fictícios para o celular informado, pela conexão da
     * empresa. Serve para conferir que o número e os templates estão funcionando de ponta a ponta.
     */
    public function sendTest(WhatsAppService $service, WhatsAppProviderInterface $provider): void
    {
        $this->authorize('company.settings');

        $this->testResult = null;
        $this->testFailed = true;

        $phone = $service->normalizePhone($this->testPhone);

        if ($phone === null) {
            $this->testResult = 'Informe um celular válido, com DDD e 9 dígitos.';

            return;
        }

        $connection = $this->connection;
        $template = config('whatsapp_templates.templates.preparing');

        if (! $connection?->isActive() || $connection->approvedTemplate('preparing') === null) {
            $this->testResult = 'A conexão ainda não está ativa. Aguarde a aprovação dos modelos de mensagem.';

            return;
        }

        $key = 'whatsapp-test:'.$connection->company_id;

        if (RateLimiter::tooManyAttempts($key, self::TEST_LIMIT)) {
            $this->testResult = 'Muitos testes seguidos. Tente novamente em alguns minutos.';

            return;
        }

        RateLimiter::hit($key, 600);

        try {
            $provider->sendTemplate(
                WhatsAppSender::fromConnection($connection),
                $phone,
                $template['name'],
                $template['example'],
                (string) config('whatsapp_templates.language', 'pt_BR'),
            );
        } catch (WhatsAppApiException $e) {
            $this->testResult = WhatsAppMessage::describeError($e->getCode() ? (string) $e->getCode() : null);

            return;
        }

        $this->testFailed = false;
        $this->testResult = 'Mensagem de teste enviada. Ela deve chegar no WhatsApp em instantes.';
    }

    public function render()
    {
        return view('livewire.admin.settings.whats-app-settings', [
            'meta' => [
                'appId' => (string) config('services.meta.app_id'),
                'configId' => (string) config('services.whatsapp.es_config_id'),
                'graphVersion' => (string) config('services.meta.graph_version'),
            ],
            'canManage' => auth()->user()?->can('company.settings') ?? false,
            'templateStatusLabels' => WhatsAppTemplate::STATUS_LABELS,
        ])->layout('layouts.app', ['title' => 'Notificações WhatsApp']);
    }
}
