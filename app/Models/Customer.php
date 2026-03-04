<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'address', 'neighborhood', 'city', 'cep'];

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
