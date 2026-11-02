<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Services\Order\ChatOrderAcceptance;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Notifications extends Component
{
    #[Locked]
    public int $companyId = 0;

    public array $notifications = [];

    /** Estação do usuário logado ('cozinha'|'bar'|'entrega') quando o papel é restrito a uma estação, senão null. */
    #[Locked]
    public ?string $userStation = null;

    public function mount(): void
    {
        if (app()->bound('current.company')) {
            $company = app('current.company');
            $this->companyId = $company->id;

            $roleSlug = auth()->user()?->roleForCompany($company);
            $this->userStation = in_array($roleSlug, ['cozinha', 'bar', 'entrega']) ? $roleSlug : null;
        }
    }

    public function getListeners(): array
    {
        if (! $this->companyId) {
            return [];
        }

        return [
            'chat-order-accepted' => '$refresh',
            "echo-private:orders.{$this->companyId},NewOrderPlaced" => 'onNewOrder',
            "echo-private:orders.{$this->companyId},OrderItemsUpdated" => 'onOrderItemsUpdated',
            "echo-private:orders.{$this->companyId},OrderStatusUpdated" => '$refresh',
        ];
    }

    #[Computed]
    public function pendingChatOrders(): \Illuminate\Support\Collection
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();
        if (! $company || $company->id !== $this->companyId || ! $user || $this->userStation
            || ! ($user->isSuperAdmin() || ($user->hasPermission('orders.view', $company) && $user->hasPermission('orders.update', $company)))) {
            return collect();
        }

        return Order::where('company_id', $this->companyId)->where('channel', 'chat')
            ->where('order_type', '!=', 'pdv')->whereNull('chat_accepted_at')
            ->where(fn ($q) => $q->whereIn('status', ['paid', 'scheduled'])
                ->orWhere(fn ($q) => $q->where('status', 'awaiting_payment')->where('payment_on_delivery', true)))
            ->when($user->isBranchScoped($company), fn ($q) => $q->where('branch_id', $user->branchIdForCompany($company)))
            ->with(['customer', 'company'])->oldest()->get()
            ->reject(fn (Order $order) => $order->chatAcceptanceExpired())->values();
    }

    public function acceptChatOrder(int $orderId): void
    {
        $order = Order::where('company_id', $this->companyId)->findOrFail($orderId);
        app(ChatOrderAcceptance::class)->accept($order, auth()->user());
        unset($this->pendingChatOrders);
        $this->dispatch('chat-order-accepted');
    }

    public function onNewOrder(array $data): void
    {
        if ($this->stationFilteredOut($data)) {
            return;
        }

        $this->pushNotification([
            'type' => 'new_order',
            'order_id' => $data['order_id'],
            'order_number' => $data['order_number'],
            'customer_name' => $data['customer_name'],
            'total' => $data['total'],
            'channel' => $data['channel'] ?? null,
            'order_type' => $data['order_type'] ?? null,
        ]);
    }

    public function onOrderItemsUpdated(array $data): void
    {
        if (empty($data['summary']) || $this->stationFilteredOut($data)) {
            return;
        }

        $this->pushNotification([
            'type' => 'items_updated',
            'order_id' => $data['order_id'],
            'order_number' => $data['order_number'],
            'summary' => $data['summary'],
        ]);
    }

    private function pushNotification(array $notification): void
    {
        $this->notifications[] = $notification + [
            'id' => uniqid('notif_'),
            'created_at' => now()->format('H:i'),
        ];

        if (count($this->notifications) > 5) {
            array_shift($this->notifications);
        }
    }

    /** Estações restritas (cozinha/bar/entrega) só veem notificações de pedidos que envolvem sua estação. */
    private function stationFilteredOut(array $data): bool
    {
        $flag = match ($this->userStation) {
            'entrega' => 'is_delivery',
            'cozinha' => 'is_kitchen',
            'bar' => 'is_bar',
            default => null,
        };

        return $flag !== null && empty($data[$flag]);
    }

    public function dismiss(string $id): void
    {
        $this->notifications = array_values(
            array_filter($this->notifications, fn ($n) => $n['id'] !== $id)
        );
    }

    public function render()
    {
        return view('livewire.admin.notifications');
    }
}
