<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        $blocks = [
            ['key' => 'h1', 'type' => 'header', 'title' => 'Novidade no painel', 'logo_url' => ''],
            ['key' => 't1', 'type' => 'text', 'html' => '<p style="margin:0 0 16px;line-height:1.6;">Olá, {{primeiro_nome}}!</p>'],
            ['key' => 'b1', 'type' => 'button', 'label' => 'Acessar', 'url' => '{{link_painel}}', 'color' => '#7A00A3'],
        ];

        return [
            'name' => fake()->words(3, true),
            'subject_default' => 'Novidade para {{empresa}}',
            'preheader_default' => null,
            'blocks' => $blocks,
            'html' => app(TemplateRenderer::class)->blocksToHtml($blocks),
        ];
    }
}
