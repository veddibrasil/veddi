<?php

namespace App\Livewire\Admin;

use Livewire\Component;

class Notifications extends Component
{
    public int $companyId = 0;

    public array $notifications = [];

    /** Estação do usuário logado ('cozinha'|'bar'|'entrega') quando o papel é restrito a uma estação, senão null. */
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
            "echo:orders.{$this->companyId},NewOrderPlaced" => 'onNewOrder',
            "echo:orders.{$this->companyId},OrderItemsUpdated" => 'onOrderItemsUpdated',
        ];
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
