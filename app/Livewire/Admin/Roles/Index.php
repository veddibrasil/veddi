<?php

namespace App\Livewire\Admin\Roles;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Company\UserPermissionService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    #[Locked]
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
        $slug = \Illuminate\Support\Str::slug($this->name, '_');

        if ($slug === '') {
            $this->addError('name', 'Use letras ou números no nome do tipo.');

            return;
        }

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
                'name' => $this->name,
                'slug' => $slug,
                'company_id' => $company->id,
                'is_system' => false,
            ]);
        }

        // A tela desabilita, mas a requisição pode trazer: permissão de tela só do admin não entra.
        $permissionIds = Permission::whereIn('name', $this->selectedPermissions)
            ->whereNotIn('name', Role::ADMIN_ONLY_PERMISSIONS)
            ->pluck('id')
            ->all();

        $members = UserPermissionService::syncRolePermissions($role, $permissionIds);

        $this->reset(['name', 'selectedPermissions', 'editingId']);
        session()->flash('status', $members > 0
            ? "Tipo de usuário salvo. Acesso atualizado para {$members} ".($members === 1 ? 'usuário' : 'usuários').' com este tipo.'
            : 'Tipo de usuário salvo com sucesso.');
    }

    public function edit(int $id): void
    {
        abort_unless($this->canManage, 403);

        $company = app('current.company');
        $role = Role::where('id', $id)->where('company_id', $company->id)->firstOrFail();

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->selectedPermissions = $role->permissions()
            ->whereNotIn('name', Role::ADMIN_ONLY_PERMISSIONS)
            ->pluck('name')
            ->toArray();
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

        if (! $this->deletingId) {
            return;
        }

        $company = app('current.company');
        $role = Role::where('id', $this->deletingId)->where('company_id', $company->id)->firstOrFail();
        $this->deletingId = null;

        // Quem está no tipo guarda cópia das permissões (user_permissions) e seguiria usando o
        // PDV; e um tipo novo com o mesmo nome devolveria o acesso a eles sem ninguém ver.
        $members = $role->memberships()->count();
        if ($members > 0) {
            session()->flash('error', "Não é possível remover: {$members} ".($members === 1 ? 'usuário usa' : 'usuários usam')
                .' este tipo. Troque o tipo deles em Usuários antes de remover.');

            return;
        }

        $role->delete();

        session()->flash('status', 'Tipo de usuário removido.');
    }

    public function openAssign(int $roleId): void
    {
        $this->assignRoleId = $roleId;
        $this->assignUserEmail = '';
    }

    public function assignUser(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'assignUserEmail' => 'required|email',
        ], [
            'assignUserEmail.required' => 'Informe o e-mail do usuário.',
        ]);

        $company = app('current.company');
        $role = Role::where('id', $this->assignRoleId)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->firstOrFail();

        // Mesma regra de Users/Index::linkUser: sem seletor de empresa, puxar alguém de outra
        // empresa (ou o super admin) trocava o painel dele; mensagem única evita enumerar e-mails.
        $user = User::where('email', $this->assignUserEmail)
            ->where('is_super_admin', false)
            ->whereDoesntHave('companies', fn ($q) => $q->where('companies.id', '!=', $company->id))
            ->first();

        if (! $user) {
            $this->addError('assignUserEmail', 'Não é possível atribuir este e-mail. Use "Novo usuário" em Usuários para criar o acesso.');

            return;
        }

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
        $company = app('current.company');
        $systemRoles = Role::whereNull('company_id')->orderBy('name')->get();
        $customRoles = Role::where('company_id', $company->id)->orderBy('name')->with('permissions')->get();
        $permissions = Permission::orderBy('group')->orderBy('label')->get()->groupBy('group');

        $memberCounts = DB::table('company_user')
            ->where('company_id', $company->id)
            ->whereIn('role', $customRoles->pluck('slug'))
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return view('livewire.admin.roles.index', [
            'systemRoles' => $systemRoles,
            'customRoles' => $customRoles,
            'permissions' => $permissions,
            'adminOnlyPermissions' => Role::ADMIN_ONLY_PERMISSIONS,
            'groupLabels' => [
                'branches' => 'Filiais',
                'categories' => 'Categorias',
                'company' => 'Empresa',
                'coupons' => 'Cupons',
                'fiscal' => 'Fiscal',
                'orders' => 'Pedidos',
                'pdv' => 'PDV',
                'products' => 'Produtos',
                'roles' => 'Tipos de usuário',
                'stock' => 'Estoque',
                'users' => 'Usuários',
            ],
            'memberCounts' => $memberCounts,
        ]);
    }
}
