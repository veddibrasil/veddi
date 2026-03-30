<?php

namespace App\Livewire\Admin\Branches;

use App\Enums\Plan;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Scopes\CompanyScope;
use Livewire\Component;

class Index extends Component
{
    public ?int $deletingId = null;
    public bool $canCreate            = false;
    public bool $canUpdate            = false;
    public bool $canDelete            = false;
    public bool $canView              = false;
    public bool $canCreateMoreBranches = false;
    public bool $deliveryEnabled      = false;
    public int  $branchCount          = 0;
    public int  $branchLimit          = 1;

    public function mount(): void
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            $this->canCreate = $this->canUpdate = $this->canDelete = true;
            $this->canCreateMoreBranches = true;
            $this->deliveryEnabled       = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->authorize('viewAny', Coupon::class);
            $this->canCreate = $user->hasPermission('branches.create', $company);
            $this->canUpdate = $user->hasPermission('branches.update', $company);
            $this->canDelete = $user->hasPermission('branches.delete', $company);

            $this->branchLimit          = $company->maxBranches();
            $this->branchCount          = $company->branches()->count();
            $this->canCreateMoreBranches = $this->canCreate && ($this->branchCount < $this->branchLimit);
            $this->deliveryEnabled      = $company->plan === Plan::Pro;
        }
    }

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
