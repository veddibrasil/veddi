<?php

use App\Jobs\ArchiveFiscalDocuments;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Fiscal\FiscalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const NFCE_DANFE_URL = 'https://homologacao.focusnfe.com.br/notas_fiscais_consumidor/NFe41261052997689000174650010000000021336013563.html';
const NFCE_XML_URL = 'https://homologacao.focusnfe.com.br/arquivos_development/52997689000174_1/202610/XMLs/41261052997689000174650010000000021336013563-nfe.xml';

function fiscalDocumentContext(string $model = '65', array $data = []): array
{
    $company = Company::create([
        'name' => 'Empresa Documentos',
        'slug' => 'empresa-documentos-'.uniqid(),
        'order_prefix' => 'DOC',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Matriz',
        'address' => 'Rua A',
        'city' => 'Apucarana',
        'state' => 'PR',
        'active' => true,
    ]);

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'enabled' => true,
        'is_default' => true,
        'environment' => 'homologacao',
        'crt' => 1,
        'token_homologacao' => 'tok-homolog',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente',
        'phone' => '43999990000',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 15,
        'total' => 15,
        'fee' => 0,
        'net_value' => 15,
        'status' => 'paid',
        'payment_method' => 'pix',
        'order_type' => 'pickup',
    ]);

    $note = FiscalNote::create([
        'company_id' => $company->id,
        'order_id' => $order->id,
        'branch_id' => $branch->id,
        'model' => $model,
        'environment' => 'homologacao',
        'status' => 'authorized',
        'data' => $data + ['danfe_url' => NFCE_DANFE_URL, 'xml_url' => NFCE_XML_URL],
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    foreach (['fiscal.view', 'orders.view'] as $permission) {
        UserPermission::create([
            'user_id' => $admin->id,
            'company_id' => $company->id,
            'permission_id' => Permission::firstOrCreate(['name' => $permission], ['group' => 'fiscal', 'label' => $permission])->id,
            'granted' => true,
        ]);
    }

    Http::preventStrayRequests();

    return compact('company', 'order', 'note', 'admin');
}

test('DANFE da NFC-e abre direto a página da Focus, sem baixar nem mandar credencial', function () {
    ['note' => $note, 'admin' => $admin] = fiscalDocumentContext();
    Http::fake();

    $this->actingAs($admin)
        ->get(route('admin.fiscal.document', ['note' => $note->id, 'type' => 'danfe']))
        ->assertRedirect(NFCE_DANFE_URL);

    Http::assertNothingSent();
});

test('DANFE da NFC-e com URL de outro host não redireciona e volta com aviso em vez de erro 500', function () {
    ['note' => $note, 'admin' => $admin] = fiscalDocumentContext(data: ['danfe_url' => 'https://external.example/danfe.html']);
    Http::fake();

    $this->actingAs($admin)
        ->from(route('admin.fiscal.notes'))
        ->get(route('admin.fiscal.document', ['note' => $note->id, 'type' => 'danfe']))
        ->assertRedirect(route('admin.fiscal.notes'))
        ->assertSessionHas('error');

    Http::assertNothingSent();
});

test('DANFE da NF-e que não vem em PDF volta com aviso em vez de erro 500', function () {
    ['note' => $note, 'admin' => $admin] = fiscalDocumentContext('55', ['danfe_url' => 'https://homologacao.focusnfe.com.br/arquivos/danfe.pdf']);
    Storage::fake('local');
    Http::fake(['*' => Http::response('<!doctype html><html></html>', 200)]);

    $this->actingAs($admin)
        ->from(route('admin.fiscal.notes'))
        ->get(route('admin.fiscal.document', ['note' => $note->id, 'type' => 'danfe']))
        ->assertRedirect(route('admin.fiscal.notes'))
        ->assertSessionHas('error');
});

test('imprimir DANFE da NF-e abre o PDF no navegador em vez de baixar', function () {
    ['note' => $note, 'admin' => $admin] = fiscalDocumentContext('55', ['danfe_url' => 'https://homologacao.focusnfe.com.br/arquivos/danfe.pdf']);
    Storage::fake('local');
    Http::fake(['*' => Http::response('%PDF-1.4 danfe', 200)]);

    $response = $this->actingAs($admin)
        ->get(route('admin.fiscal.document', ['note' => $note->id, 'type' => 'danfe', 'inline' => 1]))
        ->assertOk();

    expect($response->headers->get('content-disposition'))->toStartWith('inline');
});

test('arquivamento guarda só o XML da NFC-e, já que o DANFE dela é página HTML', function () {
    ['company' => $company, 'note' => $note] = fiscalDocumentContext();
    Storage::fake('local');
    Http::fake(['*' => Http::response('<?xml version="1.0"?><nfeProc/>', 200)]);

    (new ArchiveFiscalDocuments)->handle(app(FiscalDocumentService::class));

    Storage::disk('local')->assertExists("fiscal/{$company->id}/{$note->id}/xml.xml");
    Storage::disk('local')->assertMissing("fiscal/{$company->id}/{$note->id}/danfe.pdf");
    expect($note->fresh()->data['archived_at'] ?? null)->not->toBeNull();
    Http::assertSentCount(1);
});

test('botão Imprimir da NFC-e imprime o DANFE em bobina e aparece só para nota autorizada', function () {
    ['note' => $note, 'order' => $order] = fiscalDocumentContext();

    $render = fn () => view('livewire.admin.fiscal._print-button', ['note' => $note->fresh(), 'class' => ''])->render();

    expect($render())
        ->toContain("printFiscalNote({$order->id}, '65'")
        ->toContain(route('admin.orders.fiscal-receipt', $order))
        ->toContain('Imprimir');

    $note->update(['status' => 'cancelled']);

    expect(trim($render()))->toBe('');
});

test('botão Imprimir da NF-e manda o PDF do DANFE pra impressão do navegador', function () {
    ['note' => $note] = fiscalDocumentContext('55', ['danfe_url' => 'https://homologacao.focusnfe.com.br/arquivos/danfe.pdf']);

    $html = view('livewire.admin.fiscal._print-button', ['note' => $note, 'class' => ''])->render();

    expect($html)->toContain("'55'")->toContain('inline=1');
});

test('DANFE da NFC-e em bobina sai em PDF para a impressão do navegador', function () {
    ['note' => $note, 'order' => $order, 'admin' => $admin] = fiscalDocumentContext();
    $note->update(['access_key' => 'NFe'.str_repeat('4', 44), 'number' => '7', 'series' => 1, 'protocol' => '141260001774538']);

    $response = $this->actingAs($admin)
        ->get(route('admin.orders.fiscal-receipt', $order))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('DANFE em bobina traz chave, protocolo, QR Code e aviso de homologação', function () {
    ['note' => $note, 'order' => $order, 'company' => $company] = fiscalDocumentContext(data: ['raw_response' => [
        'url_consulta_nf' => 'http://www.fazenda.pr.gov.br/nfce/consulta',
    ]]);
    $note->update(['access_key' => 'NFe'.str_repeat('4', 44), 'number' => '7', 'series' => 1, 'protocol' => '141260001774538']);

    $html = view('livewire.admin.orders.receipt-fiscal-note', [
        'order' => $order->load(['items', 'branch']),
        'note' => $note->fresh(),
        'company' => $company,
        'qrCode' => 'data:image/svg+xml;base64,AAA',
    ])->render();

    expect($html)
        ->toContain('DANFE NFC-e')
        ->toContain(chunk_split(str_repeat('4', 44), 4, ' '))
        ->toContain('141260001774538')
        ->toContain('NFC-e n. 7 Serie 1')
        ->toContain('http://www.fazenda.pr.gov.br/nfce/consulta')
        ->toContain('data:image/svg+xml;base64,AAA')
        ->toContain('SEM VALOR FISCAL')
        ->toContain('CONSUMIDOR NAO IDENTIFICADO');
});

test('DANFE em bobina não existe para NF-e nem para nota não autorizada', function (string $model, string $status) {
    ['note' => $note, 'order' => $order, 'admin' => $admin] = fiscalDocumentContext($model);
    $note->update(['status' => $status]);

    $this->actingAs($admin)
        ->get(route('admin.orders.fiscal-receipt', $order))
        ->assertNotFound();
})->with([
    'NF-e autorizada' => ['55', 'authorized'],
    'NFC-e com erro' => ['65', 'error'],
]);
