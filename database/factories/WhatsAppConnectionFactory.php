<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WhatsAppConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppConnection>
 */
class WhatsAppConnectionFactory extends Factory
{
    protected $model = WhatsAppConnection::class;

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
            'waba_id' => (string) fake()->unique()->numerify('1##############'),
            'phone_number_id' => (string) fake()->unique()->numerify('9##############'),
            'display_phone_number' => '+55 11 99999-0001',
            'verified_name' => 'Restaurante Teste',
            'access_token' => 'token-'.Str::random(24),
            'token_scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
            'registration_pin' => (string) random_int(100000, 999999),
            'onboarding_type' => WhatsAppConnection::TYPE_CLOUD_API,
            'status' => WhatsAppConnection::STATUS_ACTIVE,
            'quality_rating' => 'GREEN',
            'messaging_limit_tier' => 'TIER_250',
            'connected_at' => now(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(['company_id' => $company->id]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function coexistence(): static
    {
        return $this->state([
            'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE,
            'registration_pin' => null,
        ]);
    }
}
