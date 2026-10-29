<?php

namespace App\Models;

use App\Enums\EmailAutomationTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sequência de e-mails disparada por gatilho (cadastro abandonado, empresa sem pedidos). */
class EmailAutomation extends Model
{
    protected $fillable = [
        'name',
        'trigger',
        'trigger_settings',
        'active',
        'from_name',
        'from_email',
        'reply_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => EmailAutomationTrigger::class,
            'trigger_settings' => 'array',
            'active' => 'boolean',
        ];
    }

    /** Horas (cadastro abandonado) ou dias (sem pedidos) para entrar na sequência. */
    public function threshold(): int
    {
        return (int) ($this->trigger_settings['threshold'] ?? $this->trigger->defaultThreshold());
    }

    public function steps(): HasMany
    {
        return $this->hasMany(EmailAutomationStep::class, 'automation_id')->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(EmailAutomationEnrollment::class, 'automation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
