<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pessoa dentro de uma automação: em que passo está e quando sai o próximo e-mail. */
class EmailAutomationEnrollment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    // Saiu antes do fim: a condição do gatilho deixou de valer (empresa ativou ou voltou a vender).
    public const STATUS_EXITED = 'exited';

    protected $fillable = [
        'automation_id',
        'user_id',
        'company_id',
        'email',
        'next_position',
        'status',
        'next_run_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'next_position' => 'integer',
            'next_run_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(EmailAutomation::class, 'automation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withoutGlobalScopes();
    }
}
