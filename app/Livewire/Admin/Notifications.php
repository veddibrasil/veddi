<?php

namespace App\Livewire\Admin;

use Livewire\Component;

class Notifications extends Component
{
    public int $companyId = 0;

    public array $notifications = [];

    public function mount(): void
    {
        if (app()->bound('current.company')) {
            $this->companyId = app('current.company')->id;
        }
    }

    public function getListeners(): array
    {
        if (! $this->companyId) {
            return [];
        }

        return [
            "echo:orders.{$this->companyId},NewOrderPlaced" => 'onNewOrder',
        ];
    }

    public function onNewOrder(array $data): void
    {
        $this->notifications[] = [
            'id' => uniqid('notif_'),
            'order_id' => $data['order_id'],
            'order_number' => $data['order_number'],
            'customer_name' => $data['customer_name'],
            'total' => $data['total'],
            'created_at' => now()->format('H:i'),
        ];

        if (count($this->notifications) > 5) {
            array_shift($this->notifications);
        }
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
