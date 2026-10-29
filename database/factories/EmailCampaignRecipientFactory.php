<?php

namespace Database\Factories;

use App\Enums\EmailRecipientStatus;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailCampaignRecipient>
 */
class EmailCampaignRecipientFactory extends Factory
{
    protected $model = EmailCampaignRecipient::class;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'campaign_id' => EmailCampaign::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => $name,
            'variables' => TemplateRenderer::variablesFor($name, 'Coxinharia Teste', 'pro'),
            'status' => EmailRecipientStatus::Pending,
        ];
    }

    public function status(EmailRecipientStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
