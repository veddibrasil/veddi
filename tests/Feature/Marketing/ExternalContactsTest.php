<?php

use App\Enums\EmailCampaignStatus;
use App\Enums\EmailSuppressionReason;
use App\Livewire\SuperAdmin\Marketing\Campaigns\Wizard;
use App\Livewire\SuperAdmin\Marketing\Contacts\Index;
use App\Livewire\SuperAdmin\Marketing\Contacts\Show;
use App\Models\EmailCampaign;
use App\Models\EmailContact;
use App\Models\EmailContactList;
use App\Models\EmailSuppression;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\ContactListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => marketingSetup());

function externalContactList(): EmailContactList
{
    return EmailContactList::create(['name' => 'Interessados no PDV', 'source' => 'Formulário de interesse', 'consent_confirmed_at' => now()]);
}

test('superadmin cria lista com origem e confirmacao e abre a importacao', function () {
    Livewire::actingAs(marketingSuperAdmin())->test(Index::class)
        ->call('create')
        ->set('name', 'Interessados no PDV')
        ->set('source', 'Formulário do site')
        ->call('save')->assertHasErrors('consent')
        ->set('consent', true)
        ->call('save')->assertHasNoErrors()
        ->assertRedirect(route('superadmin.marketing.contacts.show', EmailContactList::firstOrFail()));

    expect(EmailContactList::first()->source)->toBe('Formulário do site');
});

test('importa texto colado normaliza emails e informa invalidos e descartados', function () {
    $list = externalContactList();

    Livewire::actingAs(marketingSuperAdmin())->test(Show::class, ['list' => $list])
        ->set('pasteFormat', 'table')
        ->set('pasted', "email;nome;empresa\n ANA@restaurante.com.br ;Ana;Casa da Ana\njoao@restaurante.com.br\nana@restaurante.com.br;Ana\nnao-e-email\nteste@example.com")
        ->call('importPasted')->assertHasNoErrors()
        ->assertSet('pastePreview.new', 0)
        ->assertSee('O texto foi mantido para revisar os itens ignorados.')
        ->assertSee('2 novo(s)')
        ->assertSee('1 inválido(s)')
        ->assertSee('1 ignorado(s)')
        ->assertSee('ana@restaurante.com.br')
        ->assertSee('Casa da Ana');

    expect($list->contacts()->count())->toBe(2)
        ->and($list->contacts()->where('email', 'ana@restaurante.com.br')->first()->name)->toBe('Ana');
});

test('importa CSV com colunas em outra ordem e nao duplica reimportacao', function () {
    $list = externalContactList();
    $csv = UploadedFile::fake()->createWithContent('contatos.csv', "nome,email,empresa\nAna,ana@restaurante.com.br,Casa da Ana\nJoão,joao@restaurante.com.br,Restaurante do João\n");

    Livewire::actingAs(marketingSuperAdmin())->test(Show::class, ['list' => $list])
        ->set('csv', $csv)->call('import')->assertHasNoErrors()
        ->assertSee('2 novo(s)')
        ->set('pasted', "ana@restaurante.com.br\njoao@restaurante.com.br")
        ->call('importPasted')->assertHasErrors('pasted')
        ->assertSet('pastePreview.existing', 2);

    expect($list->contacts()->count())->toBe(2);
});

test('detalhes mostram aptos supressoes e contas existentes e permitem buscar', function () {
    $list = externalContactList();
    app(ContactListService::class)->importText($list, "externo@restaurante.com.br\ndono@restaurante.com.br\nbloqueado@restaurante.com.br");
    marketingMember(user: ['email' => 'dono@restaurante.com.br']);
    EmailSuppression::create(['email' => 'bloqueado@restaurante.com.br', 'reason' => EmailSuppressionReason::Unsubscribed]);

    Livewire::actingAs(marketingSuperAdmin())->test(Show::class, ['list' => $list])
        ->assertSet('numbers.total', 3)
        ->assertSet('numbers.eligible', 1)
        ->assertSet('numbers.users', 1)
        ->assertSet('numbers.suppressed', 1)
        ->assertSee('Descadastrado ou bloqueado')
        ->set('search', 'externo@')
        ->assertSee('externo@restaurante.com.br')
        ->assertDontSee('dono@restaurante.com.br');
});

test('remocao de contato respeita a lista aberta', function () {
    $list = externalContactList();
    $other = externalContactList();
    $contact = EmailContact::create(['list_id' => $other->id, 'email' => 'outro@restaurante.com.br']);

    Livewire::actingAs(marketingSuperAdmin())->test(Show::class, ['list' => $list])
        ->call('removeContact', $contact->id);
    expect($contact->fresh())->not->toBeNull();
});

test('botao da lista abre campanha com publico externo selecionado sem donos', function () {
    $list = externalContactList();
    app(ContactListService::class)->importText($list, 'externo@restaurante.com.br');
    marketingMember(user: ['email' => 'dono@restaurante.com.br']);

    $this->actingAs(marketingSuperAdmin())->get(route('superadmin.marketing.contacts.show', $list))
        ->assertOk()->assertSee(route('superadmin.marketing.campaigns.create', ['lista' => $list->id]), false);

    Livewire::withQueryParams(['lista' => $list->id])->actingAs(marketingSuperAdmin())->test(Wizard::class)
        ->assertSet('audienceMode', 'contacts')
        ->assertSet('contactListIds', [$list->id])
        ->assertSet('includeOwners', false)
        ->set('step', 2)
        ->assertSee('Contatos externos (possíveis clientes)')
        ->assertSeeHtml('data-test="audience-count">1<');
});

test('campanha externa envia so para contatos aptos com personalizacao e descadastro', function () {
    $fake = marketingSetup();
    marketingMember(user: ['email' => 'dono@restaurante.com.br']);
    $list = externalContactList();
    app(ContactListService::class)->importText($list, "email;nome;empresa\nana@restaurante.com.br;Ana;Casa da Ana\ndono@restaurante.com.br;Dono;Atual\nsaiu@restaurante.com.br");
    EmailSuppression::create(['email' => 'saiu@restaurante.com.br', 'reason' => EmailSuppressionReason::Unsubscribed]);
    $campaign = EmailCampaign::factory()->create(['test_sent_at' => now(), 'filters' => $list->filterData()->toArray()]);

    app(CampaignService::class)->start($campaign);

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Sent)
        ->and($campaign->recipients_count)->toBe(1)
        ->and($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]->toEmail)->toBe('ana@restaurante.com.br')
        ->and($fake->sent[0]->subject)->toContain('Casa da Ana')
        ->and($fake->sent[0]->html)->toContain('Ana', '/email/u/');
});
