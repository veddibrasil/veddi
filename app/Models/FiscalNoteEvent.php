<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalNoteEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array'];

    public function fiscalConfig(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CompanyFiscalConfig::class, 'fiscal_config_id');
    }

    public function note(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FiscalNote::class, 'fiscal_note_id');
    }
}
