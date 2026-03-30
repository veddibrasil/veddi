<?php

namespace App\Livewire\Admin\Users;

use App\Mail\WelcomeUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserPermissionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    // Formulário de criação de usuário
    public string $newName     = '';
    public string $newEmail    = '';
    public string $newRole     = '';
    public int    $newBranchId = 0;
    public bool   $showCreateForm = false;

    // Edição de role de um usuário existente
    public ?int   $editUserId   = null;
    public string $editRole     = '';
    public int    $editBranchId = 0;

    // Vincular usuário existente por e-mail
    public string $linkEmail    = '';
    public string $linkRole     = '';
    public int    $linkBranchId = 0;
    public bool   $showLinkForm = false;

    // Exclusão (desvincular)
    public ?int $removingUserId = null;

    public bool $canView   = false;
    public bool $canManage = false;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->canView = $this->canManage = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canView   = $user->hasPermission('users.view', $company);
            $this->canManage = $user->hasPermission('users.manage', $company);
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function createUser(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'newName'  => 'required|string|max:255',
            'newEmail' => 'required|email|unique:users,email',
            'newRole'  => 'required|string',
        ], [
            'newName.required'  => 'Informe o nome.',
            'newEmail.required' => 'Informe o e-mail.',
            'newEmail.unique'   => 'Este e-mail já está em uso.',
            'newRole.required'  => 'Selecione o tipo de usuário.',
        ]);

        $company           = app('current.company');
        $temporaryPassword = Str::password(12);

        $user = User::create([
            'name'     => $this->newName,
            'email'    => $this->newEmail,
            'password' => Hash::make($temporaryPassword),
        ]);

        $user->companies()->attach($company->id, [
            'role'      => $this->newRole,
            'branch_id' => $this->newRole === 'branch_manager' && $this->newBranchId
                ? $this->newBranchId
                : null,
        ]);

        UserPermissionService::assignRolePermissions($user, $company, $this->newRole);

        Mail::to($user->email)->send(new WelcomeUser($user, $company, $temporaryPassword));

        $this->reset(['newName', 'newEmail', 'newRole', 'newBranchId', 'showCreateForm']);
        session()->flash('status', 'Usuário criado e e-mail de boas-vindas enviado.');
    }

    public function linkUser(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'linkEmail' => 'required|email|exists:users,email',
            'linkRole'  => 'required|string',
        ], [
            'linkEmail.required' => 'Informe o e-mail.',
            'linkEmail.exists'   => 'Usuário não encontrado.',
            'linkRole.required'  => 'Selecione o tipo de usuário.',
        ]);

        $company = app('current.company');
        $user    = User::where('email', $this->linkEmail)->firstOrFail();

        $user->companies()->syncWithoutDetaching([
            $company->id => [
                'role'      => $this->linkRole,
                'branch_id' => $this->linkRole === 'branch_manager' && $this->linkBranchId
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
        $user    = User::findOrFail($userId);
        $pivot   = $user->companies()->where('companies.id', $company->id)->first()?->pivot;

        $this->editUserId   = $userId;
        $this->editRole     = $pivot?->role ?? '';
        $this->editBranchId = $pivot?->branch_id ?? 0;
    }

    public function saveEditRole(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'editRole' => 'required|string',
        ], [
            'editRole.required' => 'Selecione o tipo de usuário.',
        ]);

        $company = app('current.company');
        $user    = User::findOrFail($this->editUserId);

        $user->companies()->updateExistingPivot($company->id, [
            'role'      => $this->editRole,
            'branch_id' => $this->editRole === 'branch_manager' && $this->editBranchId
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

        $company = app('current.company');
        $user    = User::findOrFail($this->removingUserId);
        User::clearPermissionCache($this->removingUserId, $company->id);

        $user->companies()->detach($company->id);

        $this->removingUserId = null;
        session()->flash('status', 'Usuário removido da empresa.');
    }

    public function render()
    {
        $company = app('current.company');

        $users = User::whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->when($this->search, fn ($q) =>
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('email', 'like', "%{$this->search}%")
            )
            ->with(['companies' => fn ($q) => $q->where('companies.id', $company->id)])
            ->orderBy('name')
            ->paginate(20);

        $roles    = Role::forCompany($company)->orderBy('is_system', 'desc')->orderBy('name')->get();
        $branches = Branch::orderBy('name')->get();

        return view('livewire.admin.users.index', [
            'users'    => $users,
            'roles'    => $roles,
            'branches' => $branches,
        ]);
    }
}
