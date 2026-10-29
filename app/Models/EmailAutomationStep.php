<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAutomationStep extends Model
{
    protected $fillable = [
        'automation_id',
        'position',
        'delay_hours',
        'campaign_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay_hours' => 'integer',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(EmailAutomation::class, 'automation_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }
}
