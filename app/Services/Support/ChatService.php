<?php

namespace App\Services\Support;

use App\Events\AdminMessageSent;
use App\Events\CustomerMessageSent;
use App\Models\ChatMessage;
use App\Models\Order;
use Illuminate\Support\Collection;

class ChatService
{
    /**
     * Envia uma mensagem do cliente no chat do pedido e dispara o evento de broadcast.
     * Funciona tanto para o chat web quanto para uma futura integração WhatsApp.
     */
    public function sendCustomerOrderMessage(int $orderId, string $message): ChatMessage
    {
        $chatMessage = ChatMessage::create([
            'order_id' => $orderId,
            'sender' => 'customer',
            'message' => $message,
        ]);

        CustomerMessageSent::dispatch($chatMessage);

        return $chatMessage;
    }

    /**
     * Envia uma mensagem do admin no chat do pedido e dispara o evento de broadcast.
     * Funciona tanto para o painel web quanto para uma futura integração WhatsApp.
     */
    public function sendAdminOrderMessage(int $orderId, string $message): ChatMessage
    {
        $order = Order::findOrFail($orderId);

        $chatMessage = ChatMessage::create([
            'order_id' => $orderId,
            'sender' => 'admin',
            'message' => $message,
        ]);

        AdminMessageSent::dispatch($order, $message);

        return $chatMessage;
    }

    /**
     * Busca mensagens do admin ainda não vistas pelo cliente (polling de fallback).
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

    /**
     * Retorna todas as mensagens de um pedido em ordem cronológica.
     */
    public function getOrderConversation(int $orderId): Collection
    {
        return ChatMessage::where('order_id', $orderId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @deprecated Use sendCustomerOrderMessage()
     */
    public function sendCustomerMessage(int $orderId, string $message): ChatMessage
    {
        return $this->sendCustomerOrderMessage($orderId, $message);
    }
}
