<?php

namespace App\Livewire\Admin\Support;

use App\Events\AdminSupportMessageSent;
use App\Events\SupportTicketClosed;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Suporte'])]
class Index extends Component
{
    use WithPagination;

    public string $statusFilter   = 'open';
    public ?int $selectedTicketId = null;
    public array $conversation    = [];
    public string $replyMessage   = '';

    public function getListeners(): array
    {
        if (! $this->selectedTicketId) {
            return [];
        }

        return [
            "echo:support.{$this->selectedTicketId},SupportMessageSent" => 'onCustomerMessage',
        ];
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->selectedTicketId = null;
        $this->conversation     = [];
    }

    public function selectTicket(int $ticketId): void
    {
        $this->selectedTicketId = $ticketId;
        $this->replyMessage     = '';
        $this->loadConversation();

        SupportMessage::where('ticket_id', $ticketId)
            ->where('sender', 'customer')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function loadConversation(): void
    {
        if (! $this->selectedTicketId) {
            return;
        }

        $this->conversation = SupportMessage::where('ticket_id', $this->selectedTicketId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'sender'     => $m->sender,
                'message'    => $m->message,
                'created_at' => $m->created_at->format('H:i'),
            ])
            ->toArray();
    }

    public function onCustomerMessage(array $data): void
    {
        $this->conversation[] = [
            'sender'     => 'customer',
            'message'    => $data['message'],
            'created_at' => $data['created_at'],
        ];

        if ($this->selectedTicketId) {
            SupportMessage::where('ticket_id', $this->selectedTicketId)
                ->where('sender', 'customer')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }
    }

    public function sendReply(): void
    {
        $this->validate(['replyMessage' => ['required', 'string', 'max:500']]);

        if (! $this->selectedTicketId) {
            return;
        }

        $text = $this->replyMessage;
        $this->replyMessage = '';

        $msg = SupportMessage::create([
            'ticket_id' => $this->selectedTicketId,
            'sender'    => 'admin',
            'message'   => $text,
            'read_at'   => now(),
        ]);

        AdminSupportMessageSent::dispatch($msg);

        $this->conversation[] = [
            'sender'     => 'admin',
            'message'    => $text,
            'created_at' => now()->format('H:i'),
        ];
    }

    public function closeTicket(int $ticketId): void
    {
        $ticket = SupportTicket::find($ticketId);

        if (! $ticket) {
            return;
        }

        $ticket->update(['status' => 'closed']);
        SupportTicketClosed::dispatch($ticket);

        if ($this->selectedTicketId === $ticketId) {
            $this->selectedTicketId = null;
            $this->conversation     = [];
        }

        $this->resetPage();
    }

    public function reopenTicket(int $ticketId): void
    {
        SupportTicket::find($ticketId)?->update(['status' => 'open']);
        $this->resetPage();
    }

    public function render()
    {
        $tickets = SupportTicket::with(['customer', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender', 'customer')->whereNull('read_at')])
            ->latest()
            ->paginate(20);

        return view('livewire.admin.support.index', compact('tickets'));
    }
}
