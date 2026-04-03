<?php

namespace App\Livewire\Admin\Support;

use App\Models\SupportTicket;
use App\Services\SupportService;
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
    public bool $canView          = false;
    public bool $canReply         = false;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->canView = $this->canReply = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->authorize('viewAny', SupportTicket::class);
            $this->canReply = $user->hasPermission('support.reply', $company);
        }
    }

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

        app(SupportService::class)->markMessagesAsRead($ticketId, 'admin');
    }

    public function loadConversation(): void
    {
        if (! $this->selectedTicketId) {
            return;
        }

        $this->conversation = app(SupportService::class)
            ->getConversation($this->selectedTicketId)
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
            app(SupportService::class)->markMessagesAsRead($this->selectedTicketId, 'admin');
        }
    }

    public function sendReply(): void
    {
        abort_unless($this->canReply, 403);

        $this->validate(['replyMessage' => ['required', 'string', 'max:500']]);

        if (! $this->selectedTicketId) {
            return;
        }

        $text = $this->replyMessage;
        $this->replyMessage = '';

        app(SupportService::class)->sendAdminMessage($this->selectedTicketId, $text);

        $this->conversation[] = [
            'sender'     => 'admin',
            'message'    => $text,
            'created_at' => now()->format('H:i'),
        ];
    }

    public function closeTicket(int $ticketId): void
    {
        abort_unless($this->canReply, 403);

        app(SupportService::class)->closeTicket($ticketId);

        if ($this->selectedTicketId === $ticketId) {
            $this->selectedTicketId = null;
            $this->conversation     = [];
        }

        $this->resetPage();
    }

    public function reopenTicket(int $ticketId): void
    {
        abort_unless($this->canReply, 403);

        app(SupportService::class)->reopenTicket($ticketId);
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
