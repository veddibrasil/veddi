<?php

namespace App\Services\Support;

use App\Events\AdminSupportMessageSent;
use App\Events\NewSupportMessage;
use App\Events\SupportMessageSent;
use App\Events\SupportTicketClosed;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Collection;

class SupportService
{
    /**
     * Retorna o ticket aberto mais recente do cliente nessa empresa,
     * ou cria um novo se não existir.
     */
    public function createOrFindOpenTicket(int $companyId, int $customerId): SupportTicket
    {
        $existing = SupportTicket::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', 'open')
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        return SupportTicket::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'status' => 'open',
        ]);
    }

    /**
     * Envia uma mensagem do cliente e dispara os eventos de broadcast.
     */
    public function sendCustomerMessage(int $ticketId, string $message): SupportMessage
    {
        $msg = SupportMessage::create([
            'ticket_id' => $ticketId,
            'sender' => 'customer',
            'message' => $message,
        ]);

        $ticket = SupportTicket::withoutGlobalScopes()->find($ticketId);

        SupportMessageSent::dispatch($msg);

        if ($ticket) {
            NewSupportMessage::dispatch($msg, $ticket);
        }

        return $msg;
    }

    /**
     * Envia uma mensagem do admin e dispara o evento de broadcast.
     * Funciona tanto para o painel web quanto para uma futura integração WhatsApp.
     */
    public function sendAdminMessage(int $ticketId, string $message): SupportMessage
    {
        $msg = SupportMessage::create([
            'ticket_id' => $ticketId,
            'sender' => 'admin',
            'message' => $message,
            'read_at' => now(),
        ]);

        AdminSupportMessageSent::dispatch($msg);

        return $msg;
    }

    /**
     * Busca mensagens do admin ainda não vistas pelo cliente (polling de fallback).
     */
    public function pollAdminMessages(int $ticketId, ?int $lastMessageId): Collection
    {
        $query = SupportMessage::where('ticket_id', $ticketId)
            ->where('sender', 'admin');

        if ($lastMessageId !== null) {
            $query->where('id', '>', $lastMessageId);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Retorna todas as mensagens de um ticket em ordem cronológica.
     */
    public function getConversation(int $ticketId): Collection
    {
        return SupportMessage::where('ticket_id', $ticketId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Encerra um ticket e dispara o evento de broadcast para o cliente.
     */
    public function closeTicket(int $ticketId): void
    {
        $ticket = SupportTicket::find($ticketId);

        if (! $ticket) {
            return;
        }

        $ticket->update(['status' => 'closed']);
        SupportTicketClosed::dispatch($ticket);
    }

    /**
     * Reabre um ticket encerrado.
     */
    public function reopenTicket(int $ticketId): void
    {
        SupportTicket::find($ticketId)?->update(['status' => 'open']);
    }

    /**
     * Marca as mensagens do outro lado como lidas.
     * Ex: quando o admin lê ($readerSender = 'admin'), marca mensagens do cliente como lidas.
     */
    public function markMessagesAsRead(int $ticketId, string $readerSender): void
    {
        $senderToMark = $readerSender === 'admin' ? 'customer' : 'admin';

        SupportMessage::where('ticket_id', $ticketId)
            ->where('sender', $senderToMark)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
