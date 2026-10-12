<?php

namespace Database\Factories;

use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppTemplate>
 */
class WhatsAppTemplateFactory extends Factory
{
    protected $model = WhatsAppTemplate::class;

    public function definition(): array
    {
        return [
            'whatsapp_connection_id' => WhatsAppConnection::factory(),
            'event' => 'preparing',
            'name' => 'pedido_em_preparo',
            'language' => 'pt_BR',
            'meta_template_id' => (string) fake()->numerify('###############'),
            'status' => WhatsAppTemplate::STATUS_APPROVED,
            'rejection_reason' => null,
        ];
    }

    /** Template do config/whatsapp_templates.php para o evento (ex.: ready_pickup). */
    public function forEvent(string $event): static
    {
        return $this->state([
            'event' => $event,
            'name' => config("whatsapp_templates.templates.{$event}.name"),
            'language' => config('whatsapp_templates.language'),
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
