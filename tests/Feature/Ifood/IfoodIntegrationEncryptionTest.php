<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\IfoodIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('access_token, refresh_token e authorization_code_verifier ficam criptografados em repouso', function () {
    $company = Company::create([
        'name' => 'Empresa Encryption',
        'slug' => 'empresa-encryption-'.uniqid(),
        'order_prefix' => 'ENC',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua X, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $integration = IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => 'merchant-enc',
        'access_token' => 'access-token-plain',
        'refresh_token' => 'refresh-token-plain',
        'authorization_code_verifier' => 'verifier-plain',
        'status' => 'active',
    ]);

    $raw = DB::table('ifood_integrations')->where('id', $integration->id)->first();

    expect($raw->access_token)->not->toBe('access-token-plain')
        ->and($raw->refresh_token)->not->toBe('refresh-token-plain')
        ->and($raw->authorization_code_verifier)->not->toBe('verifier-plain');

    $integration->refresh();

    expect($integration->access_token)->toBe('access-token-plain')
        ->and($integration->refresh_token)->toBe('refresh-token-plain')
        ->and($integration->authorization_code_verifier)->toBe('verifier-plain');
});
