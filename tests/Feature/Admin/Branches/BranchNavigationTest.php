<?php

use App\Livewire\Admin\Branches\DeliverySettings;
use App\Livewire\Admin\Branches\Form;
use App\Livewire\Admin\Branches\Index;
use App\Livewire\Admin\Branches\Pauses;
use App\Livewire\Admin\SetupChecklist;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function bnCompany(array $overrides = []): Company
{
    $company = Company::create(array_merge([
        'name' => 'Nav Teste '.uniqid(),
        'slug' => 'nav-teste-'.uniqid(),
        'order_prefix' => 'NAV',
        'active' => true,
        'plan' => 'pro',
    ], $overrides));

    app()->instance('current.company', $company);

    return $company;
}

function bnBranch(Company $company, string $name = 'Filial Centro', array $overrides = []): Branch
{
    return Branch::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'name' => $name,
        'address' => 'Rua A, 1',
        'city' => 'Maringá',
        'state' => 'PR',
        'active' => true,
    ], $overrides));
}

function bnAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

function bnManager(Company $company, Branch $branch): User
{
    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);

    return $manager;
}

// ─── Lista: autorização e escopo ────────────────────────────────────────────

test('gerente de filial (branches.view sem coupons.view) abre a lista e vê só a própria filial', function () {
    $company = bnCompany();
    $own = bnBranch($company, 'Filial Própria');
    bnBranch($company, 'Filial Vizinha');
    $manager = bnManager($company, $own);

    Livewire::actingAs($manager)
        ->test(Index::class)
        ->assertOk()
        ->assertSee('Minha filial')
        ->assertSee('Filial Própria')
        ->assertDontSee('Filial Vizinha')
        ->assertDontSee('/'.$company->maxBranches().' filiai');
});

test('usuário sem branches.view leva 403 na lista de filiais', function () {
    $company = bnCompany();
    bnBranch($company);

    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'papel_sem_permissao']);

    Livewire::actingAs($user)->test(Index::class)->assertForbidden();
});

// ─── Lista: ações com texto (sem ícones mudos nem tooltip só no hover) ──────

test('lista mostra ações com rótulo de texto e sem o gate PRO morto', function () {
    $company = bnCompany(['pdv_module_enabled' => true]);
    bnBranch($company, 'Filial 1');
    bnBranch($company, 'Filial 2');

    Livewire::actingAs(bnAdmin($company))
        ->test(Index::class)
        ->assertSee('Editar')
        ->assertSee('Configurar')
        ->assertSee('Entrega')
        ->assertSee('Pausas e feriados')
        ->assertSee('Impressoras')
        ->assertSee('Taxa de serviço e couvert')
        ->assertSee('Mesas e comandas')
        ->assertSeeHtml('wire:click="confirmDelete(')
        ->assertDontSee('Somente Plano PRO');
});

test('botões Editar e Configurar têm o mesmo tamanho em todas as linhas, ativas ou não', function () {
    $company = bnCompany();
    bnBranch($company, 'Filial 1');
    bnBranch($company, 'Filial 2', ['active' => false]);
    bnBranch($company, 'Filial 3');

    $html = Livewire::actingAs(bnAdmin($company))->test(Index::class)->html();

    // 3 linhas × 2 botões, todos com a mesma largura fixa e o mesmo alinhamento.
    expect(substr_count($html, 'w-full justify-center sm:w-36'))->toBe(6);
});

test('sem módulo PDV a lista não oferece impressora, taxa de serviço nem mesas', function () {
    $company = bnCompany(['pdv_module_enabled' => false]);
    bnBranch($company);

    Livewire::actingAs(bnAdmin($company))
        ->test(Index::class)
        ->assertSee('Entrega')
        ->assertDontSee('Impressoras')
        ->assertDontSee('Taxa de serviço e couvert')
        ->assertDontSee('Mesas e comandas');
});

test('última filial não pode ser excluída e o menu explica o motivo', function () {
    $company = bnCompany();
    bnBranch($company);

    Livewire::actingAs(bnAdmin($company))
        ->test(Index::class)
        ->assertSee('Excluir (única filial)')
        ->assertDontSeeHtml('wire:click="confirmDelete(');
});

test('modal de exclusão diz o nome da filial que será removida', function () {
    $company = bnCompany();
    $branch = bnBranch($company, 'Filial Para Remover');
    bnBranch($company, 'Filial Que Fica');

    Livewire::actingAs(bnAdmin($company))
        ->test(Index::class)
        ->call('confirmDelete', $branch->id)
        ->assertSet('deletingName', 'Filial Para Remover')
        ->assertSee('Excluir “Filial Para Remover”?')
        ->call('cancelDelete')
        ->assertSet('deletingName', '');
});

test('confirmDelete de filial de outra empresa não revela o nome', function () {
    $company = bnCompany();
    bnBranch($company);
    $other = Company::create(['name' => 'Outra', 'slug' => 'outra-'.uniqid(), 'order_prefix' => 'OUT', 'active' => true]);
    $foreign = bnBranch($other, 'Filial Secreta');
    app()->instance('current.company', $company);

    Livewire::actingAs(bnAdmin($company))
        ->test(Index::class)
        ->call('confirmDelete', $foreign->id)
        ->assertSet('deletingName', '')
        ->assertDontSee('Filial Secreta');
});

test('limite de filiais atingido mostra o aviso na tela, não só em tooltip', function () {
    $company = bnCompany(['plan' => 'free']);
    bnBranch($company);

    Livewire::actingAs(bnAdmin($company))
        ->test(Index::class)
        ->assertSee('Limite de 1 filial(ais) do plano atingido.')
        ->assertSee('Ver planos');
});

test('estado vazio oferece o botão de criar a primeira filial', function () {
    $company = bnCompany();

    Livewire::actingAs(bnAdmin($company))
        ->test(Index::class)
        ->assertSee('Nenhuma filial cadastrada.')
        ->assertSeeHtml('href="'.route('admin.branches.create').'"');
});

// ─── Navegação entre as telas da filial ─────────────────────────────────────

test('telas da filial trazem abas para as demais configurações e marcam a atual', function () {
    $company = bnCompany(['pdv_module_enabled' => true]);
    $branch = bnBranch($company);

    Livewire::actingAs(bnAdmin($company))
        ->test(Pauses::class, ['branch' => $branch])
        ->assertSee('Dados e horários')
        ->assertSee('Entrega')
        ->assertSee('Impressoras')
        ->assertSee('Taxa de serviço')
        ->assertSee('Mesas e comandas')
        ->assertSeeHtml('href="'.route('admin.branches.delivery', $branch).'"')
        ->assertSeeHtml('href="'.route('admin.branches.printer', $branch).'"')
        ->assertSeeHtml('aria-current="page"')
        ->assertSeeHtml('aria-label="Localização"')
        ->assertDontSeeHtml('aria-label="Voltar para filiais"');
});

test('abas de PDV somem quando a empresa não tem o módulo PDV', function () {
    $company = bnCompany(['pdv_module_enabled' => false]);
    $branch = bnBranch($company);

    Livewire::actingAs(bnAdmin($company))
        ->test(Pauses::class, ['branch' => $branch])
        ->assertSee('Entrega')
        ->assertDontSee('Impressoras')
        ->assertDontSee('Mesas e comandas');
});

test('form de edição mostra as abas; form de criação não', function () {
    $company = bnCompany();
    $branch = bnBranch($company);
    $admin = bnAdmin($company);

    Livewire::actingAs($admin)->test(Form::class, ['branch' => $branch])
        ->assertSeeHtml('aria-label="Configurações da filial"');

    Livewire::actingAs($admin)->test(Form::class)
        ->assertDontSeeHtml('aria-label="Configurações da filial"');
});

// ─── Fim de fluxo consistente ───────────────────────────────────────────────

test('criar filial redireciona para a edição com o próximo passo', function () {
    $company = bnCompany();
    $admin = bnAdmin($company);

    $component = Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('name', 'Filial Nova')
        ->set('address', 'Av. Brasil')
        ->set('city', 'Maringá')
        ->set('state', 'PR')
        ->call('save')
        ->assertHasNoErrors();

    $branch = Branch::where('name', 'Filial Nova')->firstOrFail();

    $component->assertRedirect(route('admin.branches.edit', $branch));
    expect(session('status'))->toContain('Próximo passo');
});

test('salvar edição mantém o usuário na tela com o aviso de sucesso', function () {
    $company = bnCompany();
    $branch = bnBranch($company);

    Livewire::actingAs(bnAdmin($company))
        ->test(Form::class, ['branch' => $branch])
        ->set('name', 'Filial Renomeada')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSee('Filial atualizada.');

    expect($branch->fresh()->name)->toBe('Filial Renomeada');
});

test('UF vem de lista fechada e valores antigos em minúsculas são normalizados', function () {
    $company = bnCompany();
    $branch = bnBranch($company, 'Filial UF', ['state' => 'pr']);
    $admin = bnAdmin($company);

    Livewire::actingAs($admin)
        ->test(Form::class, ['branch' => $branch])
        ->assertSet('state', 'PR')
        ->set('state', 'XX')
        ->call('save')
        ->assertHasErrors(['state']);
});

test('CEP tem máscara, dispara a busca de endereço e vem antes da rua', function () {
    $company = bnCompany();

    $component = Livewire::actingAs(bnAdmin($company))
        ->test(Form::class)
        ->assertSeeHtml('x-mask="99999-999"')
        ->assertSeeHtml('lookupCep($event.target.value)');

    $html = $component->html();
    expect(strpos($html, 'wire:model="cep"'))->toBeLessThan(strpos($html, 'wire:model="address"'));
});

test('agendamento fica recolhido quando a empresa não usa agendamento e aberto quando usa', function () {
    $company = bnCompany(['schedule_min_advance_minutes' => 0]);
    $admin = bnAdmin($company);

    Livewire::actingAs($admin)->test(Form::class)
        ->assertSeeHtml('open: false')
        ->assertSee('Opcional — só é usado se o agendamento estiver ativado');

    $company->update(['schedule_min_advance_minutes' => 30]);
    app()->instance('current.company', $company->fresh());

    Livewire::actingAs($admin)->test(Form::class)
        ->assertSeeHtml('open: true')
        ->assertSee('Agendamento ativo na empresa');
});

// ─── Somente leitura e escopo de filial ─────────────────────────────────────

test('gerente (só branches.view) vê a filial em modo leitura, sem botão de salvar', function () {
    $company = bnCompany();
    $branch = bnBranch($company);

    Livewire::actingAs(bnManager($company, $branch))
        ->test(Form::class, ['branch' => $branch])
        ->assertSee('Somente leitura')
        ->assertDontSee('Salvar alterações');
});

test('gerente não abre edição nem entrega de outra filial da empresa', function () {
    $company = bnCompany();
    $own = bnBranch($company, 'Própria');
    $other = bnBranch($company, 'Outra');
    $manager = bnManager($company, $own);

    Livewire::actingAs($manager)->test(Form::class, ['branch' => $other])->assertForbidden();
    Livewire::actingAs($manager)->test(DeliverySettings::class, ['branch' => $other])->assertForbidden();
    Livewire::actingAs($manager)->test(Form::class, ['branch' => $own])->assertOk();
    Livewire::actingAs($manager)->test(DeliverySettings::class, ['branch' => $own])->assertOk();
});

// ─── Sidebar e checklist ────────────────────────────────────────────────────

test('sidebar do admin tem grupo Loja com Filiais, separado do Cardápio', function () {
    $company = bnCompany();
    bnBranch($company);

    $html = $this->actingAs(bnAdmin($company))
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Loja')->toContain('Filiais')->toContain('Cardápio');
    expect(strpos($html, '>Loja<'))->toBeLessThan(strpos($html, '>Cardápio<'));
});

test('sidebar do gerente mostra "Minha filial" e abre a página', function () {
    $company = bnCompany();
    $branch = bnBranch($company);

    $this->actingAs(bnManager($company, $branch))
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertSee('Minha filial')
        ->assertDontSee('Nova filial');
});

test('checklist de primeiros passos leva direto ao cadastro de filial', function () {
    $company = bnCompany();

    Livewire::actingAs(bnAdmin($company))
        ->test(SetupChecklist::class)
        ->assertSeeHtml('href="'.route('admin.branches.create').'"');
});
