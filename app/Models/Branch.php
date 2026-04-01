<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Branch extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'address', 'city', 'phone', 'active', 'opens_at', 'closes_at'];

    protected $casts = ['active' => 'boolean'];

    public function getOpensAtAttribute($value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    public function getClosesAtAttribute($value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }
    

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot(['available', 'quantity', 'min_quantity', 'track_stock'])
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliverySetting(): HasOne
    {
        return $this->hasOne(DeliverySetting::class);
    }

    public function isOpen(): bool
    {
        $now = now(config('app.timezone'))->format('H:i');
        return $now >= $this->opens_at && $now <= $this->closes_at;
    }
}
