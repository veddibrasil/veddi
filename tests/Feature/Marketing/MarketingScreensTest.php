<?php

use App\Enums\EmailSuppressionReason;
use App\Livewire\SuperAdmin\Marketing\Segments\Index as SegmentsIndex;
use App\Livewire\SuperAdmin\Marketing\Suppressions\Index as SuppressionsIndex;
use App\Livewire\SuperAdmin\Marketing\Templates\Form as TemplateForm;
use App\Models\EmailSegment;
use App\Models\EmailSuppression;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => marketingSetup());

test('template salva blocos sanitizados e mostra preview com dados de empresa real', function () {
    $member = marketingMember(['name' => 'Pastelaria da Ana'], user: ['name' => 'Ana Souza']);

    Livewire::actingAs(marketingSuperAdmin())->test(TemplateForm::class)
        ->set('name', 'Novidades')
        ->set('blocks', [
            ['key' => 'a', 'type' => 'text', 'html' => 'Olá, {{primeiro_nome}} da {{empresa}}! <img src=x onerror=alert(1)>'],
        ])
        ->set('previewCompanyId', $member->companies()->first()->id)
        ->assertSee('Olá, Ana da Pastelaria da Ana!', escape: false)
        ->call('save')
        ->assertRedirect(route('superadmin.marketing.templates.index'));

    $template = EmailTemplate::firstOrFail();
    expect($template->html)->toContain('{{primeiro_nome}}')->not->toContain('onerror');
});

test('editor de blocos adiciona, move e remove', function () {
    Livewire::actingAs(marketingSuperAdmin())->test(TemplateForm::class)
        ->assertCount('blocks', 4)
        ->call('addBlock', 'image')
        ->call('addBlock', 'nao-existe')
        ->assertCount('blocks', 5)
        ->call('moveBlock', 4, -1)
        ->assertSet('blocks.3.type', 'image')
        ->call('removeBlock', 3)
        ->assertCount('blocks', 4);
});

test('segmento salva filtros validados e a contagem', function () {
    marketingMember(['plan' => 'pro']);
    marketingMember(['plan' => 'free']);

    Livewire::actingAs(marketingSuperAdmin())->test(SegmentsIndex::class)
        ->call('create')
        ->set('name', 'Clientes PRO')
        ->set('filters.plans', ['pro'])
        ->assertSeeHtml('data-test="segment-count">1<')
        ->call('save')
        ->assertHasNoErrors();

    $segment = EmailSegment::firstOrFail();
    expect($segment->last_count)->toBe(1)->and($segment->filters['plans'])->toBe(['pro']);
});

test('segmento recusa valor fora da lista', function () {
    Livewire::actingAs(marketingSuperAdmin())->test(SegmentsIndex::class)
        ->call('create')
        ->set('name', 'X')
        ->set('filters.plans', ['platinum'])
        ->call('save')
        ->assertHasErrors('filters.plans.0');
});

test('supressão manual, importação CSV e remoção com motivo', function () {
    $csv = UploadedFile::fake()->createWithContent('lista.csv', "email\nUm@A.com\ndois@a.com;x\ninvalido\num@a.com\n");

    $component = Livewire::actingAs(marketingSuperAdmin())->test(SuppressionsIndex::class)
        ->set('newEmail', ' Manual@A.com ')
        ->call('add')
        ->assertSet('notice', 'E-mail suprimido.')
        ->set('csv', $csv)
        ->call('import')
        ->assertSet('notice', 'Importação concluída: 2 novo(s), 1 já existente(s), 1 inválido(s).');

    expect(EmailSuppression::pluck('email')->sort()->values()->all())->toBe(['dois@a.com', 'manual@a.com', 'um@a.com']);

    $suppression = EmailSuppression::where('email', 'manual@a.com')->first();
    $component->call('confirmRemove', $suppression->id)
        ->call('remove')
        ->assertHasErrors('removalReason')
        ->set('removalReason', 'Pediu para voltar por telefone')
        ->call('remove')
        ->assertHasNoErrors();

    expect(EmailSuppression::where('email', 'manual@a.com')->exists())->toBeFalse();
});

test('bounce recebido não é rebaixado por supressão manual', function () {
    EmailSuppression::create(['email' => 'x@a.com', 'reason' => EmailSuppressionReason::HardBounce]);

    Livewire::actingAs(marketingSuperAdmin())->test(SuppressionsIndex::class)
        ->set('newEmail', 'x@a.com')
        ->call('add')
        ->assertSet('notice', 'Este e-mail já estava na lista.');

    expect(EmailSuppression::first()->reason)->toBe(EmailSuppressionReason::HardBounce);
});
