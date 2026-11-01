<?php

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

use App\Livewire\Chat\OrderChat;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Chat\ChatAccess;

function endingsChat(array $ctx, ?Order $order = null): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->customerId = $ctx['customer']->id;
    verifiedChatFixture($chat, $ctx['customer']);
    $chat->orderType = 'pickup';
    $chat->taxId = $ctx['customer']->tax_id;
    $chat->cart = [$ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1, 'price' => 20, 'name' => 'Coxinha']];
    if ($order) {
        $chat->orderId = $order->id;
        app(ChatAccess::class)->grantOrder($order);
    }

    return $chat;
}

function endingsPayment(Order $order, array $attributes = []): Payment
{
    return $order->payments()->create(array_merge([
        'payment_gateway' => 'vindi', 'amount' => $order->total, 'status' => 'pending',
        'expires_at' => now()->subMinute(), 'payment_token' => bin2hex(random_bytes(16)),
    ], $attributes));
}

function endingsCard(): array
{
    return ['number' => '4111111111111111', 'holderName' => 'Cliente Teste', 'expiryMonth' => '12', 'expiryYear' => '2030', 'ccv' => '123'];
}
