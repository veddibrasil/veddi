<?php

use App\Livewire\Admin\Settings\CompanySettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function trackingPixelsTestCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Pixels',
        'slug' => 'empresa-pixels-'.uniqid(),
        'order_prefix' => 'PXL',
        'active' => true,
        'status' => 'ACTIVE',
        'plan' => \App\Enums\Plan::Essencial,
    ])->fresh();
}

function trackingPixelsTestAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

test('empresa consegue salvar pixels de rastreamento válidos', function () {
    $company = trackingPixelsTestCompany();
    app()->instance('current.company', $company);
    $admin = trackingPixelsTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->set('facebook_pixel_id', '123456789012345')
        ->set('google_analytics_id', 'G-ABCD12WXYZ')
        ->set('google_ads_id', 'AW-123456789')
        ->call('save')
        ->assertHasNoErrors();

    $company->refresh();

    expect($company->facebook_pixel_id)->toBe('123456789012345');
    expect($company->google_analytics_id)->toBe('G-ABCD12WXYZ');
    expect($company->google_ads_id)->toBe('AW-123456789');
});

test('campos de pixel podem ficar em branco', function () {
    $company = trackingPixelsTestCompany();
    app()->instance('current.company', $company);
    $admin = trackingPixelsTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->set('facebook_pixel_id', '')
        ->set('google_analytics_id', '')
        ->set('google_ads_id', '')
        ->call('save')
        ->assertHasNoErrors();

    $company->refresh();

    expect($company->facebook_pixel_id)->toBeNull();
    expect($company->google_analytics_id)->toBeNull();
    expect($company->google_ads_id)->toBeNull();
});

test('rejeita facebook pixel id em formato inválido', function () {
    $company = trackingPixelsTestCompany();
    app()->instance('current.company', $company);
    $admin = trackingPixelsTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->set('facebook_pixel_id', "123'); alert(1); //")
        ->call('save')
        ->assertHasErrors(['facebook_pixel_id' => 'regex']);
});

test('rejeita google analytics id em formato inválido', function () {
    $company = trackingPixelsTestCompany();
    app()->instance('current.company', $company);
    $admin = trackingPixelsTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->set('google_analytics_id', '<script>alert(1)</script>')
        ->call('save')
        ->assertHasErrors(['google_analytics_id' => 'regex']);
});

test('rejeita google ads id em formato inválido', function () {
    $company = trackingPixelsTestCompany();
    app()->instance('current.company', $company);
    $admin = trackingPixelsTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->set('google_ads_id', 'not-a-valid-id')
        ->call('save')
        ->assertHasErrors(['google_ads_id' => 'regex']);
});
