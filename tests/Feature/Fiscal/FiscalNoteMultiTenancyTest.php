<?php

use App\Models\Company;
use App\Models\FiscalNote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('FiscalNote isola notas por empresa via global scope', function () {
    $companyA = Company::create([
        'name' => 'Empresa A',
        'slug' => 'empresa-a-'.uniqid(),
        'order_prefix' => 'AAA',
        'active' => true,
        'status' => 'ACTIVE',
    ]);
    $companyB = Company::create([
        'name' => 'Empresa B',
        'slug' => 'empresa-b-'.uniqid(),
        'order_prefix' => 'BBB',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    FiscalNote::withoutGlobalScopes()->create([
        'company_id' => $companyA->id,
        'order_id' => null,
        'status' => 'authorized',
    ]);
    FiscalNote::withoutGlobalScopes()->create([
        'company_id' => $companyB->id,
        'order_id' => null,
        'status' => 'authorized',
    ]);

    app()->instance('current.company', $companyA);
    expect(FiscalNote::count())->toBe(1);
    expect(FiscalNote::first()->company_id)->toBe($companyA->id);

    app()->instance('current.company', $companyB);
    expect(FiscalNote::count())->toBe(1);
    expect(FiscalNote::first()->company_id)->toBe($companyB->id);
});
