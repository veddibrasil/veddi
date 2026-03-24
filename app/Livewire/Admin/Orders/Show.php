<?php

namespace App\Livewire\Admin\Orders;

use App\Events\AdminMessageSent;
use App\Events\OrderStatusUpdated;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Services\StockService;
use Illuminate\Support\Facades\Log;
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

        $previousStatus = $this->order->status;

        $this->order->update(['status' => $status]);

        if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
            app(StockService::class)->restoreForOrder($this->order);
        }
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

    public function manualRefund(): void
    {
        $this->order->loadMissing('payment');

        $payment = $this->order->payment;

        if (! $payment || $payment->status !== 'paid') {
            session()->flash('status', 'Pagamento não elegível para reembolso.');

            return;
        }

        $payment->update(['status' => 'refunded']);

        if ($this->order->status !== 'cancelled') {
            $this->order->update(['status' => 'cancelled']);
            $this->order->refresh();
            app(StockService::class)->restoreForOrder($this->order);
            OrderStatusUpdated::dispatch($this->order);
        }

        Log::channel('payments')->info('Reembolso manual marcado pelo admin', [
            'order_id'   => $this->order->id,
            'billing_id' => $payment->abacatepay_billing_id,
        ]);

        session()->flash('status', 'Pagamento marcado como reembolsado.');
    }

    public function render()
    {
        return view('livewire.admin.orders.show')
            ->layout('layouts.app', ['title' => "Pedido {$this->order->order_number}"]);
    }
}
