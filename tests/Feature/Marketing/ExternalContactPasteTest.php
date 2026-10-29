<?php

use App\Livewire\SuperAdmin\Marketing\Contacts\Show;
use App\Models\EmailContact;
use App\Models\EmailContactList;
use App\Services\Marketing\ContactListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    marketingSetup();
    $this->list = EmailContactList::create(['name' => 'Interessados', 'source' => 'Formulário', 'consent_confirmed_at' => now()]);
});

test('previa separa novos existentes repetidos e problemas sem gravar contatos', function () {
    EmailContact::create(['list_id' => $this->list->id, 'email' => 'ana@restaurante.com.br']);
    $preview = app(ContactListService::class)->previewText($this->list, " ANA@restaurante.com.br ; joao@restaurante.com.br,joao@restaurante.com.br\nnao-e-email teste@example.com");

    expect($preview['total'])->toBe(5)
        ->and($preview['new'])->toBe(1)
        ->and($preview['existing'])->toBe(1)
        ->and($preview['duplicates'])->toBe(1)
        ->and($preview['invalid'])->toBe(1)
        ->and($preview['skipped'])->toBe(1)
        ->and($preview['sample'][0]['existing'])->toBeTrue()
        ->and($preview['issues'][0]['reason'])->toBe('Endereço inválido')
        ->and($this->list->contacts()->count())->toBe(1);
});

test('lista simples importa todos os emails separados por virgula ponto e virgula espaco ou tab', function () {
    $text = " ana@restaurante.com.br, joao@restaurante.com.br;bia@restaurante.com.br\tleo@restaurante.com.br\nleo@restaurante.com.br";
    $service = app(ContactListService::class);
    $preview = $service->previewText($this->list, $text);
    $result = $service->importText($this->list, $text, 'emails');

    expect($preview['new'])->toBe(4)
        ->and($result['added'])->toBe(4)
        ->and($result['duplicates'])->toBe(1)
        ->and($this->list->contacts()->whereNotNull('name')->count())->toBe(0);
});

test('modo de planilha preserva nomes empresas acentos e aspas na previa e na importacao', function () {
    $text = "\u{FEFF}nome\temail\tempresa\nJoão\tjoao@restaurante.com.br\t\"Restaurante \"\"da\"\" Praça\"";
    $service = app(ContactListService::class);
    $preview = $service->previewText($this->list, $text, 'table');
    $service->importText($this->list, $text, 'table');

    expect($preview['new'])->toBe(1)
        ->and($preview['sample'][0]['name'])->toBe('João')
        ->and($preview['sample'][0]['company_name'])->toBe('Restaurante "da" Praça')
        ->and($this->list->contacts()->first()->company_name)->toBe('Restaurante "da" Praça');
});

test('componente mostra previa ao digitar e importa somente ao confirmar', function () {
    $component = Livewire::actingAs(marketingSuperAdmin())->test(Show::class, ['list' => $this->list])
        ->assertSet('pasteFormat', 'emails')
        ->set('pasted', 'ana@restaurante.com.br, joao@restaurante.com.br')
        ->assertSet('pastePreview.new', 2)
        ->assertSee('Adicionar 2 contatos');
    expect($this->list->contacts()->count())->toBe(0);

    $component->call('importPasted')->assertHasNoErrors()
        ->assertSet('pasted', '')
        ->assertSet('pastePreview.new', 0)
        ->assertSee('2 novo(s)');
    expect($this->list->contacts()->count())->toBe(2);
});

test('sem novos contatos o submit preserva o texto e apresenta uma orientacao', function () {
    EmailContact::create(['list_id' => $this->list->id, 'email' => 'ana@restaurante.com.br']);
    Livewire::actingAs(marketingSuperAdmin())->test(Show::class, ['list' => $this->list])
        ->set('pasted', 'ana@restaurante.com.br')
        ->call('importPasted')->assertHasErrors('pasted')
        ->assertSet('pasted', 'ana@restaurante.com.br')
        ->assertSee('Os contatos válidos já estão nesta lista.')
        ->set('pasted', 'invalido')
        ->assertHasNoErrors('pasted')
        ->call('importPasted')->assertHasErrors('pasted')
        ->assertSet('pasted', 'invalido');
    expect($this->list->contacts()->count())->toBe(1);
});

test('importacao parcial mantem itens para revisar e permite limpar a entrada', function () {
    Livewire::actingAs(marketingSuperAdmin())->test(Show::class, ['list' => $this->list])
        ->set('pasted', 'ana@restaurante.com.br;invalido')
        ->call('importPasted')->assertHasNoErrors()
        ->assertSet('pasted', 'ana@restaurante.com.br;invalido')
        ->assertSet('pastePreview.new', 0)
        ->assertSet('pastePreview.existing', 1)
        ->assertSet('pastePreview.invalid', 1)
        ->assertSee('O texto foi mantido')
        ->call('clearPasted')
        ->assertSet('pasted', '')
        ->assertSet('pasteNotice', null)
        ->assertSet('pastePreview.total', 0);
});

test('previa limita amostras e protege entradas acima do limite', function () {
    $text = implode(';', array_map(fn ($i) => "pessoa{$i}@restaurante.com.br", range(1, 8)));
    $preview = app(ContactListService::class)->previewText($this->list, $text);
    expect($preview['new'])->toBe(8)->and($preview['sample'])->toHaveCount(5);

    Livewire::actingAs(marketingSuperAdmin())->test(Show::class, ['list' => $this->list])
        ->set('pasted', str_repeat('a', 200001))
        ->assertSet('pastePreview.too_long', true)
        ->call('importPasted')->assertHasErrors('pasted');
    expect($this->list->contacts()->count())->toBe(0);
});
