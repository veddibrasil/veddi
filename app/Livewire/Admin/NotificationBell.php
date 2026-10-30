<?php

namespace App\Livewire\Admin;

use App\Models\Company;
use App\Models\CompanyNotification;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NotificationBell extends Component
{
    // Locked: CompanyNotification não tem escopo global, então a empresa vem só do mount.
    #[Locked]
    public int $companyId = 0;

    public bool $confirmingClearAll = false;

    /** Estação do usuário logado ('cozinha'|'bar'|'entrega') quando o papel é restrito a uma estação, senão null. */
    #[Locked]
    public ?string $userStation = null;

    /** Alertas de conexão (iFood/WhatsApp) levam a telas só de administrador. */
    #[Locked]
    public bool $canOpenIntegrationSettings = false;

    /** Memo por requisição de seesAdminOnly() (propriedade privada não vai para o snapshot). */
    private ?bool $seesAdminOnly = null;

    public function mount(): void
    {
        if (app()->bound('current.company')) {
            $company = app('current.company');
            $this->companyId = $company->id;

            $user = auth()->user();
            $roleSlug = $user?->roleForCompany($company);
            $this->userStation = in_array($roleSlug, ['cozinha', 'bar', 'entrega']) ? $roleSlug : null;
            $this->canOpenIntegrationSettings = (bool) ($user?->isSuperAdmin() || $user?->isCompanyAdmin($company));
        }
    }

    public function getListeners(): array
    {
        if (! $this->companyId) {
            return [];
        }

        return [
            "echo-private:orders.{$this->companyId},NewOrderPlaced" => 'onNewOrder',
            "echo-private:orders.{$this->companyId},OrderItemsUpdated" => 'onNewOrder',
            // Pega o aviso de pedido agendado (NotifyScheduledOrderJob) e outras mudanças de
            // status em tempo real — sem isso a notificação só aparece no próximo re-render.
            "echo-private:orders.{$this->companyId},OrderStatusUpdated" => 'onNewOrder',
            // Aviso de negociação e de cancelamento recusado no iFood.
            "echo-private:orders.{$this->companyId},IfoodOrderUpdated" => 'onNewOrder',
            // Pedido do iFood que não entrou no painel (resolver pelo Gestor de Pedidos do iFood).
            "echo-private:orders.{$this->companyId},IfoodOrderNotImported" => 'onNewOrder',
            // 99Food: solicitação do cliente, entrega interrompida, pedido que não entrou.
            "echo-private:orders.{$this->companyId},Food99OrderUpdated" => 'onNewOrder',
            "echo-private:orders.{$this->companyId},Food99OrderNotImported" => 'onNewOrder',
        ];
    }

    public function onNewOrder(): void
    {
        // The DB record is created by CreateOrderNotification/CreateOrderItemsChangeNotification
        // listener (server-side, once). Here we just re-render to pick up the new notification
        // from the database.
    }

    public function markRead(int $id): void
    {
        $this->visibleNotifications()
            ->where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        $this->visibleNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function delete(int $id): void
    {
        $this->visibleNotifications()
            ->where('id', $id)
            ->delete();
    }

    public function confirmClearAll(): void
    {
        $this->confirmingClearAll = true;
    }

    public function cancelClearAll(): void
    {
        $this->confirmingClearAll = false;
    }

    public function clearAll(): void
    {
        $this->confirmingClearAll = false;
        $this->visibleNotifications()->delete();
    }

    /**
     * Notificações que o usuário pode ver e mexer: as da estação (cozinha/bar/entrega) e, só para
     * company_admin, as marcadas is_admin_only (ex.: vendas). Sem isso o gerente veria faturamento
     * e "Limpar tudo" apagaria os avisos do administrador.
     */
    private function visibleNotifications(): Builder
    {
        return CompanyNotification::where('company_id', $this->companyId)
            ->when($this->stationNotificationColumn(), fn ($q, $column) => $q->where($column, true))
            ->when(! $this->seesAdminOnly(), fn ($q) => $q->where('is_admin_only', false));
    }

    /**
     * Mesma regra das telas só de admin (User::isCompanyAdmin), recalculada a cada requisição e não
     * no mount: admin rebaixado com a aba aberta deixa de ver.
     */
    private function seesAdminOnly(): bool
    {
        if ($this->seesAdminOnly !== null) {
            return $this->seesAdminOnly;
        }

        $company = app()->bound('current.company') && app('current.company')->id === $this->companyId
            ? app('current.company')
            : Company::find($this->companyId);

        return $this->seesAdminOnly = (bool) ($company && auth()->user()?->isCompanyAdmin($company));
    }

    /** Coluna de flag de notificação (is_delivery/is_kitchen/is_bar) pra estação do usuário, ou null se não restrito. */
    private function stationNotificationColumn(): ?string
    {
        return match ($this->userStation) {
            'entrega' => 'is_delivery',
            'cozinha' => 'is_kitchen',
            'bar' => 'is_bar',
            default => null,
        };
    }

    public function render()
    {
        $notifications = $this->visibleNotifications()
            ->latest()
            ->limit(30)
            ->get();

        $unreadCount = $notifications->whereNull('read_at')->count();

        // Sem acesso à tela da integração, o alerta continua no sino mas sem levar a um 403.
        $adminOnlyPaths = $this->canOpenIntegrationSettings ? [] : [route('admin.settings.ifood', absolute: false), route('admin.settings.99food', absolute: false), route('admin.settings.whatsapp', absolute: false)];

        return view('livewire.admin.notification-bell', [
            'notifications' => $notifications,
            'adminOnlyPaths' => $adminOnlyPaths,
            'unreadCount' => $unreadCount,
            'modalName' => 'confirm-clear-notifications-'.$this->getId(),
        ]);
    }
}
