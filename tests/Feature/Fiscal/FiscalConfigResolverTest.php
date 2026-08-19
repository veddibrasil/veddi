<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Services\Fiscal\FiscalConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resolverTestCompanyWithBranches(int $branchCount): array
{
    $company = Company::create([
        'name' => 'Empresa Resolver',
        'slug' => 'empresa-resolver-'.uniqid(),
        'order_prefix' => 'RES',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => true,
    ]);

    $branches = collect(range(1, $branchCount))->map(fn (int $i) => Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => "Filial {$i}",
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]));

    return [$company, $branches];
}

test('forBranch resolve pela config da própria filial quando existe', function () {
    [$company, $branches] = resolverTestCompanyWithBranches(2);
    [$branchA, $branchB] = $branches;

    $configA = CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'branch_id' => $branchA->id,
        'is_default' => true,
        'enabled' => true,
        'nfce_serie' => 1,
    ]);

    $configB = CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'branch_id' => $branchB->id,
        'is_default' => false,
        'enabled' => true,
        'nfce_serie' => 2,
    ]);

    $resolver = app(FiscalConfigResolver::class);

    expect($resolver->forBranch($company, $branchA->id)->id)->toBe($configA->id);
    expect($resolver->forBranch($company, $branchB->id)->id)->toBe($configB->id);
});

test('forBranch cai na config default quando a filial não tem config própria', function () {
    [$company, $branches] = resolverTestCompanyWithBranches(2);
    [$branchA, $branchB] = $branches;

    $default = CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'branch_id' => $branchA->id,
        'is_default' => true,
        'enabled' => true,
    ]);

    // Filial B nunca teve config própria salva.
    expect(app(FiscalConfigResolver::class)->forBranch($company, $branchB->id)->id)->toBe($default->id);
});

test('forBranch retorna null quando não há config alguma pra empresa', function () {
    [$company, $branches] = resolverTestCompanyWithBranches(1);

    expect(app(FiscalConfigResolver::class)->forBranch($company, $branches->first()->id))->toBeNull();
});

test('forBranch sem branch_id informado cai direto na config default', function () {
    [$company, $branches] = resolverTestCompanyWithBranches(1);

    $default = CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'branch_id' => $branches->first()->id,
        'is_default' => true,
        'enabled' => true,
    ]);

    expect(app(FiscalConfigResolver::class)->forBranch($company, null)->id)->toBe($default->id);
});

test('forBranch nunca retorna config de outra empresa', function () {
    [$company, $branches] = resolverTestCompanyWithBranches(1);
    [$otherCompany, $otherBranches] = resolverTestCompanyWithBranches(1);

    CompanyFiscalConfig::create([
        'company_id' => $otherCompany->id,
        'branch_id' => $otherBranches->first()->id,
        'is_default' => true,
        'enabled' => true,
    ]);

    expect(app(FiscalConfigResolver::class)->forBranch($company, $branches->first()->id))->toBeNull();
});
