<?php

use App\Livewire\Admin\Fiscal\Notes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function reconcileTestContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Reconciliação',
        'slug' => 'empresa-reconciliacao-'.uniqid(),
        'order_prefix' => 'REC',
        'active' => true,
        'status' => 'ACTIVE',
        'owner_cpf_cnpj' => '12345678000100',
        'fiscal_notes_enabled' => true,
    ]);

    config(['fiscal.focus_nfe.token' => 'token_test']);

    app()->instance('current.company', $company);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente',
        'phone' => '11999990001',
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'is_default' => true,
        'enabled' => true,
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 10.00,
        'delivery_fee' => 0,
        'total' => 10.00,
        'fee' => 0,
        'net_value' => 10.00,
        'status' => 'paid',
        'payment_method' => 'pix',
        'order_type' => 'pickup',
    ]);

    $note = FiscalNote::create([
        'company_id' => $company->id,
        'order_id' => $order->id,
        'status' => 'error',
        'provider_reference' => 'ref_reconcile_001',
        'data' => ['error_message' => 'Timeout na comunicação com a Focus NFe'],
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $permission = Permission::firstOrCreate(
        ['name' => 'fiscal.issue'],
        ['group' => 'fiscal', 'label' => 'Emitir notas fiscais'],
    );

    UserPermission::create([
        'user_id' => $admin->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);

    $viewPermission = Permission::firstOrCreate(
        ['name' => 'fiscal.view'],
        ['group' => 'fiscal', 'label' => 'Ver notas fiscais'],
    );

    UserPermission::create([
        'user_id' => $admin->id,
        'company_id' => $company->id,
        'permission_id' => $viewPermission->id,
        'granted' => true,
    ]);

    return compact('company', 'order', 'note', 'admin');
}

test('FiscalNoteService::reconcile atualiza nota "error" para authorized quando Focus confirma que a tentativa anterior foi aceita', function () {
    ['note' => $note] = reconcileTestContext();

    Http::fake([
        '*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200),
    ]);

    $updated = app(FiscalNoteService::class)->reconcile($note);

    expect($updated->status)->toBe('authorized');
    expect($updated->access_key)->toBe(str_repeat('1', 44));
});

test('FiscalNoteService::reconcile mantém nota "error" quando Focus não reconhece a referência', function () {
    ['note' => $note] = reconcileTestContext();

    Http::fake([
        '*' => Http::response(['status' => 'nao_encontrado'], 404),
    ]);

    $updated = app(FiscalNoteService::class)->reconcile($note);

    expect($updated->status)->toBe('error');
});

test('reemitir NFC-e reconcilia antes: se a Focus já processou a tentativa anterior, não reemite e apenas atualiza o status', function () {
    ['note' => $note, 'admin' => $admin] = reconcileTestContext();

    Queue::fake();

    Http::fake([
        '*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('2', 44)], 200),
    ]);

    Livewire::actingAs($admin)
        ->test(Notes::class)
        ->call('openReissueModal', $note->id)
        ->call('confirmReissue');

    Queue::assertNotPushed(\App\Jobs\IssueFiscalNote::class);
    expect($note->fresh()->status)->toBe('authorized');
});

test('reemitir NFC-e prossegue quando a reconciliação confirma que a tentativa anterior de fato falhou', function () {
    ['note' => $note, 'admin' => $admin] = reconcileTestContext();

    Queue::fake();

    Http::fake([
        '*' => Http::response(['status' => 'nao_encontrado'], 404),
    ]);

    Livewire::actingAs($admin)
        ->test(Notes::class)
        ->call('openReissueModal', $note->id)
        ->call('confirmReissue');

    Queue::assertPushed(\App\Jobs\IssueFiscalNote::class, fn ($job) => $job->orderId === $note->order_id);
    expect($note->fresh()->status)->toBe('error');
});

test('reemitir abre modal com o motivo da falha e o aviso de duplicidade, sem enfileirar nada', function () {
    ['note' => $note, 'admin' => $admin] = reconcileTestContext();
    $note->update(['status' => 'rejected', 'data' => [
        'error_message' => 'Duplicidade de NF-e, com diferenca na Chave de Acesso.',
        'raw_response' => ['status_sefaz' => '539'],
    ]]);

    Queue::fake();

    Livewire::actingAs($admin)
        ->test(Notes::class)
        ->call('openReissueModal', $note->id)
        ->assertSet('showReissueModal', true)
        ->assertSee('Reemitir nota fiscal')
        ->assertSee('Duplicidade de NF-e, com diferenca na Chave de Acesso.')
        ->assertSee('A SEFAZ já tem uma nota com esta série e número.')
        ->call('closeReissueModal')
        ->assertSet('showReissueModal', false)
        ->assertSet('reissueNoteId', null);

    Queue::assertNotPushed(\App\Jobs\IssueFiscalNote::class);
});

test('modal de reemissão não abre para nota autorizada nem para nota de outra empresa', function () {
    ['company' => $company, 'note' => $note, 'admin' => $admin] = reconcileTestContext();
    $note->update(['status' => 'authorized']);

    $other = reconcileTestContext()['note'];
    app()->instance('current.company', $company);

    Livewire::actingAs($admin)
        ->test(Notes::class)
        ->call('openReissueModal', $note->id)
        ->assertSet('showReissueModal', false)
        ->call('openReissueModal', $other->id)
        ->assertSet('showReissueModal', false)
        ->assertSet('reissueNoteId', null);
});

test('id da nota no modal de reemissão não pode ser trocado pelo navegador', function () {
    ['note' => $note, 'admin' => $admin] = reconcileTestContext();

    Livewire::actingAs($admin)
        ->test(Notes::class)
        ->set('reissueNoteId', $note->id);
})->throws(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
