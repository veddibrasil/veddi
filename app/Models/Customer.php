<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'phone', 'email', 'tax_id', 'address', 'complement', 'neighborhood', 'city', 'cep'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public static function findByPhone(string $phone): ?self
    {
        $normalized = preg_replace('/\D/', '', $phone);

        // A CompanyScope (global scope do BelongsToCompany) já filtra por company_id quando
        // current.company está vinculado. O filtro explícito abaixo é uma camada adicional
        // de segurança para garantir isolamento mesmo se o scope for contornado.
        $query = static::where('phone', $normalized);

        if (app()->bound('current.company')) {
            $query->where('company_id', app('current.company')->id);
        }

        return $query->first();
    }

    public static function findByPhoneGlobally(string $phone): ?self
    {
        $normalized = preg_replace('/\D/', '', $phone);

        return static::withoutGlobalScopes()
            ->where('phone', $normalized)
            ->first();
    }
}
