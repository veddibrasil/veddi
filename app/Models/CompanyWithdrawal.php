<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyWithdrawal extends Model
{
    protected $fillable = [
        'company_id',
        'amount',
        'pix_fee',
        'status',
        'payout_type',
        'pix_key',
        'pix_key_type',
        'bank_code',
        'bank_agency',
        'bank_account',
        'bank_account_digit',
        'bank_account_type',
        'bank_owner_cpf_cnpj',
        'bank_owner_name',
        'asaas_transfer_id',
        'asaas_response',
        'processed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'pix_fee'      => 'decimal:2',
        'asaas_response' => 'array',
        'processed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
