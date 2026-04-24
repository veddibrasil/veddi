<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppSetting extends Model
{
    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'company_id',
        'enabled',
        'notify_on_new_order',
        'notify_on_awaiting_payment',
        'notify_on_paid',
        'notify_on_preparing',
        'notify_on_ready',
        'notify_on_delivered',
        'notify_on_cancelled',
        'notify_on_admin_message',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'notify_on_new_order' => 'boolean',
        'notify_on_awaiting_payment' => 'boolean',
        'notify_on_paid' => 'boolean',
        'notify_on_preparing' => 'boolean',
        'notify_on_ready' => 'boolean',
        'notify_on_delivered' => 'boolean',
        'notify_on_cancelled' => 'boolean',
        'notify_on_admin_message' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function hasGlobalCredentials(): bool
    {
        return filled(config('services.zapi.instance_id'))
            && filled(config('services.zapi.token'))
            && filled(config('services.zapi.client_token'));
    }
}
