<?php

namespace App\Livewire\SuperAdmin\Users;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search    = '';
    public bool   $showModal = false;

    public ?int $editUserId       = null;
    public int  $assignCompanyId  = 0;
    public string $assignRole     = 'company_admin';
    public int  $assignBranchId   = 0;

    public function openAssign(int $userId): void
    {
        $this->editUserId      = $userId;
        $this->assignCompanyId = 0;
        $this->assignRole      = 'company_admin';
        $this->assignBranchId  = 0;
        $this->showModal       = true;
    }

    public function saveAssign(): void
    {
        $this->validate([
            'assignCompanyId' => ['required', 'integer', 'exists:companies,id'],
            'assignRole'      => ['required', 'in:company_admin,branch_manager'],
            'assignBranchId'  => ['nullable', 'integer'],
        ]);

        $user = User::findOrFail($this->editUserId);
        $user->companies()->syncWithoutDetaching([
            $this->assignCompanyId => [
                'role'      => $this->assignRole,
                'branch_id' => $this->assignRole === 'branch_manager' && $this->assignBranchId
                    ? $this->assignBranchId
                    : null,
            ],
        ]);

        $this->showModal = false;
        session()->flash('status', 'Usuário vinculado à empresa.');
    }

    public function toggleSuperAdmin(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update(['is_super_admin' => ! $user->is_super_admin]);
    }

    public function render()
    {
        $users = User::when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
            ->orWhere('email', 'like', "%{$this->search}%"))
            ->with('companies')
            ->orderBy('name')
            ->paginate(20);

        $companies = Company::withoutGlobalScope(CompanyScope::class)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.super-admin.users.index', compact('users', 'companies'))
            ->layout('layouts.app', ['title' => 'Super Admin — Usuários']);
    }
}
