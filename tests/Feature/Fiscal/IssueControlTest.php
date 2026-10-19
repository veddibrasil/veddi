<?php

use App\Events\FiscalNoteFailed;
use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Fiscal\IssueControl;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyNotification;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    config(['fiscal.focus_nfe.token' => 'platform-token']);
});

function controlCompany(string $name = 'Empresa Controle', bool $fiscalEnabled = true): array
{
    $company = Company::create([
        'name' => $name,
        'slug' => 'controle-'.uniqid(),
        'order_prefix' => 'CTL',
        'active' => true,
        'status' => 'ACTIVE',
        'owner_cpf_cnpj' => '11222333000181',
        'fiscal_notes_enabled' => $fiscalEnabled,
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Matriz '.$name,
        'address' => 'Rua A',
        'number' => '1',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01001000',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    CompanyFiscalConfig::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'is_default' => true,
        'enabled' => true,
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return compact('company', 'branch', 'admin');
}

function controlOrder(Company $company, Branch $branch, string $number, array $attributes = []): Order
{
    $customer = Customer::withoutGlobalScopes()->firstOrCreate(
        ['company_id' => $company->id, 'phone' => '1199999'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT)],
        ['name' => 'Cliente '.$company->name],
    );

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 20,
        'delivery_fee' => 0,
        'total' => 20,
        'fee' => 0,
        'net_value' => 20,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
        ...$attributes,
    ]);

    // order_number é gerado no creating(); fixa um legível pros asserts.
    $order->forceFill(['order_number' => $number])->saveQuietly();

    return $order;
}

function controlNote(Order $order, string $status): FiscalNote
{
    return FiscalNote::withoutGlobalScopes()->create([
        'company_id' => $order->company_id,
        'order_id' => $order->id,
        'status' => $status,
        'model' => '65',
    ]);
}

test('lista por padrão só pedidos pagos de hoje, da empresa atual, sem nota ou com erro', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();
    ['company' => $other, 'branch' => $otherBranch] = controlCompany('Outra');

    controlOrder($company, $branch, 'CTL-SEMNOTA');
    controlNote(controlOrder($company, $branch, 'CTL-REJEITADA'), 'rejected');
    controlNote(controlOrder($company, $branch, 'CTL-AUTORIZADA'), 'authorized');
    controlOrder($company, $branch, 'CTL-AGUARDANDO', ['status' => 'awaiting_payment']);
    controlOrder($company, $branch, 'CTL-ONTEM')->forceFill(['created_at' => now()->subDay()])->save();
    controlOrder($other, $otherBranch, 'OUT-SEMNOTA');

    app()->instance('current.company', $company);

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->assertSee('CTL-SEMNOTA')
        ->assertSee('CTL-REJEITADA')
        ->assertDontSee('CTL-AUTORIZADA')
        ->assertDontSee('CTL-AGUARDANDO')
        ->assertDontSee('CTL-ONTEM')
        ->assertDontSee('OUT-SEMNOTA')
        ->set('situationFilter', 'authorized')
        ->assertSee('CTL-AUTORIZADA')
        ->assertDontSee('CTL-SEMNOTA')
        ->set('situationFilter', 'to_issue')
        ->set('dateFrom', now()->subDay()->toDateString())
        ->assertSee('CTL-ONTEM');
});

test('filtra por canal', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();

    controlOrder($company, $branch, 'CTL-BALCAO');
    controlOrder($company, $branch, 'CTL-MESA', ['table_label' => 'Mesa 4']);
    controlOrder($company, $branch, 'CTL-IFOOD', ['order_type' => 'delivery', 'channel' => 'ifood', 'payment_method' => 'ifood']);

    app()->instance('current.company', $company);

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->set('channelFilter', 'comanda')
        ->assertSee('CTL-MESA')
        ->assertDontSee('CTL-BALCAO')
        ->assertDontSee('CTL-IFOOD')
        ->set('channelFilter', 'ifood')
        ->assertSee('CTL-IFOOD')
        ->assertDontSee('CTL-MESA');
});

test('emissão em lote separa pendências, ignora pedido com nota ativa ou de outra empresa e enfileira na fila low', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();
    ['company' => $other, 'branch' => $otherBranch] = controlCompany('Outra');

    $ready1 = controlOrder($company, $branch, 'CTL-1');
    $ready2 = controlOrder($company, $branch, 'CTL-2');
    $withNote = controlOrder($company, $branch, 'CTL-3');
    controlNote($withNote, 'authorized');
    // CNPJ sem endereço: NF-e com dados pendentes, não pode ir pro lote.
    $blocked = controlOrder($company, $branch, 'CTL-4', ['fiscal_recipient' => ['document' => '11222333000181']]);
    $foreign = controlOrder($other, $otherBranch, 'OUT-1');

    app()->instance('current.company', $company);
    Bus::fake();

    $component = Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->set('selected', [$ready1->id, $ready2->id, $withNote->id, $blocked->id, $foreign->id])
        ->call('prepareIssue')
        ->assertSet('showConfirmModal', true)
        ->assertSet('confirmSummary.ready', 2)
        ->assertSet('confirmSummary.nfce', 2)
        ->assertSee('CTL-4');

    expect($component->get('readyOrderIds'))->toEqualCanonicalizing([$ready1->id, $ready2->id]);
    expect($component->get('confirmSummary.blocked'))->toHaveCount(1);

    $component->call('issueSelected')
        ->assertSet('showConfirmModal', false)
        ->assertSet('selected', []);

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->queue() === 'low'
        && $batch->jobs->count() === 2
        && $batch->jobs->pluck('orderId')->sort()->values()->all() === collect([$ready1->id, $ready2->id])->sort()->values()->all()
        && $batch->jobs->every(fn (IssueFiscalNote $job) => $job->requestedBy === $admin->id));
});

test('confirmação refaz a elegibilidade: pedido emitido entre o resumo e a confirmação sai do lote', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();

    $order1 = controlOrder($company, $branch, 'CTL-1');
    $order2 = controlOrder($company, $branch, 'CTL-2');

    app()->instance('current.company', $company);
    Bus::fake();

    $component = Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->set('selected', [$order1->id, $order2->id])
        ->call('prepareIssue');

    controlNote($order2, 'pending');

    $component->call('issueSelected');

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 1 && $batch->jobs->first()->orderId === $order1->id);
});

test('selecionar todos do filtro pega só pedidos emitíveis', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();

    $free = controlOrder($company, $branch, 'CTL-1');
    $failed = controlOrder($company, $branch, 'CTL-2');
    controlNote($failed, 'error');
    controlNote(controlOrder($company, $branch, 'CTL-3'), 'pending');

    app()->instance('current.company', $company);

    $component = Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->set('situationFilter', 'all')
        ->call('selectAllFiltered');

    expect($component->get('selected'))->toEqualCanonicalizing([$free->id, $failed->id]);
});

test('emitir um pedido pela linha enfileira o job e recusa pedido com nota ativa', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();

    $order = controlOrder($company, $branch, 'CTL-1');
    $withNote = controlOrder($company, $branch, 'CTL-2');
    controlNote($withNote, 'authorized');

    app()->instance('current.company', $company);
    Bus::fake();

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->call('openIssueOne', $order->id)
        ->assertSet('showIssueOneModal', true)
        ->assertSee('Emitir nota fiscal')
        ->call('issueOne')
        ->assertSet('showIssueOneModal', false)
        ->call('openIssueOne', $withNote->id)
        ->assertSet('showIssueOneModal', false)
        ->call('issueOne');

    Bus::assertDispatchedTimes(IssueFiscalNote::class, 1);
    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
});

test('modal da linha mostra a última falha e o aviso de duplicidade antes de reemitir', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();

    $order = controlOrder($company, $branch, 'CTL-539');
    controlNote($order, 'rejected')->update(['data' => [
        'error_message' => 'Duplicidade de NF-e, com diferenca na Chave de Acesso.',
        'raw_response' => ['status_sefaz' => '539'],
    ]]);

    app()->instance('current.company', $company);
    Bus::fake();

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->call('openIssueOne', $order->id)
        ->assertSee('Reemitir nota fiscal')
        ->assertSee('Duplicidade de NF-e, com diferenca na Chave de Acesso.')
        ->assertSee('A SEFAZ já tem uma nota com esta série e número.')
        ->call('closeIssueOneModal')
        ->assertSet('issueOneOrderId', null);

    Bus::assertNothingDispatched();
});

test('modal da linha mostra a pendência da pré-checagem e não deixa emitir', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();
    CompanyFiscalConfig::withoutGlobalScopes()->where('company_id', $company->id)->update(['enabled' => false]);

    $order = controlOrder($company, $branch, 'CTL-PEND');

    app()->instance('current.company', $company);
    Bus::fake();

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->call('openIssueOne', $order->id)
        ->assertSet('issueOneBlockedReason', 'Configuração fiscal não habilitada para esta filial.')
        ->assertSee('Configuração fiscal não habilitada para esta filial.')
        ->assertDontSeeHtml('wire:click="issueOne"')
        ->call('issueOne');

    Bus::assertNothingDispatched();
});

test('pedido do modal da linha não pode ser trocado pelo navegador', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();
    $order = controlOrder($company, $branch, 'CTL-LOCK');

    app()->instance('current.company', $company);

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->set('issueOneOrderId', $order->id);
})->throws(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

test('gerente de filial vê só a própria filial e não emite sem fiscal.issue', function () {
    ['company' => $company, 'branch' => $branch] = controlCompany();
    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Filial 2', 'address' => 'Rua B', 'number' => '2',
        'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'cep' => '01001000',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);

    $mine = controlOrder($company, $branch, 'CTL-MINHA');
    controlOrder($company, $otherBranch, 'CTL-OUTRA');

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);

    app()->instance('current.company', $company);
    app()->instance('current.branch', $branch);
    Bus::fake();

    Livewire::actingAs($manager)
        ->test(IssueControl::class)
        ->assertSet('canIssue', false)
        ->assertSee('CTL-MINHA')
        ->assertDontSee('CTL-OUTRA')
        ->set('selected', [$mine->id])
        ->call('prepareIssue')
        ->assertForbidden();

    Bus::assertNothingDispatched();
});

test('tela exige o módulo fiscal contratado', function () {
    ['company' => $company, 'admin' => $admin] = controlCompany(fiscalEnabled: false);

    app()->instance('current.company', $company);

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->assertForbidden();
});

test('pré-checagem avisa quando há pedidos de dias anteriores', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();

    $old = controlOrder($company, $branch, 'CTL-ONTEM');
    $old->forceFill(['created_at' => now()->subDays(2)])->save();

    app()->instance('current.company', $company);

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->set('selected', [$old->id])
        ->call('prepareIssue')
        ->assertSet('confirmSummary.older', 1)
        ->assertSee('data de hoje');
});

test('nota rejeitada avisa o PDV pelo canal do pedido', function () {
    ['company' => $company, 'branch' => $branch] = controlCompany();
    app()->instance('current.company', $company);

    $order = controlOrder($company, $branch, 'CTL-1');
    $note = controlNote($order, 'pending');

    Event::fake([FiscalNoteFailed::class]);

    $note->update(['status' => 'rejected', 'data' => ['error_message' => 'Rejeição 778: NCM inexistente']]);

    Event::assertDispatched(FiscalNoteFailed::class, fn ($event) => $event->orderId === $order->id
        && $event->reason === 'Rejeição 778: NCM inexistente'
        && $event->broadcastOn()[0]->name === 'private-order.'.$order->id);
});

test('falha definitiva do job avisa o PDV; em lote não cria uma notificação por pedido', function () {
    ['company' => $company, 'branch' => $branch] = controlCompany();
    app()->instance('current.company', $company);

    $single = controlOrder($company, $branch, 'CTL-1');
    $batched = controlOrder($company, $branch, 'CTL-2');

    Event::fake([FiscalNoteFailed::class]);

    (new IssueFiscalNote($single->id))->failed(new RuntimeException('Configuração fiscal não habilitada para esta filial.'));
    (new IssueFiscalNote($batched->id))->withBatchId('batch-123')->failed(new RuntimeException('Configuração fiscal não habilitada para esta filial.'));

    Event::assertDispatchedTimes(FiscalNoteFailed::class, 2);
    expect(CompanyNotification::where('company_id', $company->id)->where('type', 'fiscal_note_failed')->count())->toBe(1)
        ->and($batched->fresh()->fiscal_error)->toBe('Configuração fiscal não habilitada para esta filial.');
});

test('PDV consulta a situação da nota depois de assinar o canal', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();
    ['company' => $other, 'branch' => $otherBranch] = controlCompany('Outra');
    app()->instance('current.company', $company);

    $authorized = controlOrder($company, $branch, 'CTL-1');
    controlNote($authorized, 'authorized');
    $failed = controlOrder($company, $branch, 'CTL-2', ['fiscal_error' => 'Token do provedor fiscal não configurado.']);
    $waiting = controlOrder($company, $branch, 'CTL-3');
    $foreign = controlOrder($other, $otherBranch, 'OUT-1');

    $this->actingAs($admin);

    $this->getJson(route('admin.pdv.fiscal-note-status', $authorized))
        ->assertOk()->assertJson(['status' => 'authorized', 'model' => '65', 'reason' => null]);
    $this->getJson(route('admin.pdv.fiscal-note-status', $failed))
        ->assertOk()->assertJson(['status' => 'error', 'reason' => 'Token do provedor fiscal não configurado.']);
    $this->getJson(route('admin.pdv.fiscal-note-status', $waiting))
        ->assertOk()->assertJson(['status' => 'none']);
    $this->getJson(route('admin.pdv.fiscal-note-status', $foreign->id))
        ->assertNotFound();
});

test('lote roda de ponta a ponta: emite as notas e deixa uma notificação de resumo', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = controlCompany();

    $order1 = controlOrder($company, $branch, 'CTL-1');
    $order2 = controlOrder($company, $branch, 'CTL-2');

    app()->instance('current.company', $company);
    Http::fake(['*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200)]);

    Livewire::actingAs($admin)
        ->test(IssueControl::class)
        ->set('selected', [$order1->id, $order2->id])
        ->call('prepareIssue')
        ->call('issueSelected')
        ->assertSee('Lote concluído');

    expect(FiscalNote::withoutGlobalScopes()->whereIn('order_id', [$order1->id, $order2->id])->where('status', 'authorized')->count())->toBe(2)
        ->and(CompanyNotification::where('company_id', $company->id)->where('type', 'fiscal_batch_finished')->sole()->subtitle)->toBe('2 autorizada(s).');
});

test('resumo do lote conta rejeição da SEFAZ e falha antes do envio, que não aparecem em failedJobs', function () {
    ['company' => $company, 'branch' => $branch] = controlCompany();
    app()->instance('current.company', $company);

    controlNote($authorized = controlOrder($company, $branch, 'CTL-1'), 'authorized');
    controlNote($rejected = controlOrder($company, $branch, 'CTL-2'), 'rejected');
    $notSent = controlOrder($company, $branch, 'CTL-3', ['fiscal_error' => 'Dados fiscais pendentes']);

    $outcome = app(\App\Services\Fiscal\FiscalNoteService::class)->batchOutcome($company->id, [$authorized->id, $rejected->id, $notSent->id]);

    expect($outcome)->toBe(['authorized' => 1, 'rejected' => 1, 'pending' => 0, 'not_sent' => 1])
        ->and(\App\Services\Fiscal\FiscalNoteService::describeBatchOutcome($outcome))
        ->toBe('1 autorizada(s), 1 rejeitada(s)/com erro, 1 não enviada(s) por pendência');
});
