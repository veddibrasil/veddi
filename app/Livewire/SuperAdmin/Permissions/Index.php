<?php

namespace App\Livewire\SuperAdmin\Permissions;

use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Company\UserPermissionService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use RequiresSuperAdmin;

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
        $current = $role->permissions()->pluck('permissions.id')->all();

        // Via serviço: quem já tem o papel (em todas as empresas) recebe a mudança na hora.
        if (in_array($permission->id, $current)) {
            UserPermissionService::syncRolePermissions($role, array_values(array_diff($current, [$permission->id])));
            $this->rolePermissions[$roleSlug] = array_values(
                array_filter($this->rolePermissions[$roleSlug] ?? [], fn ($p) => $p !== $permissionName)
            );
        } else {
            UserPermissionService::syncRolePermissions($role, [...$current, $permission->id]);
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
