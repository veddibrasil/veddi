<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portal extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'branch_id',
        'channel',
        'external_merchant_id',
        'credentials',
        'status',
        'active_interruption_id',
        'paused_until',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'paused_until' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function productMappings(): HasMany
    {
        return $this->hasMany(ProductPortalMapping::class);
    }
}
