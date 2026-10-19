<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalNoteEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array'];
}
