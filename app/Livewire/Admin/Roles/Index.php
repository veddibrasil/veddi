<?php

namespace App\Livewire\Admin\Roles;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserPermissionService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $name = '';
    public array $selectedPermissions = [];

    public ?int $editingId = null;
    public ?int $deletingId = null;

    public ?int $assignRoleId = null;
    public string $assignUserEmail = '';
    public string $assignUserRole = '';

    public bool $canManage = false;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->canManage = true;
        } elseif (app()->bound('current.company')) {
            $this->canManage = $user->hasPermission('roles.manage', app('current.company'));
        }
    }

    public function save(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'name' => 'required|string|max:100',
        ], [
            'name.required' => 'O nome do tipo de usuário é obrigatório.',
        ]);

        $company = app('current.company');
        $slug    = \Illuminate\Support\Str::slug($this->name, '_');

        if ($this->editingId) {
            $role = Role::where('id', $this->editingId)
                ->where('company_id', $company->id)
                ->firstOrFail();
            $role->update(['name' => $this->name]);
        } else {
            $exists = Role::where('slug', $slug)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
                ->exists();

            if ($exists) {
                $this->addError('name', 'Já existe um tipo de usuário com este nome.');
                return;
            }

            $role = Role::create([
                'name'       => $this->name,
                'slug'       => $slug,
                'company_id' => $company->id,
                'is_system'  => false,
            ]);
        }

        $permissionIds = Permission::whereIn('name', $this->selectedPermissions)->pluck('id');
        $role->permissions()->sync($permissionIds);

        $this->reset(['name', 'selectedPermissions', 'editingId']);
        session()->flash('status', 'Tipo de usuário salvo com sucesso.');
    }

    public function edit(int $id): void
    {
        abort_unless($this->canManage, 403);

        $company = app('current.company');
        $role    = Role::where('id', $id)->where('company_id', $company->id)->firstOrFail();

        $this->editingId           = $role->id;
        $this->name                = $role->name;
        $this->selectedPermissions = $role->permissions()->pluck('name')->toArray();
    }

    public function cancelEdit(): void
    {
        $this->reset(['name', 'selectedPermissions', 'editingId']);
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
        abort_unless($this->canManage, 403);

        if (! $this->deletingId) return;

        $company = app('current.company');
        $role    = Role::where('id', $this->deletingId)->where('company_id', $company->id)->firstOrFail();
        $role->delete();

        $this->deletingId = null;
        session()->flash('status', 'Tipo de usuário removido.');
    }

    public function openAssign(int $roleId): void
    {
        $this->assignRoleId    = $roleId;
        $this->assignUserEmail = '';
    }

    public function assignUser(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'assignUserEmail' => 'required|email|exists:users,email',
        ], [
            'assignUserEmail.required' => 'Informe o e-mail do usuário.',
            'assignUserEmail.exists'   => 'Usuário não encontrado.',
        ]);

        $company = app('current.company');
        $role    = Role::findOrFail($this->assignRoleId);
        $user    = User::where('email', $this->assignUserEmail)->firstOrFail();

        $pivot = $user->companies()->where('companies.id', $company->id)->first();

        if (! $pivot) {
            $user->companies()->attach($company->id, ['role' => $role->slug]);
        } else {
            $user->companies()->updateExistingPivot($company->id, ['role' => $role->slug]);
        }

        UserPermission::where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->delete();

        UserPermissionService::assignRolePermissions($user, $company, $role->slug);

        $this->reset(['assignRoleId', 'assignUserEmail']);
        session()->flash('status', 'Usuário atribuído ao tipo com sucesso.');
    }

    public function cancelAssign(): void
    {
        $this->reset(['assignRoleId', 'assignUserEmail']);
    }

    public function render()
    {
        $company     = app('current.company');
        $systemRoles = Role::whereNull('company_id')->orderBy('name')->get();
        $customRoles = Role::where('company_id', $company->id)->orderBy('name')->with('permissions')->get();
        $permissions = Permission::orderBy('group')->orderBy('label')->get()->groupBy('group');

        return view('livewire.admin.roles.index', [
            'systemRoles' => $systemRoles,
            'customRoles' => $customRoles,
            'permissions' => $permissions,
        ]);
    }
}
