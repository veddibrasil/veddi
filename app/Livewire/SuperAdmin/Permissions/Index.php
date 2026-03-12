<?php

namespace App\Livewire\SuperAdmin\Permissions;

use App\Models\Permission;
use App\Models\Role;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public array $rolePermissions = [];

    public function mount(): void
    {
        $this->loadRolePermissions();
    }

    private function loadRolePermissions(): void
    {
        $roles = Role::whereNull('company_id')->get();

        foreach ($roles as $role) {
            $this->rolePermissions[$role->slug] = $role->permissions()->pluck('name')->toArray();
        }
    }

    public function toggle(string $roleSlug, string $permissionName): void
    {
        $role = Role::where('slug', $roleSlug)->whereNull('company_id')->firstOrFail();
        $permission = Permission::where('name', $permissionName)->firstOrFail();

        if ($role->permissions()->where('name', $permissionName)->exists()) {
            $role->permissions()->detach($permission->id);
            $this->rolePermissions[$roleSlug] = array_values(
                array_filter($this->rolePermissions[$roleSlug] ?? [], fn ($p) => $p !== $permissionName)
            );
        } else {
            $role->permissions()->attach($permission->id);
            $this->rolePermissions[$roleSlug][] = $permissionName;
        }

        session()->flash('status', 'Permissão atualizada.');
    }

    public function render()
    {
        $permissions = Permission::orderBy('group')->orderBy('label')->get()->groupBy('group');
        $systemRoles = Role::whereNull('company_id')->get();

        return view('livewire.super-admin.permissions.index', [
            'permissions' => $permissions,
            'systemRoles' => $systemRoles,
        ]);
    }
}
