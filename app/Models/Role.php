<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class Role extends Model
{
    /**
     * Permissões das telas que só o administrador da empresa abre (rotas com
     * company.role:company_admin: Usuários, Tipos de Usuário, Empresa, Assinatura, Carteira).
     * Tipo criado pela empresa não recebe: o menu mostraria a tela e a rota devolveria 403,
     * e liberar a rota daria ao tipo como se promover a administrador.
     */
    public const ADMIN_ONLY_PERMISSIONS = ['users.view', 'users.manage', 'roles.manage', 'company.settings'];

    protected $fillable = ['name', 'slug', 'company_id', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function scopeForCompany($query, Company $company)
    {
        return $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id)
        );
    }

    /**
     * Vínculos usuário-empresa com este papel. Papel da empresa só vale nela; papel do
     * sistema vale em todas, então volta um par por empresa.
     *
     * @return \Illuminate\Support\Collection<int, object{user_id: int, company_id: int}>
     */
    public function memberships(): \Illuminate\Support\Collection
    {
        return DB::table('company_user')
            ->where('role', $this->slug)
            ->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))
            ->get(['user_id', 'company_id']);
    }
}
