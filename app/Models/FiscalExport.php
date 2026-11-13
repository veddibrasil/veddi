<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalExport extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['note_ids' => 'array'];

    public function directory(): string
    {
        return 'fiscal-exports/'.$this->company_id.'/'.$this->id;
    }

    public function filename(): string
    {
        return 'notas-fiscais-'.$this->month.'-'.$this->environment.'-'.$this->id.'.zip';
    }
}
