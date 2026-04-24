<?php

namespace App\Livewire\SuperAdmin\Permissions;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class UserPermissions extends Component
{
    public User $user;

    public int $companyId = 0;

    public array $overrides = [];

    public function mount(User $user): void
    {
        $this->user = $user;
        $firstCompany = $user->companies()->first();
        if ($firstCompany) {
            $this->companyId = $firstCompany->id;
            $this->loadOverrides();
        }
    }

    public function updatedCompanyId(): void
    {
        $this->loadOverrides();
    }

    private function loadOverrides(): void
    {
        $this->overrides = [];
        if (! $this->companyId) {
            return;
        }

        $userPerms = UserPermission::where('user_id', $this->user->id)
            ->where('company_id', $this->companyId)
            ->with('permission')
            ->get()
            ->keyBy(fn ($up) => $up->permission->name);

        foreach ($userPerms as $permName => $up) {
            $this->overrides[$permName] = $up->granted ? 'grant' : 'deny';
        }
    }

    public function save(): void
    {
        if (! $this->companyId) {
            return;
        }

        foreach ($this->overrides as $permName => $value) {
            $permission = Permission::where('name', $permName)->first();
            if (! $permission) {
                continue;
            }

            if ($value === 'default') {
                UserPermission::where('user_id', $this->user->id)
                    ->where('company_id', $this->companyId)
                    ->where('permission_id', $permission->id)
                    ->delete();
            } else {
                UserPermission::updateOrCreate(
                    [
                        'user_id' => $this->user->id,
                        'company_id' => $this->companyId,
                        'permission_id' => $permission->id,
                    ],
                    ['granted' => $value === 'grant']
                );
            }
        }

        session()->flash('status', 'Permissões do usuário atualizadas.');
    }

    public function render()
    {
        $companies = $this->user->companies;
        $permissions = Permission::orderBy('group')->orderBy('label')->get()->groupBy('group');

        $roleDefaults = [];
        if ($this->companyId) {
            $company = Company::find($this->companyId);
            $roleSlug = $this->user->roleForCompany($company);
            if ($roleSlug) {
                $role = Role::where('slug', $roleSlug)
                    ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->companyId))
                    ->first();
                if ($role) {
                    $roleDefaults = $role->permissions()->pluck('name')->toArray();
                }
            }
        }

        return view('livewire.super-admin.permissions.user-permissions', [
            'companies' => $companies,
            'permissions' => $permissions,
            'roleDefaults' => $roleDefaults,
        ]);
    }
}
