<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WhatsAppMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    protected $model = WhatsAppMessage::class;

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::create([
                'name' => 'Empresa '.fake()->unique()->company(),
                'slug' => 'empresa-'.Str::lower(Str::random(10)),
                'order_prefix' => 'TST',
                'active' => true,
                'status' => 'ACTIVE',
            ])->id,
            'whatsapp_connection_id' => null,
            'order_id' => null,
            'event' => 'preparing',
            'template' => 'pedido_em_preparo',
            'to_phone' => '5511999990001',
            'wamid' => null,
            'status' => WhatsAppMessage::STATUS_QUEUED,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'wamid' => 'wamid.'.Str::random(24),
            'status' => WhatsAppMessage::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function failed(string $code = '131026', string $message = 'Message undeliverable'): static
    {
        return $this->state([
            'status' => WhatsAppMessage::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message,
        ]);
    }
}
