<?php

use App\Enums\EmailCampaignStatus;
use App\Exceptions\EmailProviderException;
use App\Livewire\SuperAdmin\Marketing\Campaigns\Index;
use App\Livewire\SuperAdmin\Marketing\Campaigns\Show;
use App\Livewire\SuperAdmin\Marketing\Campaigns\Wizard;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSegment;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function wizardStepOne($component)
{
    return $component
        ->set('name', 'Lançamento PDV')
        ->set('subject', 'Novidade para {{empresa}}')
        ->set('preheader', 'Veja o que mudou')
        ->set('fromName', 'Veddi')
        ->set('fromEmail', 'novidades@news.veddi.com.br')
        ->set('blocks', [
            ['key' => 'a', 'type' => 'header', 'title' => 'Oi, {{primeiro_nome}}', 'logo_url' => ''],
            ['key' => 'b', 'type' => 'text', 'html' => '<p onclick="x()">PDV novo</p><script>alert(1)</script>'],
            ['key' => 'c', 'type' => 'button', 'label' => 'Ver', 'url' => 'https://veddi.com.br/pdv', 'color' => '#7A00A3'],
        ]);
}

test('fluxo completo: conteúdo, público, teste, checklist e disparo', function () {
    $fake = marketingSetup();
    marketingMember(['plan' => 'pro', 'name' => 'Coxinhas do Zé'], user: ['email' => 'ze@coxinhas.com', 'name' => 'Zé Silva']);
    marketingMember(['plan' => 'free'], user: ['email' => 'free@a.com']);

    $component = Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class);

    wizardStepOne($component)->call('next')->assertHasNoErrors()->assertSet('step', 2);

    $campaign = EmailCampaign::firstOrFail();
    expect($campaign->status)->toBe(EmailCampaignStatus::Draft)
        ->and($campaign->html)->not->toContain('script')->not->toContain('onclick');

    $component->set('audienceMode', 'filters')
        ->set('filters.plans', ['pro'])
        ->assertSeeHtml('data-test="audience-count">1<')
        ->call('next')
        ->assertSet('step', 3);

    expect($campaign->refresh()->filters['plans'])->toBe(['pro']);

    // Checklist pede o teste antes de liberar o disparo.
    $component->call('confirmDispatch')->assertSet('confirmingDispatch', false);

    $component->set('testEmails', 'eu@veddi.com.br')->call('sendTest')->assertHasNoErrors();
    expect($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]->subject)->toStartWith('[Teste]');

    $component->call('next')->assertSet('step', 4)
        ->call('confirmDispatch')
        ->assertSet('confirmingDispatch', true)
        ->assertSeeHtml('data-test="confirm-count">1<')
        ->call('dispatchCampaign')
        ->assertRedirect(route('superadmin.marketing.campaigns.show', $campaign));

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Sent)
        ->and(collect($fake->sent)->pluck('toEmail')->all())->toBe(['eu@veddi.com.br', 'ze@coxinhas.com'])
        ->and($fake->sent[1]->subject)->toBe('Novidade para Coxinhas do Zé');
});

test('envio de teste aceita até 5 e-mails válidos', function () {
    $fake = marketingSetup();
    $campaign = EmailCampaign::factory()->create();

    Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class, ['campaign' => $campaign])
        ->set('step', 3)
        ->set('testEmails', 'a@a.com, b@b.com, c@c.com, d@d.com, e@e.com, f@f.com')
        ->call('sendTest')
        ->assertHasErrors('testEmails')
        ->set('testEmails', 'nao-e-email')
        ->call('sendTest')
        ->assertHasErrors('testEmails');

    expect($fake->sent)->toBeEmpty()->and($campaign->refresh()->test_sent_at)->toBeNull();
});

test('erro do provedor no teste aparece para o super admin', function () {
    $fake = marketingSetup();
    $fake->failures['eu@veddi.com.br'] = EmailProviderException::permanent('Resend 403: domain not verified');
    $campaign = EmailCampaign::factory()->create();

    Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class, ['campaign' => $campaign])
        ->set('step', 3)
        ->set('testEmails', 'eu@veddi.com.br')
        ->call('sendTest')
        ->assertSet('errorNotice', 'O provedor recusou o envio de teste: Resend 403: domain not verified');
});

test('agendar grava o horário de Brasília em UTC', function () {
    marketingSetup();
    marketingMember();
    $campaign = EmailCampaign::factory()->create(['test_sent_at' => now()]);
    $local = now('America/Sao_Paulo')->addDay()->setTime(9, 30);

    Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class, ['campaign' => $campaign])
        ->set('step', 4)
        ->set('sendMode', 'schedule')
        ->set('scheduledAt', $local->format('Y-m-d\TH:i'))
        ->call('confirmDispatch')
        ->assertSet('confirmingDispatch', true)
        ->call('dispatchCampaign')
        ->assertRedirect();

    $campaign->refresh();
    expect($campaign->status)->toBe(EmailCampaignStatus::Scheduled)
        ->and($campaign->scheduled_at->equalTo($local->copy()->utc()))->toBeTrue();
});

test('variável desconhecida bloqueia o passo de conteúdo', function () {
    marketingSetup();

    wizardStepOne(Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class))
        ->set('subject', 'Use o cupom {{cupom}}')
        ->call('next')
        ->assertHasErrors('blocks')
        ->assertSet('step', 1);
});

test('campanha em envio não abre o wizard e o id é travado', function () {
    marketingSetup();
    $sending = EmailCampaign::factory()->sending()->create();
    $draft = EmailCampaign::factory()->create();

    Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class, ['campaign' => $sending])
        ->assertRedirect(route('superadmin.marketing.campaigns.show', $sending));

    expect(fn () => Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class, ['campaign' => $draft])->set('campaignId', $sending->id))
        ->toThrow(Exception::class);
});

test('carregar template copia blocos e assunto', function () {
    marketingSetup();
    $template = EmailTemplate::factory()->create(['subject_default' => 'Assunto do template']);

    Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class)
        ->set('subject', '')
        ->set('templateId', $template->id)
        ->call('loadTemplate')
        ->assertSet('subject', 'Assunto do template')
        ->assertCount('blocks', 3);
});

test('segmento salvo como público', function () {
    marketingSetup();
    marketingMember(['plan' => 'free']);
    $segment = EmailSegment::factory()->create(['filters' => ['plans' => ['free']]]);
    $campaign = EmailCampaign::factory()->create();

    Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class, ['campaign' => $campaign])
        ->set('step', 2)
        ->set('audienceMode', 'segment')
        ->set('segmentId', $segment->id)
        ->assertSeeHtml('data-test="audience-count">1<')
        ->call('next')
        ->assertSet('step', 3);

    expect($campaign->refresh()->segment_id)->toBe($segment->id);
});

test('lista de campanhas: duplicar, pausar e cancelar', function () {
    marketingSetup();
    $sending = EmailCampaign::factory()->sending()->create(['name' => 'Black Friday']);

    Livewire::actingAs(marketingSuperAdmin())->test(Index::class)
        ->assertSee('Black Friday')
        ->call('pause', $sending->id)
        ->assertSet('notice', 'Campanha pausada.')
        ->call('confirmCancel', $sending->id)
        ->call('cancel')
        ->call('duplicate', $sending->id)
        ->assertRedirect();

    expect($sending->refresh()->status)->toBe(EmailCampaignStatus::Cancelled)
        ->and(EmailCampaign::where('name', 'Cópia de Black Friday')->where('status', 'draft')->exists())->toBeTrue();
});

test('relatório mostra funil, destinatários e exporta CSV', function () {
    marketingSetup();
    $campaign = EmailCampaign::factory()->sending()->create(['sent_count' => 2, 'delivered_count' => 2, 'opened_count' => 1, 'recipients_count' => 2]);
    EmailCampaignRecipient::factory()->for($campaign, 'campaign')->create(['email' => 'um@a.com', 'status' => 'delivered', 'sent_at' => now()]);
    EmailCampaignRecipient::factory()->for($campaign, 'campaign')->create(['email' => 'dois@a.com', 'status' => 'failed', 'error' => 'Caixa cheia']);
    $admin = marketingSuperAdmin();

    Livewire::actingAs($admin)->test(Show::class, ['campaign' => $campaign])
        ->assertSee('Entregues')
        ->assertSee('um@a.com')
        ->assertSee('Caixa cheia')
        ->set('recipientStatus', 'failed')
        ->assertDontSee('um@a.com');

    EmailCampaignRecipient::factory()->for($campaign, 'campaign')->create(['email' => 'tres@a.com', 'name' => '=HYPERLINK("http://x")']);

    $csv = $this->actingAs($admin)->get(route('superadmin.marketing.campaigns.export', $campaign));
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('um@a.com')->toContain('Caixa cheia')
        ->toContain("'=HYPERLINK")->not->toContain(';=HYPERLINK');
});
