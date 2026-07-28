<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyNotification extends Model
{
    protected $fillable = [
        'company_id',
        'type',
        'is_delivery',
        'is_kitchen',
        'is_bar',
        'title',
        'subtitle',
        'link',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'is_delivery' => 'boolean',
        'is_kitchen' => 'boolean',
        'is_bar' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
