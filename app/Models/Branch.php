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

    protected $fillable = ['company_id', 'name', 'address', 'city', 'phone', 'active', 'opens_at', 'closes_at', 'available_days'];

    protected $casts = [
        'active' => 'boolean',
        'available_days' => 'array',
    ];

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
        $now = now(config('app.timezone'));
        $currentDay = (int) $now->format('w');
        $currentTime = $now->format('H:i');

        $days = $this->available_days;
        if ($days !== null && ! in_array($currentDay, $days)) {
            return false;
        }

        return $currentTime >= $this->opens_at && $currentTime <= $this->closes_at;
    }
}
