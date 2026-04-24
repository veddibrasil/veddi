<?php

namespace App\Livewire\Chat\Concerns;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ChatService;
use App\Services\SupportService;

trait HasChatSupport
{
    // --- Chat do pedido (order chat) ---

    public function sendSupportMessage(): void
    {
        $this->validate(['supportMessage' => ['required', 'string', 'max:500']]);

        if (! $this->orderId) {
            return;
        }

        $text = $this->supportMessage;
        $this->supportMessage = '';

        app(ChatService::class)->sendCustomerOrderMessage($this->orderId, $text);
        $this->addMessage('user', $text);
    }

    public function receiveAdminMessage(array $data): void
    {
        $message = $data['message'] ?? '';
        if ($message === '') {
            return;
        }

        $this->addMessage('bot', $message);

        if ($this->orderId) {
            $latest = \App\Models\ChatMessage::where('order_id', $this->orderId)
                ->where('sender', 'admin')
                ->max('id');

            if ($latest) {
                $this->lastAdminMessageId = $latest;
                $this->saveToSession();
            }
        }
    }

    public function pollAdminMessages(): void
    {
        if (! $this->orderId) {
            return;
        }

        $newMessages = app(ChatService::class)->pollAdminMessages($this->orderId, $this->lastAdminMessageId);

        foreach ($newMessages as $msg) {
            $this->addMessage('bot', $msg->message);
            $this->lastAdminMessageId = $msg->id;
        }
    }

    // --- Suporte geral (ticket) ---

    public function goToSupport(): void
    {
        if (! $this->customerId) {
            return;
        }

        $company = app()->bound('current.company') ? app('current.company') : null;
        if (! $company) {
            return;
        }

        $this->openSupportTicket();
    }

    public function closeSupportModal(): void
    {
        $this->showSupportModal = false;
        $this->saveToSession();
    }

    public function sendGeneralSupportMessage(): void
    {
        $this->validate(['generalSupportMessage' => ['required', 'string', 'max:500']]);

        if (! $this->supportTicketId) {
            return;
        }

        $text = $this->generalSupportMessage;
        $this->generalSupportMessage = '';

        app(SupportService::class)->sendCustomerMessage($this->supportTicketId, $text);

        $this->supportConversation[] = [
            'sender' => 'customer',
            'message' => $text,
            'created_at' => now()->format('H:i'),
        ];
    }

    public function receiveAdminSupportMessage(array $data): void
    {
        $message = $data['message'] ?? '';
        if ($message === '') {
            return;
        }

        if (! empty($data['message_id'])) {
            $this->lastAdminSupportMessageId = (int) $data['message_id'];
        } else {
            $this->lastAdminSupportMessageId = SupportMessage::where('ticket_id', $this->supportTicketId)
                ->where('sender', 'admin')
                ->max('id') ?? $this->lastAdminSupportMessageId;
        }

        $this->supportConversation[] = [
            'sender' => 'admin',
            'message' => $message,
            'created_at' => now()->format('H:i'),
        ];
    }

    public function pollAdminSupportMessages(): void
    {
        if (! $this->supportTicketId) {
            return;
        }

        $ticket = SupportTicket::withoutGlobalScopes()->find($this->supportTicketId);
        if (! $ticket || $ticket->status === 'closed') {
            $this->onSupportTicketClosed([]);

            return;
        }

        if ($this->lastAdminSupportMessageId === null) {
            $this->lastAdminSupportMessageId = SupportMessage::where('ticket_id', $this->supportTicketId)
                ->where('sender', 'admin')
                ->max('id') ?? 0;
            $this->saveToSession();

            return;
        }

        $newMessages = app(SupportService::class)->pollAdminMessages(
            $this->supportTicketId,
            $this->lastAdminSupportMessageId
        );

        foreach ($newMessages as $msg) {
            $this->supportConversation[] = [
                'sender' => 'admin',
                'message' => $msg->message,
                'created_at' => $msg->created_at->format('H:i'),
            ];
            $this->lastAdminSupportMessageId = $msg->id;
        }
    }

    public function onSupportTicketClosed(array $data): void
    {
        if (! $this->supportTicketId) {
            return;
        }

        $this->supportConversation[] = [
            'sender' => 'system',
            'message' => '✅ Ticket encerrado. Obrigado pelo contato!',
            'created_at' => now()->format('H:i'),
        ];

        $this->supportTicketId = null;
        $this->saveToSession();
    }

    // --- Helper compartilhado para abrir/criar ticket ---

    protected function openSupportTicket(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        if (! $company || ! $this->customerId) {
            return;
        }

        $ticket = app(SupportService::class)->createOrFindOpenTicket($company->id, $this->customerId);
        $this->supportTicketId = $ticket->id;

        $this->supportConversation = app(SupportService::class)
            ->getConversation($this->supportTicketId)
            ->map(fn ($m) => [
                'sender' => $m->sender,
                'message' => $m->message,
                'created_at' => $m->created_at->format('H:i'),
            ])
            ->toArray();

        $this->showSupportModal = true;
        $this->saveToSession();
    }
}
