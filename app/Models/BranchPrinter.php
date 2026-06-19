<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchPrinter extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'ip_address',
        'port',
        'paper_width',
        'auto_print',
        'active',
    ];

    protected $casts = [
        'port' => 'integer',
        'paper_width' => 'integer',
        'auto_print' => 'boolean',
        'active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
