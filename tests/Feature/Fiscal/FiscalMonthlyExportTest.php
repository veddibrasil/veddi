<?php

use App\Jobs\BuildFiscalMonthlyArchive;
use App\Jobs\PrepareFiscalExportDocument;
use App\Livewire\Admin\Fiscal\MonthlyExport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalExport;
use App\Models\FiscalNote;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Fiscal\FiscalMonthlyExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function monthlyExportContext(): array
{
    $company = Company::create(['name' => 'Empresa XML', 'slug' => 'xml-'.uniqid(), 'order_prefix' => 'XML', 'active' => true, 'status' => 'ACTIVE', 'fiscal_notes_enabled' => true]);
    app()->instance('current.company', $company);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Matriz', 'address' => 'Rua A', 'city' => 'Apucarana', 'state' => 'PR', 'active' => true]);
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'company_admin']);
    UserPermission::create(['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => Permission::firstOrCreate(['name' => 'fiscal.view'], ['group' => 'fiscal', 'label' => 'Ver fiscal'])->id, 'granted' => true]);
    Storage::fake('local');
    Http::preventStrayRequests();

    return compact('company', 'branch', 'user');
}

function monthlyExportNote(array $context, array $attributes = []): FiscalNote
{
    $attributes = array_replace_recursive([
        'company_id' => $context['company']->id, 'branch_id' => $context['branch']->id, 'model' => '65',
        'status' => 'authorized', 'environment' => 'producao', 'issuer_cnpj' => '52997689000174',
        'access_key' => '41261052997689000174650010000000021336013563',
        'data' => ['request_payload' => ['data_emissao' => '2026-10-15T10:00:00-03:00']],
    ], $attributes);
    $attributes['access_key'] = substr_replace($attributes['access_key'], $attributes['model'], 20, 2);
    $note = FiscalNote::create($attributes);
    Storage::disk('local')->put('fiscal/'.$note->company_id.'/'.$note->id.'/xml.xml', monthlyExportXml($note));

    return $note;
}

function monthlyExportXml(FiscalNote $note): string
{
    $date = $note->data['request_payload']['data_emissao'];
    $environment = $note->environment === 'producao' ? '1' : '2';

    return '<?xml version="1.0" encoding="UTF-8"?><nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.$note->access_key.'"><ide><mod>'.$note->model.'</mod><nNF>2</nNF><serie>1</serie><tpAmb>'.$environment.'</tpAmb><dhEmi>'.$date.'</dhEmi></ide><emit><CNPJ>'.$note->issuer_cnpj.'</CNPJ></emit><total><ICMSTot><vNF>123.45</vNF></ICMSTot></total></infNFe></NFe><protNFe><infProt><chNFe>'.$note->access_key.'</chNFe><cStat>100</cStat></infProt></protNFe></nfeProc>';
}

function monthlyExportRecord(array $context, array $ids, array $attributes = []): FiscalExport
{
    return FiscalExport::create($attributes + ['company_id' => $context['company']->id, 'user_id' => $context['user']->id, 'month' => '2026-10', 'environment' => 'producao', 'note_ids' => $ids]);
}

function monthlyExportContents(FiscalExport $export): array
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($export->fresh()->path)))->toBeTrue();
    $entries = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entries[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
    }
    $zip->close();

    return $entries;
}

test('gera ZIP mensal real com NF-e NFC-e canceladas e CSV sem misturar empresa ambiente ou mês', function () {
    $context = monthlyExportContext();
    $authorized = monthlyExportNote($context);
    $cancelled = monthlyExportNote($context, ['model' => '55', 'status' => 'cancelled']);
    monthlyExportNote($context, ['environment' => 'homologacao']);
    monthlyExportNote($context, ['status' => 'pending']);
    monthlyExportNote($context, ['status' => 'rejected']);
    monthlyExportNote($context, ['data' => ['request_payload' => ['data_emissao' => '2026-11-01T00:00:00-03:00']]]);
    $other = Company::create(['name' => 'Outra', 'slug' => 'other-xml', 'order_prefix' => 'OTR', 'active' => true]);
    monthlyExportNote($context, ['company_id' => $other->id]);

    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', '2026-10')->call('generate')->assertHasNoErrors()->assertSee('Baixar ZIP');

    $export = FiscalExport::firstOrFail();
    expect($export->status)->toBe('ready')->and($export->documents_count)->toBe(2)->and($export->errors_count)->toBe(0)
        ->and($export->note_ids)->toBe([$authorized->id, $cancelled->id]);
    $entries = monthlyExportContents($export);
    expect($entries)->toHaveCount(4)->toHaveKeys(['relatorio.csv', 'LEIA-ME.txt'])
        ->and($entries['relatorio.csv'])->toContain('Cancelada', 'Autorizada', '123.45', $authorized->access_key)
        ->and(implode(' ', array_keys($entries)))->toContain('/55/cancelled/', '/65/authorized/');
    $this->actingAs($context['user'])->get(route('admin.fiscal.export', $export->id))->assertOk()->assertDownload($export->filename());
    Http::assertNothingSent();
});

test('usa data de emissão com limites inclusivo e exclusivo do mês e fuso local', function () {
    $context = monthlyExportContext();
    $first = monthlyExportNote($context, ['data' => ['request_payload' => ['data_emissao' => '2026-10-01T00:00:00-03:00']]]);
    $last = monthlyExportNote($context, ['data' => ['request_payload' => ['data_emissao' => '2026-11-01T02:59:59+00:00']]]);
    monthlyExportNote($context, ['data' => ['request_payload' => ['data_emissao' => '2026-10-01T02:59:59+00:00']]]);
    monthlyExportNote($context, ['data' => ['request_payload' => ['data_emissao' => '2026-11-01T00:00:00-03:00']]]);
    expect(app(FiscalMonthlyExportService::class)->noteIds($context['company']->id, '2026-10', 'producao', null))->toBe([$first->id, $last->id]);
});

test('lote incompleto informa XML ausente inválido ou de outra nota sem omitir a pendência', function (string $content) {
    $context = monthlyExportContext();
    monthlyExportNote($context);
    $invalid = monthlyExportNote($context);
    Storage::disk('local')->put('fiscal/'.$invalid->company_id.'/'.$invalid->id.'/xml.xml', $content);
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', '2026-10')->call('generate')->assertSee('Lote incompleto');
    $export = FiscalExport::firstOrFail();
    expect($export->status)->toBe('partial')->and($export->documents_count)->toBe(1)->and($export->errors_count)->toBe(1);
    $entries = monthlyExportContents($export);
    expect($entries)->toHaveCount(3)->and($entries['relatorio.csv'])->toContain('XML indisponível ou inválido');
})->with(['html' => '<html>erro</html>', 'xml malformado' => '<nfeProc>', 'xml sem protocolo' => '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"/>', 'entidade externa' => '<!DOCTYPE nfeProc [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><nfeProc/>']);

test('XML indisponível no provedor gera relatório com pendência', function () {
    $context = monthlyExportContext();
    $note = monthlyExportNote($context);
    Storage::disk('local')->delete('fiscal/'.$note->company_id.'/'.$note->id.'/xml.xml');
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', '2026-10')->call('generate');
    $export = FiscalExport::firstOrFail();
    expect($export->status)->toBe('partial')->and($export->documents_count)->toBe(0)->and($export->errors_count)->toBe(1);
    expect(monthlyExportContents($export)['relatorio.csv'])->toContain('XML indisponível ou inválido');
});

test('competência vazia inválida futura ou sem notas não cria lote', function (string $month) {
    $context = monthlyExportContext();
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', $month)->call('generate')->assertHasErrors('month');
    expect(FiscalExport::count())->toBe(0);
})->with(['', '2026-13', '2026-10-01', '2099-01', '2026-09']);

test('geração enfileira um documento por job na fila low', function () {
    $context = monthlyExportContext();
    monthlyExportNote($context);
    Bus::fake();
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', '2026-10')->call('generate')->assertHasNoErrors()->assertSee('Preparando');
    Bus::assertBatched(fn ($batch) => count($batch->jobs) === 1 && $batch->jobs[0] instanceof PrepareFiscalExportDocument && $batch->jobs[0]->queue === 'low');
});

test('gerente não gera nem baixa lote de outra filial ou de todas as filiais', function () {
    $context = monthlyExportContext();
    $otherBranch = Branch::create(['company_id' => $context['company']->id, 'name' => 'Outra filial', 'address' => 'Rua B', 'city' => 'Apucarana', 'active' => true]);
    $context['user']->companies()->updateExistingPivot($context['company']->id, ['role' => 'branch_manager', 'branch_id' => $context['branch']->id]);
    monthlyExportNote($context);
    monthlyExportNote($context, ['branch_id' => $otherBranch->id]);
    $component = Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', '2026-10')->call('generate')->assertHasNoErrors();
    expect(FiscalExport::firstOrFail()->note_ids)->toHaveCount(1);
    $component->set('branchId', (string) $otherBranch->id)->call('generate')->assertForbidden();
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('branchId', '')->call('generate')->assertForbidden();
    foreach ([null, $otherBranch->id] as $branchId) {
        $export = monthlyExportRecord($context, [], ['branch_id' => $branchId]);
        $this->actingAs($context['user'])->get(route('admin.fiscal.export', $export->id))->assertForbidden();
    }
});

test('download bloqueia empresa diferente falta de permissão módulo desativado e lote não pronto', function () {
    $context = monthlyExportContext();
    $export = monthlyExportRecord($context, []);
    $this->actingAs($context['user'])->get(route('admin.fiscal.export', $export->id))->assertNotFound();
    $export->update(['status' => 'ready', 'path' => 'missing.zip']);
    $this->get(route('admin.fiscal.export', $export->id))->assertNotFound();
    $other = Company::create(['name' => 'Outra', 'slug' => 'other-download', 'order_prefix' => 'OTR', 'active' => true]);
    $export->update(['company_id' => $other->id]);
    $this->get(route('admin.fiscal.export', $export->id))->assertNotFound();
    $context['company']->update(['fiscal_notes_enabled' => false]);
    app()->instance('current.company', $context['company']->fresh());
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->assertForbidden();
    $this->get(route('admin.fiscal.export', $export->id))->assertForbidden();
});

test('worker mantém empresa original do contexto e registra XML falho', function () {
    $context = monthlyExportContext();
    $note = monthlyExportNote($context);
    $export = monthlyExportRecord($context, [$note->id]);
    $other = Company::create(['name' => 'Worker anterior', 'slug' => 'worker-context', 'order_prefix' => 'WRK', 'active' => true]);
    app()->instance('current.company', $other);
    (new PrepareFiscalExportDocument($export->id, $note->id))->handle(app(FiscalMonthlyExportService::class));
    expect(app('current.company')->id)->toBe($other->id);
    (new BuildFiscalMonthlyArchive($export->id))->handle(app(FiscalMonthlyExportService::class));
    expect($export->fresh()->status)->toBe('ready');
});

test('finalização registra job sem resultado como pendência e falha terminal sai de processamento', function () {
    $context = monthlyExportContext();
    $note = monthlyExportNote($context);
    $export = monthlyExportRecord($context, [$note->id]);
    (new BuildFiscalMonthlyArchive($export->id))->handle(app(FiscalMonthlyExportService::class));
    expect($export->fresh()->status)->toBe('partial')->and(monthlyExportContents($export)['relatorio.csv'])->toContain('Processamento do XML não concluído');
    $failed = monthlyExportRecord($context, [$note->id]);
    (new BuildFiscalMonthlyArchive($failed->id))->failed(new RuntimeException('Falha'));
    expect($failed->fresh()->status)->toBe('failed');
});

test('rejeita XML com chave ambiente emitente modelo ou competência divergente', function (string $field) {
    $context = monthlyExportContext();
    $note = monthlyExportNote($context);
    $export = monthlyExportRecord($context, [$note->id]);
    $xml = monthlyExportXml($note);
    $xml = match ($field) {
        'chave' => str_replace($note->access_key, str_repeat('1', 44), $xml),
        'ambiente' => str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', $xml),
        'emitente' => str_replace('<CNPJ>'.$note->issuer_cnpj.'</CNPJ>', '<CNPJ>../arquivo</CNPJ>', $xml),
        'modelo' => str_replace('<mod>65</mod>', '<mod>55</mod>', $xml),
        'mês' => str_replace('2026-10-15', '2026-11-15', $xml),
        'protocolo' => str_replace('<cStat>100</cStat>', '<cStat>302</cStat>', $xml),
    };
    Storage::disk('local')->put('fiscal/'.$note->company_id.'/'.$note->id.'/xml.xml', $xml);
    expect(fn () => app(FiscalMonthlyExportService::class)->document($note, $export))->toThrow(RuntimeException::class);
})->with(['chave', 'ambiente', 'emitente', 'modelo', 'mês', 'protocolo']);

test('ambiente legado desconhecido não some do lote e fica como pendência', function () {
    $context = monthlyExportContext();
    monthlyExportNote($context, ['environment' => null]);
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', '2026-10')->call('generate');
    expect(FiscalExport::firstOrFail()->status)->toBe('partial');
});

test('somente visualizar fiscal permite exportar e falta de permissão bloqueia geração e download', function () {
    $context = monthlyExportContext();
    monthlyExportNote($context);
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', '2026-10')->call('generate')->assertHasNoErrors();
    $export = FiscalExport::firstOrFail();
    UserPermission::where('user_id', $context['user']->id)->update(['granted' => false]);
    User::clearPermissionCache($context['user']->id, $context['company']->id);
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->assertForbidden();
    $this->actingAs($context['user'])->get(route('admin.fiscal.export', $export->id))->assertForbidden();
});

test('consulta de filiais e ambiente não permite parâmetros de outra empresa', function () {
    $context = monthlyExportContext();
    $other = Company::create(['name' => 'Outra', 'slug' => 'other-branch', 'order_prefix' => 'OTR', 'active' => true]);
    $branch = Branch::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Outra', 'address' => 'Rua B', 'city' => 'Apucarana', 'active' => true]);
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('branchId', (string) $branch->id)->call('generate')->assertHasErrors('branchId');
    Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('environment', 'qualquer')->call('generate')->assertHasErrors('environment');
    expect(FiscalExport::count())->toBe(0);
});

test('baixa XML não arquivado com credencial da filial e reutiliza arquivo privado', function () {
    $context = monthlyExportContext();
    $config = \App\Models\CompanyFiscalConfig::create([
        'company_id' => $context['company']->id, 'branch_id' => $context['branch']->id,
        'issuer_cnpj' => '52997689000174', 'enabled' => true, 'environment' => 'homologacao',
        'token_homologacao' => 'token-filial-exportacao', 'crt' => 1,
    ]);
    $note = monthlyExportNote($context, [
        'fiscal_config_id' => $config->id, 'environment' => 'homologacao',
        'data' => ['xml_url' => 'https://homologacao.focusnfe.com.br/arquivo-mensal.xml'],
    ]);
    Storage::disk('local')->delete('fiscal/'.$note->company_id.'/'.$note->id.'/xml.xml');
    Http::fake(['https://homologacao.focusnfe.com.br/arquivo-mensal.xml' => Http::response(monthlyExportXml($note))]);
    $component = Livewire::actingAs($context['user'])->test(MonthlyExport::class)->set('month', '2026-10')->set('environment', 'homologacao');
    $component->call('generate')->assertHasNoErrors();
    expect(FiscalExport::firstOrFail()->status)->toBe('ready');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('token-filial-exportacao:')));
    $component->call('generate')->assertHasNoErrors();
    Http::assertSentCount(1);
});

test('recupera lote interrompido sem depender de worker ou recriar lote', function () {
    $context = monthlyExportContext();
    $first = monthlyExportNote($context);
    $second = monthlyExportNote($context);
    $export = monthlyExportRecord($context, [$first->id, $second->id]);
    $service = app(FiscalMonthlyExportService::class);
    (new PrepareFiscalExportDocument($export->id, $first->id))->handle($service);
    $resultPath = $export->directory().'/results/'.$first->id.'.json';
    $result = Storage::disk('local')->get($resultPath);
    Bus::fake();

    $this->artisan('fiscal:resume-export', ['export' => $export->id])->assertSuccessful();

    expect($export->fresh()->status)->toBe('ready')->and($export->fresh()->documents_count)->toBe(2)
        ->and(FiscalExport::count())->toBe(1)->and(Storage::disk('local')->get($resultPath))->toBe($result);
    expect(monthlyExportContents($export))->toHaveCount(4);
    Bus::assertNothingDispatched();
});

test('retomada cancela batch antigo e documentos cancelados não continuam processando', function () {
    $context = monthlyExportContext();
    $note = monthlyExportNote($context);
    $export = monthlyExportRecord($context, [$note->id]);
    $batch = app(\Illuminate\Bus\BatchRepository::class)->store(
        Bus::batch([new PrepareFiscalExportDocument($export->id, $note->id)])->name('Lote mensal de XML #'.$export->id)
    );
    $this->artisan('fiscal:resume-export', ['export' => $export->id])->assertSuccessful();
    expect(Bus::findBatch($batch->id)->cancelled())->toBeTrue()->and($export->fresh()->status)->toBe('ready');
    $path = $export->directory().'/results/'.$note->id.'.json';
    Storage::disk('local')->delete($path);
    $export->update(['status' => 'processing']);
    (new PrepareFiscalExportDocument($export->id, $note->id))->withBatchId($batch->id)->handle(app(FiscalMonthlyExportService::class));
    Storage::disk('local')->assertMissing($path);
});

test('retomada trata lote inexistente concluído e já em recuperação', function () {
    $context = monthlyExportContext();
    $this->artisan('fiscal:resume-export', ['export' => 99999])->assertFailed();
    $export = monthlyExportRecord($context, [], ['status' => 'ready']);
    $this->artisan('fiscal:resume-export', ['export' => $export->id])->assertSuccessful();
    $export->update(['status' => 'processing']);
    $lock = \Illuminate\Support\Facades\Cache::lock('fiscal-export:resume:'.$export->id, 3600);
    $lock->get();
    try {
        $this->artisan('fiscal:resume-export', ['export' => $export->id])->assertFailed();
    } finally {
        $lock->release();
    }
});
