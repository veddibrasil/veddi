<?php

namespace App\Livewire\Admin\Users;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Permissions extends Component
{
    public User $user;

    public array $overrides = [];

    public function mount(User $user): void
    {
        $this->user = $user;
        $this->loadOverrides();
    }

    private function loadOverrides(): void
    {
        $company = app('current.company');

        $userPerms = UserPermission::where('user_id', $this->user->id)
            ->where('company_id', $company->id)
            ->with('permission')
            ->get()
            ->keyBy(fn ($up) => $up->permission->name);

        foreach ($userPerms as $permName => $up) {
            $this->overrides[$permName] = $up->granted ? 'grant' : 'deny';
        }
    }

    public function save(): void
    {
        $company = app('current.company');

        foreach ($this->overrides as $permName => $value) {
            $permission = Permission::where('name', $permName)->first();
            if (! $permission) {
                continue;
            }

            if ($value === 'default') {
                UserPermission::where('user_id', $this->user->id)
                    ->where('company_id', $company->id)
                    ->where('permission_id', $permission->id)
                    ->delete();
            } else {
                UserPermission::updateOrCreate(
                    [
                        'user_id' => $this->user->id,
                        'company_id' => $company->id,
                        'permission_id' => $permission->id,
                    ],
                    ['granted' => $value === 'grant']
                );
            }
        }

        Cache::forget("user:{$this->user->id}:permissions:company:{$company->id}");

        Log::channel('audit')->info('Permissões de usuário atualizadas', [
            'admin_id' => auth()->id(),
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'overrides' => $this->overrides,
        ]);

        session()->flash('status', 'Permissões atualizadas com sucesso.');
    }

    public function render()
    {
        $company = app('current.company');
        $permissions = Permission::orderBy('group')->orderBy('label')->get()->groupBy('group');

        $roleSlug = $this->user->roleForCompany($company);
        $roleDefaults = [];

        if ($roleSlug) {
            $role = Role::where('slug', $roleSlug)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
                ->first();

            if ($role) {
                $roleDefaults = $role->permissions()->pluck('name')->toArray();
            }
        }

        return view('livewire.admin.users.permissions', [
            'permissions' => $permissions,
            'roleDefaults' => $roleDefaults,
        ]);
    }
}
