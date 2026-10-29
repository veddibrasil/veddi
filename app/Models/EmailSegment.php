<?php

namespace App\Models;

use App\DTOs\AudienceFilterData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Filtro de público salvo (validado pelo AudienceFilterData). */
class EmailSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'filters',
        'last_count',
        'last_counted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'last_count' => 'integer',
            'last_counted_at' => 'datetime',
        ];
    }

    public function filterData(): AudienceFilterData
    {
        return AudienceFilterData::fromArray($this->filters ?? []);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class, 'segment_id');
    }
}
