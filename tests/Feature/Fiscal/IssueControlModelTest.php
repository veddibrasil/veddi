<?php

use App\Livewire\Admin\Fiscal\IssueControl;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function issueControlModelContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Controle Modelo', 'slug' => 'empresa-controle-modelo-'.uniqid(), 'order_prefix' => 'CMO',
        'active' => true, 'status' => 'ACTIVE', 'owner_cpf_cnpj' => '12345678000100', 'fiscal_notes_enabled' => true,
    ]);
    app()->instance('current.company', $company);
    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Filial Modelo', 'active' => true,
        'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
    $admin = User::factory()->create(['is_super_admin' => true]);

    return [$company, $branch, $admin];
}

function issueControlOrderWithNote(Company $company, Branch $branch, string $model): Order
{
    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'order_number' => 'M'.uniqid(),
        'status' => 'paid', 'channel' => 'chat', 'order_type' => 'delivery', 'total' => 20, 'subtotal' => 20,
    ]);
    FiscalNote::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'order_id' => $order->id,
        'model' => $model, 'status' => 'authorized', 'environment' => 'homologacao', 'provider' => 'focus_nfe',
    ]);

    return $order;
}

test('filtro de modelo mostra só as notas do modelo escolhido', function () {
    [$company, $branch, $admin] = issueControlModelContext();
    $nfce = issueControlOrderWithNote($company, $branch, '65');
    $nfe = issueControlOrderWithNote($company, $branch, '55');

    Livewire::actingAs($admin)->test(IssueControl::class)
        ->set('situationFilter', 'all')
        ->set('modelFilter', 'nfce')
        ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$nfce->id])
        ->set('situationFilter', 'all')
        ->set('modelFilter', 'nfe')
        ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$nfe->id]);
});

test('lote forçado em NFC-e com CNPJ bloqueado não vira NF-e', function () {
    [$company, $branch, $admin] = issueControlModelContext();
    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'order_number' => 'FORCA1',
        'status' => 'paid', 'channel' => 'chat', 'order_type' => 'delivery', 'total' => 20, 'subtotal' => 20,
        'fiscal_recipient' => ['document' => '11444777000161'],
    ]);

    Livewire::actingAs($admin)->test(IssueControl::class)
        ->set('selected', [$order->id])
        ->set('batchModel', 'nfce')
        ->call('prepareIssue')
        ->assertSet('readyOrderIds', [])
        ->assertSet('confirmSummary.nfce', 0);
});
