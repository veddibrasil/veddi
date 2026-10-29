<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contato externo de uma lista: e-mail normalizado, nome e empresa opcionais. */
class EmailContact extends Model
{
    protected $fillable = [
        'list_id',
        'email',
        'name',
        'company_name',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailContactList::class, 'list_id');
    }
}
