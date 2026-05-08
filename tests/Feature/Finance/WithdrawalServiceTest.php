<?php

use App\Jobs\ProcessWithdrawal;
use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Services\Finance\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('does not over-debit when requested amount requires partial transaction', function () {
    Queue::fake();
    config()->set('payments.pix_withdrawal_fee', 0.50);

    $company = Company::create([
        'name' => 'Test Company',
        'slug' => 'test-company',
        'active' => true,
    ]);

    // Two released entries: 80 + 30. Withdraw 100 should not debit 110.
    CompanyTransaction::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'order_id' => null,
        'payment_id' => null,
        'type' => 'pix',
        'status' => 'released',
        'value' => 80.00,
        'net_value' => 80.00,
        'payment_date' => now()->toDateString(),
        'release_date' => now()->toDateString(),
        'withdrawn' => false,
        'description' => 'TX 80',
        'metadata' => [],
    ]);

    $tx30 = CompanyTransaction::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'order_id' => null,
        'payment_id' => null,
        'type' => 'pix',
        'status' => 'released',
        'value' => 30.00,
        'net_value' => 30.00,
        'payment_date' => now()->toDateString(),
        'release_date' => now()->toDateString(),
        'withdrawn' => false,
        'description' => 'TX 30',
        'metadata' => [],
    ]);

    /** @var WithdrawalService $service */
    $service = app(WithdrawalService::class);

    $withdrawal = $service->requestWithdrawal($company, 100.00, [
        'payout_type' => 'PIX',
        'pix_key' => 'test-key',
        'pix_key_type' => 'EVP',
    ]);

    $reservedSum = (float) CompanyTransaction::withoutGlobalScopes()
        ->where('withdrawal_id', $withdrawal->id)
        ->sum('net_value');

    expect($reservedSum)->toBe(100.00);

    $leftoverOriginal = CompanyTransaction::withoutGlobalScopes()->findOrFail($tx30->id);
    expect((float) $leftoverOriginal->net_value)->toBe(10.00);
    expect($leftoverOriginal->withdrawal_id)->toBeNull();

    Queue::assertPushed(ProcessWithdrawal::class);
});
