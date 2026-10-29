<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Layout reutilizável de e-mail marketing (módulo da plataforma, sem company_id).
 * `html` é gerado a partir dos blocos e sanitizado pelo TemplateRenderer.
 */
class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subject_default',
        'preheader_default',
        'blocks',
        'html',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class, 'template_id');
    }
}
