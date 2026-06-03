<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFiscalConfig extends Model
{
    protected $fillable = [
        'company_id',
        'enabled',
        'inscricao_estadual',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
