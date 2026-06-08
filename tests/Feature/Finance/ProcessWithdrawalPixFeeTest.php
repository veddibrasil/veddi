<?php

use App\Jobs\ProcessWithdrawal;
use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Models\CompanyWithdrawal;
use App\Services\Payment\VindiService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('debits pix fee from wallet and transfers net amount', function () {
    $company = Company::create([
        'name' => 'Test Company',
        'slug' => 'test-company',
        'active' => true,
        'vindi_affiliate_token' => 'affiliate_tok_test',
    ]);

    CompanyWalletEntry::create([
        'company_id' => $company->id,
        'order_id' => null,
        'type' => 'credit',
        'amount' => 200.00,
        'description' => 'Seed',
        'reference' => 'seed',
    ]);

    $withdrawal = CompanyWithdrawal::create([
        'company_id' => $company->id,
        'amount' => 100.00,
        'pix_fee' => 0.50,
        'status' => 'pending',
        'payout_type' => 'PIX',
        'pix_key' => 'test-key',
        'pix_key_type' => 'EVP',
    ]);

    CompanyTransaction::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'order_id' => null,
        'payment_id' => null,
        'type' => 'pix',
        'status' => 'released',
        'value' => 100.00,
        'net_value' => 100.00,
        'payment_date' => now()->toDateString(),
        'release_date' => now()->toDateString(),
        'withdrawn' => false,
        'withdrawal_id' => $withdrawal->id,
        'description' => 'Reserved',
        'metadata' => [],
    ]);

    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('createTransfer')
        ->once()
        ->with(Mockery::on(function (array $payload) {
            return isset($payload['amount']) && (float) $payload['amount'] === 99.50;
        }))
        ->andReturn(['id' => 'transfer-1']);

    (new ProcessWithdrawal($withdrawal->id))->handle($vindi);

    $withdrawalEntry = CompanyWalletEntry::where('company_id', $company->id)
        ->where('type', 'withdrawal')
        ->where('description', 'Saque #'.$withdrawal->id)
        ->firstOrFail();

    $pixFeeEntry = CompanyWalletEntry::where('company_id', $company->id)
        ->where('type', 'pix_fee')
        ->where('description', 'Taxa PIX - Saque #'.$withdrawal->id)
        ->firstOrFail();

    expect((float) $withdrawalEntry->amount)->toBe(99.50);
    expect((float) $pixFeeEntry->amount)->toBe(0.50);
    expect(CompanyWalletEntry::balanceFor($company->id))->toBe(100.00);
});
