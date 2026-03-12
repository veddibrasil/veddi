<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

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
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
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

    public function isCompanyAdmin(Company $company): bool
    {
        return $this->isSuperAdmin() || $this->roleForCompany($company) === 'company_admin';
    }

    public function isBranchManager(Company $company): bool
    {
        return $this->roleForCompany($company) === 'branch_manager';
    }

    public function overridePermissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
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

        $override = UserPermission::whereHas('permission', fn ($q) => $q->where('name', $permission))
            ->where('user_id', $this->id)
            ->where('company_id', $company->id)
            ->first();

        if ($override !== null) {
            return $override->granted;
        }

        $role = Role::where('slug', $roleSlug)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->first();

        if (! $role) {
            return false;
        }

        return $role->permissions()->where('name', $permission)->exists();
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
