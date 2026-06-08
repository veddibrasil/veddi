<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Payment\VindiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function vindiSplitContext(bool $withAffiliateToken = true): array
{
    $company = Company::create([
        'name' => 'Empresa Split',
        'slug' => 'empresa-split',
        'order_prefix' => 'VSP',
        'active' => true,
        'pix_fee_absorbed_by_company' => true,
        'vindi_affiliate_token' => $withAffiliateToken ? 'affiliate_tok_xyz' : null,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Split',
        'address' => 'Rua S, 5',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Split',
        'phone' => '11666660001',
        'email' => 'split@teste.com',
        'tax_id' => '111.222.333-44',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 60.00,
        'total' => 60.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 0,
        'status' => 'pending',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'VSP-2026-00001',
    ]);

    return compact('company', 'branch', 'customer', 'order');
}

test('VindiService inclui affiliates no payload quando affiliate_token fornecido', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'transaction' => [
                    'token' => 'split_tok_001',
                    'status_name' => 'Aguardando Pagamento',
                    'pix_copy_paste' => '00020126abc',
                ],
            ], 200);
        },
    ]);

    $ctx = vindiSplitContext(withAffiliateToken: true);
    $service = app(VindiService::class);

    $service->createPixCharge(
        amount: 60.00,
        externalRef: (string) $ctx['order']->id,
        customer: $ctx['customer'],
        affiliateToken: 'affiliate_tok_xyz',
        affiliatePercentual: 99.01,
    );

    expect($capturedPayload)->not->toBeNull();
    assert(is_array($capturedPayload));
    expect(isset($capturedPayload['affiliates']))->toBeTrue()
        ->and($capturedPayload['affiliates'][0]['token_account'])->toBe('affiliate_tok_xyz')
        ->and($capturedPayload['affiliates'][0]['percentual'])->toBe('99.01');
});

test('VindiService não inclui affiliates quando sem affiliate_token', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'transaction' => [
                    'token' => 'nosplit_tok_002',
                    'status_name' => 'Aguardando Pagamento',
                    'pix_copy_paste' => '00020126def',
                ],
            ], 200);
        },
    ]);

    $ctx = vindiSplitContext(withAffiliateToken: false);
    $service = app(VindiService::class);

    $service->createPixCharge(
        amount: 60.00,
        externalRef: (string) $ctx['order']->id,
        customer: $ctx['customer'],
        affiliateToken: null,
    );

    expect($capturedPayload)->not->toBeNull();
    expect(isset($capturedPayload['affiliates']))->toBeFalse();
});
