<?php

use App\Livewire\SuperAdmin\Marketing\Templates\Index as TemplatesIndex;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\DefaultEmailTemplates;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => marketingSetup());

test('instala os modelos padrão uma vez só, sem duplicar nem sobrescrever os existentes', function () {
    $defaults = app(DefaultEmailTemplates::class);
    $total = count($defaults->definitions());
    EmailTemplate::factory()->create(['name' => 'boas-vindas', 'subject_default' => 'Meu assunto']);

    expect($defaults->install())->toBe($total - 1)
        ->and($defaults->install())->toBe(0)
        ->and(EmailTemplate::count())->toBe($total)
        ->and(EmailTemplate::where('name', 'boas-vindas')->value('subject_default'))->toBe('Meu assunto');
});

test('modelos padrão usam só variáveis da lista fechada e nenhum bloco se perde na sanitização', function () {
    $renderer = app(TemplateRenderer::class);

    foreach (app(DefaultEmailTemplates::class)->definitions() as $definition) {
        $normalized = $renderer->normalizeBlocks($definition['blocks']);
        $html = $renderer->blocksToHtml($normalized);

        expect($normalized)->toHaveCount(count($definition['blocks']))
            ->and($renderer->unknownVariables($definition['subject'], $definition['preheader'], $html))->toBe([])
            ->and($html)->not->toContain('<script');

        foreach ($normalized as $block) {
            if ($block['type'] === 'button') {
                expect($block['url'])->not->toBe('');
            }
        }
    }
});

test('upgrade do plano Grátis usa os números de config/plans.php', function () {
    config(['plans.free.max_orders_per_month' => 80, 'plans.pro.max_branches' => 5]);

    $upgrade = collect(app(DefaultEmailTemplates::class)->definitions())->firstWhere('name', 'Upgrade do plano Grátis');
    $html = app(TemplateRenderer::class)->blocksToHtml($upgrade['blocks']);

    expect($html)->toContain('80 pedidos por mês')->toContain('até 5 filiais');
});

test('super admin adiciona os modelos pela tela de templates', function () {
    $admin = marketingSuperAdmin();

    Livewire::actingAs($admin)->test(TemplatesIndex::class)
        ->call('installDefaults')
        ->assertSee('modelos padrão adicionados')
        ->assertSee('Boas-vindas')
        ->call('installDefaults')
        ->assertSee('Os modelos padrão já estão na lista.');

    expect(EmailTemplate::where('created_by', $admin->id)->count())->toBe(count(app(DefaultEmailTemplates::class)->definitions()));
});

test('usuário sem super admin não instala modelos', function () {
    $member = marketingMember();

    Livewire::actingAs($member)->test(TemplatesIndex::class)->assertForbidden();

    expect(EmailTemplate::count())->toBe(0);
});

test('checklist bloqueia campanha com trecho [EDITAR: ...] do modelo', function () {
    marketingMember();
    $template = collect(app(DefaultEmailTemplates::class)->definitions())->firstWhere('name', 'Lançamento de novidade');
    $html = app(TemplateRenderer::class)->blocksToHtml($template['blocks']);
    $campaign = EmailCampaign::factory()->create([
        'subject' => $template['subject'],
        'blocks' => $template['blocks'],
        'html' => $html,
        'test_sent_at' => now(),
    ]);

    $item = collect(app(CampaignService::class)->checklist($campaign))->firstWhere('key', 'pending_edits');

    expect($item['ok'])->toBeFalse()
        ->and($item['hint'])->toContain('[EDITAR: nome da novidade]')
        ->and(fn () => app(CampaignService::class)->start($campaign))->toThrow(LogicException::class);

    $campaign->update(['subject' => 'Novidade no Veddi: PDV', 'html' => '<tr><td>PDV novo</td></tr>']);

    expect(collect(app(CampaignService::class)->checklist($campaign->refresh()))->firstWhere('key', 'pending_edits')['ok'])->toBeTrue();
});
