<?php

namespace App\Livewire\Admin\Users;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Company\UserCreationService;
use App\Services\Company\UserDeletionService;
use App\Services\Company\UserPermissionService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    private const BRANCH_SCOPED_ROLES = ['branch_manager', 'cozinha', 'caixa', 'garcom'];

    private const WAITER_MODULE_MESSAGE = 'Módulo Garçom não habilitado. Adicione essa funcionalidade ao módulo PDV em Configurações > Faturamento antes de atribuir esse tipo de usuário.';

    public string $search = '';

    // Formulário de criação de usuário
    public string $newName = '';

    public string $newEmail = '';

    public string $newRole = '';

    public int $newBranchId = 0;

    public string $newPassword = '';

    public bool $showCreateForm = false;

    // Edição de role de um usuário existente
    public ?int $editUserId = null;

    public string $editRole = '';

    public int $editBranchId = 0;

    // Vincular usuário existente por e-mail
    public string $linkEmail = '';

    public string $linkRole = '';

    public int $linkBranchId = 0;

    public bool $showLinkForm = false;

    // Exclusão (desvincular)
    public ?int $removingUserId = null;

    public bool $canView = false;

    public bool $canManage = false;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->canView = $this->canManage = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canView = $user->hasPermission('users.view', $company);
            $this->canManage = $user->hasPermission('users.manage', $company);
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Papeis cuja permissao pdv.waiter_operate exige o modulo Garçom habilitado na empresa.
     */
    private function availableRoleSlugs(Company $company): array
    {
        return Role::forCompany($company)
            ->with('permissions')
            ->get()
            ->reject(fn (Role $role) => ! $company->waiter_module_enabled
                && $role->permissions->pluck('name')->contains('pdv.waiter_operate')
            )
            ->pluck('slug')
            ->all();
    }

    public function createUser(): void
    {
        abort_unless($this->canManage, 403);

        $company = app('current.company');

        $this->validate([
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|email|unique:users,email',
            'newRole' => ['required', 'string', Rule::in($this->availableRoleSlugs($company))],
            'newPassword' => 'required|string|min:8',
        ], [
            'newName.required' => 'Informe o nome.',
            'newEmail.required' => 'Informe o e-mail.',
            'newEmail.unique' => 'Este e-mail já está em uso.',
            'newRole.required' => 'Selecione o tipo de usuário.',
            'newRole.in' => self::WAITER_MODULE_MESSAGE,
            'newPassword.required' => 'Informe a senha.',
            'newPassword.min' => 'A senha deve ter no mínimo 8 caracteres.',
        ]);

        UserCreationService::create($company, $this->newName, $this->newEmail, $this->newRole, $this->newBranchId, $this->newPassword);

        $this->reset(['newName', 'newEmail', 'newRole', 'newBranchId', 'newPassword', 'showCreateForm']);
        session()->flash('status', 'Usuário criado e e-mail de boas-vindas enviado.');
    }

    public function linkUser(): void
    {
        abort_unless($this->canManage, 403);

        $company = app('current.company');

        $this->validate([
            'linkEmail' => 'required|email|exists:users,email',
            'linkRole' => ['required', 'string', Rule::in($this->availableRoleSlugs($company))],
        ], [
            'linkEmail.required' => 'Informe o e-mail.',
            'linkEmail.exists' => 'Usuário não encontrado.',
            'linkRole.required' => 'Selecione o tipo de usuário.',
            'linkRole.in' => self::WAITER_MODULE_MESSAGE,
        ]);

        $user = User::where('email', $this->linkEmail)->firstOrFail();

        $user->companies()->syncWithoutDetaching([
            $company->id => [
                'role' => $this->linkRole,
                'branch_id' => in_array($this->linkRole, self::BRANCH_SCOPED_ROLES) && $this->linkBranchId
                    ? $this->linkBranchId
                    : null,
            ],
        ]);

        UserPermissionService::assignRolePermissions($user, $company, $this->linkRole);

        $this->reset(['linkEmail', 'linkRole', 'linkBranchId', 'showLinkForm']);
        session()->flash('status', 'Usuário vinculado com sucesso.');
    }

    public function openEditRole(int $userId): void
    {
        $company = app('current.company');
        $user = User::findOrFail($userId);
        $pivot = $user->companies()->where('companies.id', $company->id)->first()?->pivot;

        $this->editUserId = $userId;
        $this->editRole = $pivot?->role ?? '';
        $this->editBranchId = $pivot?->branch_id ?? 0;
    }

    public function saveEditRole(): void
    {
        abort_unless($this->canManage, 403);

        $company = app('current.company');

        $this->validate([
            'editRole' => ['required', 'string', Rule::in($this->availableRoleSlugs($company))],
        ], [
            'editRole.required' => 'Selecione o tipo de usuário.',
            'editRole.in' => self::WAITER_MODULE_MESSAGE,
        ]);

        $user = User::findOrFail($this->editUserId);

        $user->companies()->updateExistingPivot($company->id, [
            'role' => $this->editRole,
            'branch_id' => in_array($this->editRole, self::BRANCH_SCOPED_ROLES) && $this->editBranchId
                ? $this->editBranchId
                : null,
        ]);

        UserPermission::where('user_id', $this->editUserId)
            ->where('company_id', $company->id)
            ->delete();

        UserPermissionService::assignRolePermissions($user, $company, $this->editRole);

        User::clearPermissionCache($this->editUserId, $company->id);

        $this->reset(['editUserId', 'editRole', 'editBranchId']);
        session()->flash('status', 'Tipo de usuário atualizado.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editUserId', 'editRole', 'editBranchId']);
    }

    public function confirmRemove(int $userId): void
    {
        $this->removingUserId = $userId;
    }

    public function cancelRemove(): void
    {
        $this->removingUserId = null;
    }

    public function removeUser(): void
    {
        abort_unless($this->canManage, 403);
        abort_if($this->removingUserId === auth()->id(), 403);

        $user = User::findOrFail($this->removingUserId);

        UserDeletionService::delete($user);

        $this->removingUserId = null;
        session()->flash('status', 'Usuário excluído da plataforma.');
    }

    public function render()
    {
        $company = app('current.company');

        $users = User::whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
            )
            ->with(['companies' => fn ($q) => $q->where('companies.id', $company->id)])
            ->orderBy('name')
            ->paginate(20);

        $roles = Role::forCompany($company)->with('permissions')->orderBy('is_system', 'desc')->orderBy('name')->get();
        $branches = Branch::orderBy('name')->get();

        return view('livewire.admin.users.index', [
            'users' => $users,
            'roles' => $roles,
            'branches' => $branches,
            'company' => $company,
        ]);
    }
}
