<?php

use App\Contracts\PrinterServiceInterface;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Printer\EscPosPrinterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-06 19:05:00', config('app.timezone')));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Pedido com um item de cozinha (Coxinha) e um de bar (Suco); endereço só quando $withAddress.
 *
 * @return array{company: Company, order: Order}
 */
function handoffOrder(array $attributes = [], bool $withAddress = true): array
{
    $company = Company::create([
        'name' => 'Lancheria Rodape',
        'slug' => 'rodape-'.uniqid(),
        'order_prefix' => 'ROD',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Centro',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Maria Souza',
        'phone' => '11988887777',
    ]);

    $kitchen = ProductCategory::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Salgados', 'station' => 'cozinha']);
    $bar = ProductCategory::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Bebidas', 'station' => 'bar']);
    $coxinha = Product::withoutGlobalScopes()->create(['company_id' => $company->id, 'product_category_id' => $kitchen->id, 'name' => 'Coxinha', 'price' => 20.00, 'active' => true]);
    $suco = Product::withoutGlobalScopes()->create(['company_id' => $company->id, 'product_category_id' => $bar->id, 'name' => 'Suco', 'price' => 20.00, 'active' => true]);

    $addressId = $withAddress
        ? Address::create(['line1' => 'Rua das Flores', 'number' => '123', 'complement' => 'Apto 4', 'neighborhood' => 'Centro', 'city' => 'São Paulo'])->id
        : null;

    $order = Order::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 40.00,
        'delivery_fee' => 5.00,
        'total' => 45.00,
        'fee' => 0,
        'net_value' => 45.00,
        'status' => 'preparing',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'delivery_address_id' => $addressId,
    ], $attributes));

    foreach ([$coxinha, $suco] as $product) {
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 20.00,
            'quantity' => 1,
            'subtotal' => 20.00,
        ]);
    }

    return ['company' => $company, 'order' => $order->fresh(['items.product.category', 'branch', 'customer', 'payment', 'deliveryAddressRecord'])];
}

function handoffTicket(Order $order, string $station): string
{
    return app(PrinterServiceInterface::class)->buildOrderReceipt($order, $station, $order->company);
}

test('cozinha: entrega do chat em dinheiro sai com tipo, horas, endereço, cobrança e troco', function () {
    ['order' => $order] = handoffOrder([
        'payment_method' => 'cash',
        'payment_on_delivery' => true,
        'notes' => 'Troco para R$ 50,00',
    ]);
    // chat_accepted_at não é fillable: quem grava é o aceite.
    $order->forceFill(['chat_accepted_at' => Carbon::parse('2026-11-06 19:02:00', config('app.timezone'))])->saveQuietly();

    $ticket = handoffTicket($order, 'cozinha');

    expect($ticket)->toContain("Lancheria Rodape\n")
        ->toContain('Coxinha')
        ->not->toContain('Suco')
        ->toContain('*** ENTREGA ***')
        ->toContain('Aceito 19:02')
        ->toContain('Impresso 19:05')
        ->toContain('Maria Souza')
        ->toContain('Tel: 11988887777')
        ->toContain('Rua das Flores, 123')
        ->toContain('Apto 4')
        ->toContain('Centro - Sao Paulo')
        ->toContain('COBRAR R$ 45,00 - DINHEIRO')
        ->toContain('TROCO PARA R$ 50,00 (LEVAR R$ 5,00)')
        ->toContain('Taxa de entrega: R$ 5,00')
        ->toContain('TOTAL: R$ 45,00');
});

test('cozinha: PIX online já pago sai como PAGO', function () {
    ['order' => $order] = handoffOrder(['payment_method' => 'pix', 'status' => 'preparing']);

    expect(handoffTicket($order, 'cozinha'))
        ->toContain('PAGO - PIX ONLINE')
        ->not->toContain('COBRAR');
});

test('cozinha: entrega do PDV a receber sai para cobrar', function () {
    ['order' => $order] = handoffOrder(['order_type' => 'pdv', 'delivery_type' => 'entrega', 'payment_method' => 'card', 'status' => 'awaiting_payment']);

    expect(handoffTicket($order, 'cozinha'))
        ->toContain('*** ENTREGA ***')
        ->toContain('COBRAR R$ 45,00 - CARTAO NA MAQUININHA');
});

test('cozinha: retirada sai sem endereço', function () {
    ['order' => $order] = handoffOrder(['order_type' => 'pickup', 'delivery_fee' => 0, 'total' => 40.00], withAddress: false);

    expect(handoffTicket($order, 'cozinha'))
        ->toContain('*** RETIRADA ***')
        ->toContain('Maria Souza')
        ->not->toContain('Rua das Flores')
        ->not->toContain('Taxa de entrega');
});

test('cozinha: pedido agendado leva o horário combinado', function () {
    ['order' => $order] = handoffOrder(['status' => 'scheduled', 'scheduled_at' => Carbon::parse('2026-11-06 20:30:00', config('app.timezone'))]);

    expect(handoffTicket($order, 'cozinha'))->toContain('AGENDADO PARA 06/11 20:30');
});

test('cozinha: balcão do PDV e comanda não ganham o rodapé', function () {
    ['order' => $counter] = handoffOrder(['order_type' => 'pdv', 'delivery_type' => 'balcao', 'status' => 'paid', 'payment_method' => 'cash'], withAddress: false);
    ['order' => $tab] = handoffOrder(['order_type' => 'pdv', 'is_open_tab' => true, 'table_label' => 'Mesa 7', 'status' => 'paid', 'payment_method' => 'cash'], withAddress: false);

    foreach ([$counter, $tab] as $order) {
        expect(handoffTicket($order, 'cozinha'))
            ->not->toContain('Impresso')
            ->not->toContain('TOTAL:')
            ->not->toContain('*** BALCAO ***');
    }
});

test('bar nunca recebe o rodapé', function () {
    ['order' => $order] = handoffOrder(['payment_method' => 'cash', 'payment_on_delivery' => true]);

    expect(handoffTicket($order, 'bar'))
        ->toContain('Suco')
        ->not->toContain('COBRAR')
        ->not->toContain('Impresso')
        ->not->toContain('Rua das Flores');
});

test('cupom de entrega pago mostra PAGO e total; não repete o endereço no topo', function () {
    ['order' => $order] = handoffOrder(['payment_method' => 'pix', 'status' => 'ready']);

    $ticket = handoffTicket($order, 'entrega');

    expect($ticket)->toContain('Coxinha')
        ->toContain('Suco')
        ->toContain('PAGO - PIX ONLINE')
        ->toContain('TOTAL: R$ 45,00')
        ->not->toContain('ENDERECO DE ENTREGA')
        ->and(substr_count($ticket, 'Rua das Flores, 123'))->toBe(1);
});

test('cupom de entrega do PDV já recebido não pede cobrança', function () {
    ['order' => $order] = handoffOrder(['order_type' => 'pdv', 'payment_method' => 'cash', 'status' => 'paid']);
    Payment::create([
        'order_id' => $order->id,
        'company_id' => $order->company_id,
        'amount' => 45.00,
        'status' => 'paid',
        'payment_gateway' => 'cash',
    ]);

    expect(handoffTicket($order->fresh(['items.product.category', 'branch', 'customer', 'payment', 'deliveryAddressRecord', 'payments']), 'entrega'))
        ->toContain('PAGO - DINHEIRO')
        ->not->toContain('COBRAR');
});

test('iFood entrega própria: bloco da plataforma no rodapé sem repetir o topo', function () {
    ['order' => $order] = handoffOrder([
        'channel' => 'ifood',
        'payment_method' => 'ifood',
        'status' => 'preparing',
        'external_metadata' => [
            'display_id' => '5396',
            'order_type' => 'DELIVERY',
            'delivered_by' => 'MERCHANT',
            'ifood_status' => 'CONFIRMED',
            'pickup_code' => '0951',
            'customer_phone' => '0800 700 3021',
            'phone_localizer' => '29369477',
            'delivery_observations' => 'Portão azul',
            'delivery_reference' => 'Ao lado da padaria',
            'prepaid_amount' => 0,
            'pending_amount' => 45,
            'payment_methods' => [['method' => 'CASH', 'prepaid' => false, 'value' => 45, 'change_for' => 50]],
        ],
    ]);

    $ticket = handoffTicket($order, 'cozinha');

    expect($ticket)->toContain('*** ENTREGA ***')
        ->toContain('Codigo de coleta: 0951')
        ->toContain('Tel. cliente: 0800 700 3021')
        ->toContain('COBRAR DO CLIENTE: R$ 45,00')
        ->toContain('Referencia: Ao lado da padaria')
        ->toContain('Rua das Flores, 123')
        ->toContain('TOTAL: R$ 45,00')
        ->not->toContain('Tel: ')
        ->and(substr_count($ticket, 'IFOOD #5396'))->toBe(1);
});

test('PDF da cozinha traz o mesmo rodapé do térmico', function () {
    ['order' => $order] = handoffOrder([
        'payment_method' => 'cash',
        'payment_on_delivery' => true,
        'notes' => 'Troco para R$ 50,00',
    ]);

    $html = view('livewire.admin.orders.receipt-station', [
        'order' => $order,
        'company' => $order->company,
        'station' => 'cozinha',
        'items' => $order->items->filter(fn ($item) => $item->matchesStation('cozinha'))->values(),
        'handoffFooter' => EscPosPrinterService::handoffFooterLines($order, 'cozinha'),
    ])->render();

    expect($html)->toContain('*** ENTREGA ***')
        ->toContain('Rua das Flores, 123')
        ->toContain('Centro - São Paulo')
        ->toContain('COBRAR R$ 45,00 - DINHEIRO')
        ->toContain('TROCO PARA R$ 50,00 (LEVAR R$ 5,00)')
        ->toContain('TOTAL: R$ 45,00');
});

test('PDF da cozinha de entrega abre pela rota', function () {
    ['order' => $order] = handoffOrder(['payment_method' => 'cash', 'payment_on_delivery' => true]);

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->get(route('admin.orders.receipt', ['order' => $order, 'station' => 'cozinha']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
