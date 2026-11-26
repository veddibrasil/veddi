<?php

use App\Livewire\Admin\Fiscal\Notes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();

    $this->company = Company::create([
        'name' => 'Empresa Filtros', 'slug' => 'filtros-fiscal', 'order_prefix' => 'FLT',
        'active' => true, 'status' => 'ACTIVE', 'fiscal_notes_enabled' => true,
    ]);
    app()->instance('current.company', $this->company);

    $this->branch = Branch::create([
        'company_id' => $this->company->id, 'name' => 'Matriz', 'address' => 'Rua A', 'city' => 'Apucarana', 'state' => 'PR', 'active' => true,
    ]);

    $this->admin = User::factory()->create();
    $this->admin->companies()->attach($this->company->id, ['role' => 'company_admin']);

    $this->customerAna = customerFor($this->company, 'Ana Lima', '52998224725');
    $this->customerBruno = customerFor($this->company, 'Bruno Souza', '11144477735');
});

function filterContext(object $test): array
{
    return ['company' => $test->company, 'branch' => $test->branch];
}

function customerFor(Company $company, string $name, ?string $taxId): Customer
{
    return Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => $name, 'phone' => (string) random_int(4_300_000_000, 4_399_999_999), 'email' => str($name)->slug().'@teste.com',
        'tax_id' => $taxId, 'address' => 'Rua das Flores', 'number' => '10', 'neighborhood' => 'Centro',
        'city' => 'Apucarana', 'state' => 'PR', 'cep' => '86800-000',
    ]);
}

/** Pedido + nota. $recipient vai pra data.recipient (o que foi gravado na emissão). */
function filterNote(array $context, string $orderNumber, ?Customer $customer, ?array $recipient, array $attributes = []): FiscalNote
{
    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $context['company']->id,
        'customer_id' => $customer?->id,
        'branch_id' => $context['branch']->id,
        'order_number' => $orderNumber,
        'subtotal' => 10, 'delivery_fee' => 0, 'discount' => 0, 'total' => 10, 'fee' => 0, 'net_value' => 10,
        'status' => 'paid', 'notes' => '', 'payment_method' => 'pix', 'order_type' => 'pickup',
    ]);

    // O creating do Order sobrescreve order_number pela sequência da empresa: grava o número
    // escolhido depois, via query builder (não dispara o hook), para o teste ser determinístico.
    DB::table('orders')->where('id', $order->id)->update(['order_number' => $orderNumber]);

    return FiscalNote::create($attributes + [
        'company_id' => $context['company']->id,
        'branch_id' => $context['branch']->id,
        'order_id' => $order->id,
        'model' => '65',
        'status' => 'authorized',
        'environment' => 'homologacao',
        'issuer_cnpj' => '52997689000174',
        'access_key' => str_pad((string) random_int(1, 10 ** 10), 44, '0', STR_PAD_LEFT),
        'data' => $recipient === null ? [] : ['recipient' => $recipient],
    ]);
}

test('filtra pelo número do pedido sem trazer as outras notas', function () {
    filterNote(filterContext($this), 'FLT-2026-0001', $this->customerAna, ['document' => '52998224725', 'name' => 'Ana Lima']);
    filterNote(filterContext($this), 'FLT-2026-0002', $this->customerBruno, ['document' => '11144477735', 'name' => 'Bruno Souza']);

    Livewire::actingAs($this->admin)->test(Notes::class)
        ->set('orderNumber', 'FLT-2026-0002')
        ->assertSee('FLT-2026-0002')
        ->assertDontSee('FLT-2026-0001');
});

test('filtra pelo nome do destinatário gravado na nota', function () {
    filterNote(filterContext($this), 'FLT-2026-0001', $this->customerAna, ['document' => '', 'name' => 'Ana Maria Lima']);
    filterNote(filterContext($this), 'FLT-2026-0002', $this->customerBruno, ['document' => '', 'name' => 'Bruno Souza']);

    Livewire::actingAs($this->admin)->test(Notes::class)
        ->set('customerName', 'maria lima')
        ->assertSee('FLT-2026-0001')
        ->assertDontSee('FLT-2026-0002');
});

test('nome cai no cliente do pedido quando a nota não guarda destinatário', function () {
    filterNote(filterContext($this), 'FLT-2026-0001', $this->customerAna, null);
    filterNote(filterContext($this), 'FLT-2026-0002', $this->customerBruno, null);

    Livewire::actingAs($this->admin)->test(Notes::class)
        ->set('customerName', 'Bruno')
        ->assertSee('FLT-2026-0002')
        ->assertDontSee('FLT-2026-0001');
});

test('CPF digitado com pontuação acha a nota pelo documento gravado', function () {
    filterNote(filterContext($this), 'FLT-2026-0001', $this->customerAna, ['document' => '52998224725', 'name' => 'Ana Lima']);
    filterNote(filterContext($this), 'FLT-2026-0002', $this->customerBruno, ['document' => '11144477735', 'name' => 'Bruno Souza']);

    Livewire::actingAs($this->admin)->test(Notes::class)
        ->set('customerDocument', '529.982.247-25')
        ->assertSee('FLT-2026-0001')
        ->assertDontSee('FLT-2026-0002');
});

test('documento também acha nota sem destinatário gravado, pelo CPF do cliente', function () {
    filterNote(filterContext($this), 'FLT-2026-0001', $this->customerAna, null);
    filterNote(filterContext($this), 'FLT-2026-0002', $this->customerBruno, null);

    Livewire::actingAs($this->admin)->test(Notes::class)
        ->set('customerDocument', '111.444.777-35')
        ->assertSee('FLT-2026-0002')
        ->assertDontSee('FLT-2026-0001');
});

test('filtros combinam e limpar volta a listar tudo', function () {
    filterNote(filterContext($this), 'FLT-2026-0001', $this->customerAna, ['document' => '52998224725', 'name' => 'Ana Lima']);
    filterNote(filterContext($this), 'FLT-2026-0002', $this->customerAna, ['document' => '52998224725', 'name' => 'Ana Lima']);
    filterNote(filterContext($this), 'FLT-2026-0003', $this->customerBruno, ['document' => '11144477735', 'name' => 'Bruno Souza']);

    Livewire::actingAs($this->admin)->test(Notes::class)
        ->set('customerDocument', '52998224725')
        ->set('orderNumber', 'FLT-2026-0002')
        ->assertSee('FLT-2026-0002')
        ->assertDontSee('FLT-2026-0001')
        ->assertDontSee('FLT-2026-0003')
        ->call('clearFilters')
        ->assertSet('orderNumber', '')
        ->assertSet('customerDocument', '')
        ->assertSee('FLT-2026-0001')
        ->assertSee('FLT-2026-0003');
});

test('filtro de documento não mostra nota de outra empresa com o mesmo CPF', function () {
    $outra = Company::create([
        'name' => 'Outra Empresa', 'slug' => 'outra-fiscal', 'order_prefix' => 'OUT',
        'active' => true, 'status' => 'ACTIVE', 'fiscal_notes_enabled' => true,
    ]);
    $outraBranch = Branch::create(['company_id' => $outra->id, 'name' => 'Central', 'address' => 'Rua B', 'city' => 'Londrina', 'state' => 'PR', 'active' => true]);
    $outraCustomer = customerFor($outra, 'Ana Outra', '52998224725');
    filterNote(['company' => $outra, 'branch' => $outraBranch], 'OUT-2026-0001', $outraCustomer, ['document' => '52998224725', 'name' => 'Ana Outra']);
    filterNote(filterContext($this), 'FLT-2026-0001', $this->customerAna, ['document' => '52998224725', 'name' => 'Ana Lima']);

    app()->instance('current.company', $this->company);

    Livewire::actingAs($this->admin)->test(Notes::class)
        ->set('customerDocument', '52998224725')
        ->assertSee('FLT-2026-0001')
        ->assertDontSee('OUT-2026-0001');
});

test('botão de limpar só aparece com filtro ativo e a contagem acompanha o filtro', function () {
    filterNote(filterContext($this), 'FLT-2026-0001', $this->customerAna, ['document' => '52998224725', 'name' => 'Ana Lima']);
    filterNote(filterContext($this), 'FLT-2026-0002', $this->customerBruno, ['document' => '11144477735', 'name' => 'Bruno Souza']);

    Livewire::actingAs($this->admin)->test(Notes::class)
        ->assertDontSee('Limpar filtros')
        ->assertSee('2 notas encontradas')
        ->set('customerName', 'Bruno')
        ->assertSee('Limpar filtros')
        ->assertSee('1 nota encontrada')
        ->call('clearFilters')
        ->assertDontSee('Limpar filtros')
        ->assertSee('2 notas encontradas');
});
