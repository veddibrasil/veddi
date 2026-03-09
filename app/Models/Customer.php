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
        return static::where('phone', $normalized)->first();
    }
}
