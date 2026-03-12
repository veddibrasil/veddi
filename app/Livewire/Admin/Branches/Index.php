<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Models\Scopes\CompanyScope;
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
        $branch = Branch::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
        $this->authorize('delete', $branch);
        $branch->delete();
        $this->deletingId = null;
        session()->flash('status', 'Filial removida.');
    }

    public function render()
    {
        $isSuperAdmin = auth()->user()->isSuperAdmin();

        $query = $isSuperAdmin
            ? Branch::withoutGlobalScope(CompanyScope::class)->with('company')->orderBy('name')
            : Branch::orderBy('name');

        return view('livewire.admin.branches.index', [
            'branches'     => $query->get(),
            'isSuperAdmin' => $isSuperAdmin,
        ])->layout('layouts.app', ['title' => 'Filiais']);
    }
}
