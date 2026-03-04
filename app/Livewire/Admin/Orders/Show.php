<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use Livewire\Component;

class Show extends Component
{
    public Order $order;

    public function updateStatus(string $status): void
    {
        $allowed = ['pending', 'awaiting_payment', 'paid', 'preparing', 'ready', 'delivered', 'cancelled'];

        if (! in_array($status, $allowed)) {
            $this->addError('status', 'Status inválido.');

            return;
        }

        $this->order->update(['status' => $status]);
        $this->order->refresh();
        session()->flash('status', 'Status atualizado.');
    }

    public function render()
    {
        return view('livewire.admin.orders.show')
            ->layout('layouts.app', ['title' => "Pedido {$this->order->order_number}"]);
    }
}
