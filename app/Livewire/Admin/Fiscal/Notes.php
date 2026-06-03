<?php

namespace App\Livewire\Admin\Fiscal;

use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalNoteService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Notes extends Component
{
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $search = '';

    public bool $canView = false;

    public bool $canIssue = false;

    public bool $showCancelModal = false;

    public ?int $cancelNoteId = null;

    public string $cancelJustification = '';

    public function mount(): void
    {
        $company = app('current.company');
        $user = auth()->user();

        $this->canView = $user->hasPermission('fiscal.view', $company);
        $this->canIssue = $user->hasPermission('fiscal.issue', $company);

        abort_unless($this->canView, 403);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openCancelModal(int $noteId): void
    {
        abort_unless($this->canIssue, 403);

        $this->cancelNoteId = $noteId;
        $this->cancelJustification = '';
        $this->showCancelModal = true;
    }

    public function closeCancelModal(): void
    {
        $this->showCancelModal = false;
        $this->cancelNoteId = null;
        $this->cancelJustification = '';
    }

    public function confirmCancel(): void
    {
        abort_unless($this->canIssue, 403);

        $this->validate([
            'cancelJustification' => ['required', 'string', 'min:15', 'max:255'],
        ]);

        $note = FiscalNote::find($this->cancelNoteId);

        if (! $note || ! $note->isActive()) {
            session()->flash('error', 'Nota não encontrada ou não pode ser cancelada.');
            $this->closeCancelModal();

            return;
        }

        app(FiscalNoteService::class)->cancel($note, $this->cancelJustification);

        $this->closeCancelModal();
        session()->flash('status', 'Cancelamento solicitado.');
    }

    public function render()
    {
        $query = FiscalNote::with(['order'])
            ->orderByDesc('created_at');

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('access_key', 'like', '%'.$this->search.'%')
                    ->orWhere('provider_reference', 'like', '%'.$this->search.'%')
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', '%'.$this->search.'%'));
            });
        }

        return view('livewire.admin.fiscal.notes', [
            'notes' => $query->paginate(15),
        ])->layout('layouts.app', ['title' => 'Notas Fiscais']);
    }
}
