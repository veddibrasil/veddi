<?php

namespace Database\Factories;

use App\Enums\EmailCampaignStatus;
use App\Models\EmailCampaign;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailCampaign>
 */
class EmailCampaignFactory extends Factory
{
    protected $model = EmailCampaign::class;

    public function definition(): array
    {
        $blocks = [
            ['key' => 'h1', 'type' => 'header', 'title' => 'Oi, {{primeiro_nome}}', 'logo_url' => ''],
            ['key' => 't1', 'type' => 'text', 'html' => 'Conheça o novo PDV da {{empresa}}.'],
            ['key' => 'b1', 'type' => 'button', 'label' => 'Ver novidade', 'url' => 'https://veddi.com.br/novidades', 'color' => '#7A00A3'],
        ];
        $renderer = app(TemplateRenderer::class);
        $blocks = $renderer->normalizeBlocks($blocks);

        return [
            'name' => fake()->sentence(3),
            'kind' => EmailCampaign::KIND_CAMPAIGN,
            'subject' => 'Novidade para {{empresa}}',
            'preheader' => 'Veja o que mudou',
            'from_name' => 'Veddi',
            'from_email' => 'novidades@news.veddi.com.br',
            'blocks' => $blocks,
            'html' => $renderer->blocksToHtml($blocks),
            'filters' => [],
            'status' => EmailCampaignStatus::Draft,
        ];
    }

    public function status(EmailCampaignStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** Pronta para enviar: snapshot congelado e envio iniciado. */
    public function sending(): static
    {
        return $this->state(fn () => ['status' => EmailCampaignStatus::Sending, 'started_at' => now()])
            ->afterCreating(function (EmailCampaign $campaign) {
                $campaign->forceFill(['html_snapshot' => app(TemplateRenderer::class)->snapshot($campaign)])->save();
            });
    }
}
