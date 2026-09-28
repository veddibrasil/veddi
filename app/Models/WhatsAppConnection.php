<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConnection extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_TEMPLATES_PENDING = 'templates_pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    public const TYPE_CLOUD_API = 'cloud_api';

    public const TYPE_COEXISTENCE = 'coexistence';

    protected $table = 'whatsapp_connections';

    protected $fillable = [
        'company_id',
        'waba_id',
        'phone_number_id',
        'display_phone_number',
        'verified_name',
        'access_token',
        'token_scopes',
        'registration_pin',
        'onboarding_type',
        'status',
        'quality_rating',
        'messaging_limit_tier',
        'last_error',
        'connected_at',
        'disconnected_at',
        'last_app_activity_at',
        'alerts_sent',
    ];

    /** Nunca serializar segredos (toArray/toJson/logs/Livewire). */
    protected $hidden = [
        'access_token',
        'registration_pin',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'registration_pin' => 'encrypted',
            'token_scopes' => 'array',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_app_activity_at' => 'datetime',
            'alerts_sent' => 'array',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_connection_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_connection_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isCoexistence(): bool
    {
        return $this->onboarding_type === self::TYPE_COEXISTENCE;
    }

    /**
     * Todos os templates de config/whatsapp_templates.php aprovados (no idioma configurado).
     * É o critério para a conexão sair de templates_pending e virar active.
     */
    public function hasAllTemplatesApproved(): bool
    {
        $approvedEvents = $this->templates()
            ->where('status', WhatsAppTemplate::STATUS_APPROVED)
            ->where('language', config('whatsapp_templates.language'))
            ->pluck('event')
            ->all();

        return array_diff(array_keys(config('whatsapp_templates.templates')), $approvedEvents) === [];
    }

    /**
     * Template aprovado para o evento (chave de config/whatsapp_templates.php,
     * ex.: ready_pickup), ou null se ainda não foi aprovado pela Meta.
     */
    public function approvedTemplate(string $event): ?WhatsAppTemplate
    {
        return $this->templates()
            ->where('event', $event)
            ->where('status', WhatsAppTemplate::STATUS_APPROVED)
            ->first();
    }
}
