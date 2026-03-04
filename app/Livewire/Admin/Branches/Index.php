<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use Livewire\Component;

class Index extends Component
{
    public ?int $deletingId = null;

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function delete(): void
    {
        Branch::findOrFail($this->deletingId)->delete();
        $this->deletingId = null;
        session()->flash('status', 'Filial removida.');
    }

    public function render()
    {
        return view('livewire.admin.branches.index', [
            'branches' => Branch::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Filiais']);
    }
}
