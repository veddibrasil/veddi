<?php

namespace App\Livewire\Admin\Orders;

use App\Events\AdminMessageSent;
use App\Events\OrderStatusUpdated;
use App\Models\ChatMessage;
use App\Models\Order;
use Livewire\Component;

class Show extends Component
{
    public Order $order;
    public string $adminMessage = '';
    public array $chatMessages = [];

    public function mount(): void
    {
        $this->loadMessages();
    }

    public function getListeners(): array
    {
        return [
            "echo:order.{$this->order->id},CustomerMessageSent" => 'onCustomerMessage',
        ];
    }

    public function onCustomerMessage(array $data): void
    {
        $this->chatMessages[] = [
            'sender'     => 'customer',
            'message'    => $data['message'],
            'created_at' => $data['created_at'],
        ];
    }

    public function sendMessage(): void
    {
        $this->validate(['adminMessage' => ['required', 'string', 'max:500']]);

        $text = $this->adminMessage;
        $this->adminMessage = '';

        ChatMessage::create([
            'order_id' => $this->order->id,
            'sender'   => 'admin',
            'message'  => $text,
        ]);

        AdminMessageSent::dispatch($this->order, $text);

        $this->chatMessages[] = [
            'sender'     => 'admin',
            'message'    => $text,
            'created_at' => now()->format('H:i'),
        ];
    }

    public function updateStatus(string $status): void
    {
        $allowed = ['pending', 'awaiting_payment', 'paid', 'preparing', 'ready', 'delivered', 'cancelled'];

        if (! in_array($status, $allowed)) {
            $this->addError('status', 'Status inválido.');

            return;
        }

        $this->order->update(['status' => $status]);
        $this->order->refresh();

        OrderStatusUpdated::dispatch($this->order);

        session()->flash('status', 'Status atualizado.');
    }

    public function loadMessages(): void
    {
        $this->chatMessages = ChatMessage::where('order_id', $this->order->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'sender'     => $m->sender,
                'message'    => $m->message,
                'created_at' => $m->created_at->format('H:i'),
            ])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.admin.orders.show')
            ->layout('layouts.app', ['title' => "Pedido {$this->order->order_number}"]);
    }
}
