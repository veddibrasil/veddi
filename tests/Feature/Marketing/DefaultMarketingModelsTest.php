<?php

use App\DTOs\AudienceFilterData;
use App\Enums\EmailAutomationTrigger;
use App\Enums\EmailCampaignStatus;
use App\Enums\EmailSuppressionReason;
use App\Livewire\SuperAdmin\Marketing\Automations\Index as AutomationsIndex;
use App\Livewire\SuperAdmin\Marketing\Campaigns\Index as CampaignsIndex;
use App\Livewire\SuperAdmin\Marketing\Segments\Index as SegmentsIndex;
use App\Livewire\SuperAdmin\Marketing\Suppressions\Index as SuppressionsIndex;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailAutomation;
use App\Models\EmailAutomationEnrollment;
use App\Models\EmailCampaign;
use App\Models\EmailSegment;
use App\Models\EmailSuppression;
use App\Models\EmailTemplate;
use App\Services\Marketing\AutomationService;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\DefaultEmailAutomations;
use App\Services\Marketing\DefaultEmailCampaigns;
use App\Services\Marketing\DefaultEmailSegments;
use App\Services\Marketing\DefaultEmailTemplates;
use App\Services\Marketing\SuppressionCleanup;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    marketingSetup();
    config(['marketing.from' => ['address' => 'novidades@news.veddi.com.br', 'name' => 'Veddi']]);
});

test('segmentos padrão: instala uma vez, com filtros válidos e contagem', function () {
    marketingMember(['plan' => 'free'], user: ['email' => 'gratis@a.com']);
    marketingMember(['plan' => 'pro'], user: ['email' => 'pro@a.com']);
    EmailSegment::factory()->create(['name' => 'plano grátis ativo', 'filters' => ['plans' => ['pro']]]);

    $defaults = app(DefaultEmailSegments::class);
    $total = count($defaults->definitions());

    expect($defaults->install())->toBe($total - 1)
        ->and($defaults->install())->toBe(0)
        ->and(EmailSegment::count())->toBe($total)
        ->and(EmailSegment::where('name', 'plano grátis ativo')->first()->filterData()->plans)->toBe(['pro']);

    foreach ($defaults->definitions() as $definition) {
        // Nada se perde na validação da lista fechada nem fica com data absoluta.
        $filters = AudienceFilterData::fromArray($definition['filters']);
        expect(array_filter($filters->toArray(), fn ($value) => $value !== null && $value !== []))->toEqual($definition['filters'])
            ->and($filters->createdFrom)->toBeNull()
            ->and($filters->createdTo)->toBeNull();
    }

    expect(EmailSegment::where('name', 'Assinantes Essencial e PRO')->value('last_count'))->toBe(1)
        ->and(EmailSegment::where('name', 'Ativos sem PDV')->first()->filterData()->pdvEnabled)->toBeFalse();
});

test('super admin adiciona os segmentos padrão pela tela', function () {
    Livewire::actingAs(marketingSuperAdmin())->test(SegmentsIndex::class)
        ->call('installDefaults')
        ->assertSee('segmentos padrão adicionados')
        ->assertSee('Plano Grátis ativo')
        ->call('installDefaults')
        ->assertSee('Os segmentos padrão já estão na lista.');
});

test('logo e links dos modelos usam o APP_URL público, não o host de quem está no painel', function () {
    config(['app.url' => 'https://app.veddi.com.br']);
    URL::forceRootUrl('http://localhost:8000');

    $content = json_encode(app(DefaultEmailTemplates::class)->definitions(), JSON_UNESCAPED_SLASHES);
    preg_match_all('#https?://[^"\\\\\s<>]+#', $content, $urls);

    expect($urls[0])->not->toBeEmpty()
        ->and(collect($urls[0])->reject(fn (string $url) => str_starts_with($url, 'https://app.veddi.com.br/'))->all())->toBe([])
        ->and($content)->toContain('https://app.veddi.com.br/images/email/veddi-logo.png')
        ->and(TemplateRenderer::variablesFor('Ana', 'Loja', 'pro')['link_painel'])->toBe('https://app.veddi.com.br'.route('login', absolute: false));
});

test('templates padrão de PDV e nota fiscal usam o preço do config', function () {
    config(['pdv.addon_monthly_price' => 79.9, 'fiscal.addon_monthly_price' => 120]);

    $definitions = collect(app(DefaultEmailTemplates::class)->definitions())->keyBy('name');
    $renderer = app(TemplateRenderer::class);

    expect($renderer->blocksToHtml($definitions['Conheça o PDV']['blocks']))->toContain('R$ 79,90 por mês')
        ->and($renderer->blocksToHtml($definitions['Nota fiscal pelo Veddi']['blocks']))->toContain('R$ 120,00 por mês');
});

test('campanha pronta vira rascunho com template e segmento padrão instalados só se faltarem', function () {
    $admin = marketingSuperAdmin();
    marketingMember(['plan' => 'free']);

    Livewire::actingAs($admin)->test(CampaignsIndex::class)
        ->call('createFromPreset', 'upgrade_free')
        ->assertRedirect();

    $campaign = EmailCampaign::campaigns()->with(['template', 'segment'])->sole();

    expect($campaign->status)->toBe(EmailCampaignStatus::Draft)
        ->and($campaign->template->name)->toBe('Upgrade do plano Grátis')
        ->and($campaign->segment->name)->toBe('Plano Grátis ativo')
        ->and($campaign->subject)->toBe($campaign->template->subject_default)
        ->and($campaign->from_email)->toBe('novidades@news.veddi.com.br')
        ->and($campaign->html)->toContain('Hora de tirar os limites')
        ->and($campaign->test_sent_at)->toBeNull()
        ->and(EmailTemplate::count())->toBe(1)
        ->and(EmailSegment::count())->toBe(1);

    // Segunda campanha com o mesmo modelo reaproveita template e segmento.
    app(DefaultEmailCampaigns::class)->createDraft('upgrade_free', $admin);

    expect(EmailTemplate::count())->toBe(1)
        ->and(EmailSegment::count())->toBe(1)
        ->and(EmailCampaign::campaigns()->count())->toBe(2);

    // Só falta o teste no checklist: o resto do modelo já está pronto para disparo.
    $pending = collect(app(CampaignService::class)->checklist($campaign))->reject(fn ($item) => $item['ok'])->pluck('key')->all();
    expect($pending)->toBe(['test']);
});

test('todas as campanhas prontas apontam para template e segmento padrão existentes', function () {
    $templates = collect(app(DefaultEmailTemplates::class)->definitions())->pluck('name');
    $segments = collect(app(DefaultEmailSegments::class)->definitions())->pluck('name');

    foreach (app(DefaultEmailCampaigns::class)->definitions() as $preset) {
        expect($templates)->toContain($preset['template'])
            ->and($segments)->toContain($preset['segment']);
    }
});

test('campanha pronta desconhecida não cria nada', function () {
    Livewire::actingAs(marketingSuperAdmin())->test(CampaignsIndex::class)
        ->call('createFromPreset', 'nao-existe')
        ->assertNotFound();

    expect(EmailCampaign::count())->toBe(0);
});

test('automações padrão: pausadas, com os templates padrão e prontas para ativar', function () {
    $admin = marketingSuperAdmin();
    $defaults = app(DefaultEmailAutomations::class);

    expect($defaults->install($admin))->toBe(3)
        ->and($defaults->install($admin))->toBe(0);

    $automations = EmailAutomation::with('steps.campaign.template')->get()->keyBy('name');
    $welcome = $automations['Boas-vindas a empresas novas'];

    expect($automations)->toHaveCount(3)
        ->and($automations->every(fn ($automation) => ! $automation->active))->toBeTrue()
        ->and($welcome->trigger)->toBe(EmailAutomationTrigger::NewCompany)
        ->and($welcome->steps->pluck('delay_hours')->all())->toBe([0, 72])
        ->and($welcome->steps->map(fn ($step) => $step->campaign->template->name)->all())->toBe(['Boas-vindas', 'Dicas para vender mais'])
        ->and($welcome->steps[0]->campaign->subject)->toBe('Boas-vindas ao Veddi, {{primeiro_nome}}!')
        ->and(EmailTemplate::where('name', 'Dicas para vender mais')->count())->toBe(1);

    foreach ($automations as $automation) {
        expect(app(AutomationService::class)->activationProblems($automation))->toBe([]);
    }
});

test('super admin adiciona as automações padrão pela tela', function () {
    Livewire::actingAs(marketingSuperAdmin())->test(AutomationsIndex::class)
        ->call('installDefaults')
        ->assertSee('3 automações padrão adicionadas')
        ->assertSee('Recuperar cadastro abandonado');
});

test('kit inicial instala templates, segmentos e automações de uma vez', function () {
    Livewire::actingAs(marketingSuperAdmin())->test(CampaignsIndex::class)
        ->assertSee('Primeiros passos')
        ->call('installStarterKit')
        ->assertSee('Modelos prontos instalados')
        ->assertDontSee('Primeiros passos')
        ->call('installStarterKit')
        ->assertSee('Os modelos prontos já estão instalados.');

    expect(EmailTemplate::count())->toBe(count(app(DefaultEmailTemplates::class)->definitions()))
        ->and(EmailSegment::count())->toBe(count(app(DefaultEmailSegments::class)->definitions()))
        ->and(EmailAutomation::count())->toBe(3);
});

test('empresa nova: entra depois do prazo, só cadastro recente e ativo, e sai se deixar de estar ativa', function () {
    $fake = marketingSetup();
    config(['marketing.from' => ['address' => 'novidades@news.veddi.com.br', 'name' => 'Veddi']]);
    app(DefaultEmailAutomations::class)->install(marketingSuperAdmin());
    $automation = EmailAutomation::where('trigger', 'new_company')->with('steps.campaign')->sole();
    app(AutomationService::class)->setActive($automation, true);

    $new = marketingMember(['name' => 'Pizza Nova'], user: ['email' => 'nova@a.com', 'name' => 'Ana Souza']);
    $new->companies()->first()->forceFill(['created_at' => now()->subHours(2)])->save();
    marketingMember(user: ['email' => 'agora@a.com']);
    $old = marketingMember(user: ['email' => 'antiga@a.com']);
    $old->companies()->first()->forceFill(['created_at' => now()->subDays(20)])->save();
    $pending = marketingMember(['status' => 'PENDING_PAYMENT', 'active' => false], user: ['email' => 'pendente@a.com']);
    $pending->companies()->first()->forceFill(['created_at' => now()->subHours(5)])->save();

    $this->artisan('marketing:run-automations')->assertSuccessful();

    expect($fake->sentTo())->toBe(['nova@a.com'])
        ->and($fake->sent[0]->subject)->toBe('Boas-vindas ao Veddi, Ana!');

    $new->companies()->first()->forceFill(['status' => 'BLOCKED'])->save();
    $this->artisan('marketing:run-automations')->assertSuccessful();

    expect(EmailAutomationEnrollment::where('email', 'nova@a.com')->value('status'))->toBe(EmailAutomationEnrollment::STATUS_EXITED);
});

test('limpeza da lista acha endereços problemáticos e suprime só os da regra', function () {
    marketingMember(user: ['email' => 'dono@gmail.con']);
    marketingMember(user: ['email' => 'Teste@Example.com']);
    marketingMember(user: ['email' => 'loja@yopmail.com']);
    marketingMember(user: ['email' => 'noreply+pedidos@lanches.com.br']);
    marketingMember(user: ['email' => 'caixa@loja.local']);
    marketingMember(user: ['email' => 'certo@lanches.com.br']);
    marketingMember(user: ['email' => 'ja@example.org']);
    EmailSuppression::create(['email' => 'ja@example.org', 'reason' => EmailSuppressionReason::HardBounce]);
    // Cliente final de restaurante nunca entra no público, nem na limpeza.
    Customer::withoutGlobalScopes()->create(['company_id' => Company::first()->id, 'name' => 'Cliente', 'phone' => '11999999999', 'email' => 'cliente@example.com']);

    expect(app(SuppressionCleanup::class)->scan())->toBe([
        'invalid' => [],
        'typo' => ['dono@gmail.con'],
        'test' => ['caixa@loja.local', 'teste@example.com'],
        'disposable' => ['loja@yopmail.com'],
        'automatic' => ['noreply+pedidos@lanches.com.br'],
    ]);

    Livewire::actingAs(marketingSuperAdmin())->test(SuppressionsIndex::class)
        ->call('scanCleanup')
        ->assertSee('dono@gmail.con')
        ->call('confirmCleanup', 'test')
        ->assertSet('confirmingCleanup', true)
        ->call('runCleanup')
        ->assertSee('2 e-mails suprimidos (E-mail de teste).');

    expect(EmailSuppression::where('reason', EmailSuppressionReason::Manual)->orderBy('email')->pluck('email')->all())
        ->toBe(['caixa@loja.local', 'teste@example.com'])
        ->and(EmailSuppression::where('email', 'teste@example.com')->value('note'))->toBe('Limpeza da lista: E-mail de teste')
        ->and(EmailSuppression::where('email', 'ja@example.org')->value('reason'))->toBe(EmailSuppressionReason::HardBounce);
});

test('limpeza com regra forjada não suprime nada', function () {
    marketingMember(user: ['email' => 'teste@example.com']);

    Livewire::actingAs(marketingSuperAdmin())->test(SuppressionsIndex::class)
        ->set('cleanupRule', 'qualquer')
        ->call('runCleanup')
        ->assertSee('Regra de limpeza desconhecida.');

    expect(EmailSuppression::count())->toBe(0);
});

test('usuário sem super admin não instala modelos nem limpa a lista', function () {
    $member = marketingMember();

    Livewire::actingAs($member)->test(SegmentsIndex::class)->assertForbidden();
    Livewire::actingAs($member)->test(AutomationsIndex::class)->assertForbidden();
    Livewire::actingAs($member)->test(CampaignsIndex::class)->assertForbidden();
    Livewire::actingAs($member)->test(SuppressionsIndex::class)->assertForbidden();

    expect(EmailSegment::count() + EmailAutomation::count() + EmailCampaign::count() + EmailSuppression::count())->toBe(0);
});
