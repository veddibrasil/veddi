<?php

namespace App\Services;

use App\Events\CustomerMessageSent;
use App\Models\ChatMessage;
use Illuminate\Support\Collection;

class ChatService
{
    /**
     * Envia uma mensagem do cliente e dispara o evento de broadcast.
     */
    public function sendCustomerMessage(int $orderId, string $message): ChatMessage
    {
        $chatMessage = ChatMessage::create([
            'order_id' => $orderId,
            'sender'   => 'customer',
            'message'  => $message,
        ]);

        CustomerMessageSent::dispatch($chatMessage);

        return $chatMessage;
    }

    /**
     * Busca mensagens do admin ainda não vistas pelo cliente.
     */
    public function pollAdminMessages(int $orderId, ?int $lastAdminMessageId): Collection
    {
        $query = ChatMessage::where('order_id', $orderId)
            ->where('sender', 'admin');

        if ($lastAdminMessageId) {
            $query->where('id', '>', $lastAdminMessageId);
        }

        return $query->orderBy('id')->get();
    }
}
