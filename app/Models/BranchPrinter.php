<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchPrinter extends Model
{
    public const STATIONS = ['geral', 'cozinha', 'bar', 'entrega'];

    public const CONNECTION_TYPES = ['network', 'usb'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'station',
        'connection_type',
        'ip_address',
        'port',
        'printer_name',
        'paper_width',
        'auto_print',
        'print_fiscal_note',
        'active',
    ];

    protected $casts = [
        'port' => 'integer',
        'paper_width' => 'integer',
        'auto_print' => 'boolean',
        'print_fiscal_note' => 'boolean',
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
