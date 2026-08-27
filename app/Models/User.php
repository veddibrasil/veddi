<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot(['role', 'branch_id'])
            ->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function roleForCompany(Company $company): ?string
    {
        $pivot = $this->companies()->where('companies.id', $company->id)->first()?->pivot;

        return $pivot?->role;
    }

    public function branchIdForCompany(Company $company): ?int
    {
        $pivot = $this->companies()->where('companies.id', $company->id)->first()?->pivot;

        return $pivot?->branch_id;
    }

    public function isBranchManager(Company $company): bool
    {
        return $this->roleForCompany($company) === 'branch_manager';
    }

    public function isCompanyAdmin(Company $company): bool
    {
        return $this->roleForCompany($company) === 'company_admin';
    }

    /** Totais e relatórios de fechamento (financeiro) só pra administrador da empresa. */
    public function canManageClosing(Company $company): bool
    {
        return $this->isSuperAdmin() || $this->isCompanyAdmin($company);
    }

    public function isBranchScoped(Company $company): bool
    {
        return in_array($this->roleForCompany($company), ['branch_manager', 'cozinha', 'caixa', 'bar', 'entrega', 'garcom']);
    }

    /**
     * Nome da rota pra onde o usuário deve ser levado após login/acesso à raiz.
     * Papéis operacionais sem dashboard (garcom, entrega, cozinha, bar) vão direto
     * pra própria tela de trabalho; os demais caem no dashboard.
     */
    public function homeRouteName(): string
    {
        if ($this->isSuperAdmin()) {
            return 'superadmin.dashboard';
        }

        $company = $this->companies()->orderBy('id')->first();
        $role = $company ? $this->roleForCompany($company) : null;

        return match ($role) {
            'garcom' => 'admin.pdv.tabs',
            'caixa' => 'admin.pdv',
            'entrega', 'cozinha', 'bar' => 'admin.orders.index',
            default => 'admin.dashboard',
        };
    }

    public function hasPermission(string $permission, Company $company): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $roleSlug = $this->roleForCompany($company);
        if (! $roleSlug) {
            return false;
        }

        $cacheKey = "user:{$this->id}:permissions:company:{$company->id}";
        $userId = $this->id;
        $companyId = $company->id;

        // self::clearPermissionCache($userId, $companyId);
        $perms = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($userId, $companyId, $roleSlug) {
            $overrides = UserPermission::where('user_id', $userId)
                ->where('company_id', $companyId)
                ->with('permission')
                ->get()
                ->filter(fn ($up) => $up->permission !== null)
                ->mapWithKeys(fn ($up) => [$up->permission->name => (bool) $up->granted])
                ->toArray();

            $role = Role::where('slug', $roleSlug)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->with('permissions')
                ->first();

            $rolePermissions = $role ? $role->permissions->pluck('name')->toArray() : [];

            return ['overrides' => $overrides, 'role' => $rolePermissions];
        });

        if (array_key_exists($permission, $perms['overrides'])) {
            return $perms['overrides'][$permission];
        }

        return in_array($permission, $perms['role']);
    }

    public static function clearPermissionCache(int $userId, int $companyId): void
    {
        Cache::forget("user:{$userId}:permissions:company:{$companyId}");
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
