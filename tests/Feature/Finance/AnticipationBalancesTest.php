<?php

use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Services\Finance\AnticipationService;
use App\Services\Finance\BalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('anticipation moves from pending to available and debits fee entry', function () {
    config()->set('payments.credit_card.anticipation_d30', 0.10); // 10% fee for easy math

    $company = Company::create([
        'name' => 'Test Company',
        'slug' => 'test-company',
        'active' => true,
    ]);

    // Seed wallet balance so fee debit is visible (wallet credits happen at payment confirmation).
    CompanyWalletEntry::create([
        'company_id' => $company->id,
        'order_id' => null,
        'type' => 'credit',
        'amount' => 100.00,
        'description' => 'Seed credit',
        'reference' => 'seed',
    ]);

    $releaseDate = Carbon::today()->addDays(40)->toDateString(); // uses d30 bracket (default)

    $tx = CompanyTransaction::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'order_id' => null,
        'payment_id' => null,
        'type' => 'cartao',
        'status' => 'confirmed',
        'value' => 100.00,
        'net_value' => 100.00,
        'payment_date' => Carbon::today()->toDateString(),
        'release_date' => $releaseDate,
        'withdrawn' => false,
        'description' => 'Receivable',
        'metadata' => [],
    ]);

    /** @var BalanceService $balanceService */
    $balanceService = app(BalanceService::class);
    /** @var AnticipationService $anticipation */
    $anticipation = app(AnticipationService::class);

    $before = $balanceService->calculateBalance($company);
    expect($before['blocked_balance'])->toBe(100.00);
    expect($before['available_balance'])->toBe(0.00);

    $count = $anticipation->anticipateSelected($company, [$tx->id]);
    expect($count)->toBe(1);

    $after = $balanceService->calculateBalance($company);
    // Fee = 10% of 100 => 10.00, available becomes net_after_fee (90.00)
    expect($after['blocked_balance'])->toBe(0.00);
    expect($after['available_balance'])->toBe(90.00);

    $feeEntry = CompanyWalletEntry::where('company_id', $company->id)
        ->where('type', 'fee')
        ->latest()
        ->firstOrFail();

    expect((float) $feeEntry->amount)->toBe(10.00);
    expect(CompanyWalletEntry::balanceFor($company->id))->toBe(90.00);
});
