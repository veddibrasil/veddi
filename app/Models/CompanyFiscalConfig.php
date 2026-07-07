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
        'crt',
        'provider',
        'provider_token',
        'environment',
        'nfce_serie',
        'certificate_path',
        'certificate_password',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'crt' => 'integer',
        'nfce_serie' => 'integer',
        'provider_token' => 'encrypted',
        'certificate_password' => 'encrypted',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
