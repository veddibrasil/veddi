<?php

namespace App\Models;

use App\Enums\EmailEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrega, abertura, clique, bounce, spam e descadastro (limpos após 12 meses). */
class EmailEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'recipient_id',
        'campaign_id',
        'type',
        'url',
        'user_agent',
        'ip_hash',
        'provider_event_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => EmailEventType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EmailCampaignRecipient::class, 'recipient_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }
}
