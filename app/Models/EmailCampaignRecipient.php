<?php

namespace App\Models;

use App\Enums\EmailRecipientStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Destinatário de uma campanha. As variáveis ficam congeladas no momento do disparo
 * (nome, empresa, plano...), e unique(campaign_id, email) garante um envio por pessoa.
 * Sem CompanyScope de propósito: o módulo é da plataforma e cruza empresas.
 */
class EmailCampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'user_id',
        'company_id',
        'email',
        'name',
        'variables',
        'variant',
        'status',
        'attempts',
        'provider_message_id',
        'queued_at',
        'sent_at',
        'delivered_at',
        'first_opened_at',
        'first_clicked_at',
        'bounced_at',
        'complained_at',
        'unsubscribed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailRecipientStatus::class,
            'variables' => 'array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withoutGlobalScopes();
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class, 'recipient_id');
    }
}
