<?php

namespace App\Models;

use App\Enums\EmailSuppressionReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lista global: quem está aqui nunca recebe e-mail marketing (consultada antes de cada envio). */
class EmailSuppression extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'reason',
        'source_campaign_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reason' => EmailSuppressionReason::class,
        ];
    }

    public function sourceCampaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'source_campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
