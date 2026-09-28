<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppSetting extends Model
{
    /**
     * Evento de notificação → coluna do toggle. 'refunded' compartilha o toggle de
     * cancelamento; 'awaiting_payment' ainda não tem template (pendência).
     */
    public const EVENT_FIELDS = [
        'new_order' => 'notify_on_new_order',
        'awaiting_payment' => 'notify_on_awaiting_payment',
        'paid' => 'notify_on_paid',
        'scheduled' => 'notify_on_scheduled',
        'preparing' => 'notify_on_preparing',
        'ready' => 'notify_on_ready',
        'out_for_delivery' => 'notify_on_out_for_delivery',
        'delivered' => 'notify_on_delivered',
        'cancelled' => 'notify_on_cancelled',
        'refunded' => 'notify_on_cancelled',
    ];

    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'company_id',
        'enabled',
        'notify_on_new_order',
        'notify_on_awaiting_payment',
        'notify_on_paid',
        'notify_on_scheduled',
        'notify_on_preparing',
        'notify_on_ready',
        'notify_on_out_for_delivery',
        'notify_on_delivered',
        'notify_on_cancelled',
        'notify_on_admin_message',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'notify_on_new_order' => 'boolean',
        'notify_on_awaiting_payment' => 'boolean',
        'notify_on_paid' => 'boolean',
        'notify_on_scheduled' => 'boolean',
        'notify_on_preparing' => 'boolean',
        'notify_on_ready' => 'boolean',
        'notify_on_out_for_delivery' => 'boolean',
        'notify_on_delivered' => 'boolean',
        'notify_on_cancelled' => 'boolean',
        'notify_on_admin_message' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isEventEnabled(string $event): bool
    {
        $field = self::EVENT_FIELDS[$event] ?? null;

        return $this->enabled && $field !== null && (bool) $this->{$field};
    }
}
