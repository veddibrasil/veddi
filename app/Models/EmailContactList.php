<?php

namespace App\Models;

use App\DTOs\AudienceFilterData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lista de contatos externos (possíveis clientes), com a origem declarada ao criar. */
class EmailContactList extends Model
{
    protected $fillable = [
        'name',
        'source',
        'consent_confirmed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'consent_confirmed_at' => 'datetime',
        ];
    }

    public function filterData(): AudienceFilterData
    {
        return new AudienceFilterData(contactLists: [$this->id]);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmailContact::class, 'list_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
