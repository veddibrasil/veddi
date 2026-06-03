<?php

use App\Helpers\Validation;
use App\Http\Controllers\AsaasSimulatePaymentController;
use App\Http\Controllers\AsaasWebhookController;
use App\Http\Controllers\FiscalWebhookController;
use App\Http\Controllers\RegisterCompanyController;
use App\Http\Controllers\StarkSimulatePaymentController;
use App\Http\Controllers\StarkWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('https://veddi.com.br');
});

Route::get('/health', \App\Http\Controllers\HealthController::class)->name('health');

// --- Onboarding público ---
Route::get('/cadastro', [RegisterCompanyController::class, 'create'])->name('register.create');
Route::post('/cadastro', [RegisterCompanyController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
Route::get('/cadastro/pendente', \App\Livewire\Onboarding\PendingPayment::class)->name('register.pending');

// --- Simulação de pagamento Asaas (somente APP_DEBUG=true) ---
Route::post('/dev/simulate/asaas-payment', AsaasSimulatePaymentController::class)->name('dev.simulate.asaas-payment');

// --- Simulação de pagamento Stark Bank PIX (somente APP_DEBUG=true) ---
Route::post('/dev/simulate/stark-payment', StarkSimulatePaymentController::class)->name('dev.simulate.stark-payment');
Route::get('/dev/simulate/stark-status/{paymentId}', function (string $paymentId) {
    abort_unless(config('app.debug'), 403);
    $stark = app(\App\Services\Payment\StarkService::class);
    $status = $stark->getBrcodePaymentStatus($paymentId);

    return response()->json(['payment_id' => $paymentId, 'status' => $status]);
})->name('dev.simulate.stark-status');

// --- Webhook Asaas (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
Route::post('/webhooks/asaas', AsaasWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhook.asaas');

// --- Webhook Stark Bank (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
Route::post('/webhooks/stark', StarkWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhook.stark');

// --- Webhook Focus NFe (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
Route::post('/webhooks/fiscal', FiscalWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhook.fiscal');

// --- API pública ---
Route::post('/api/validate-cpf', function (Request $request) {
    $cpf = $request->input('cpf', '');
    $valid = Validation::isValidCpf($cpf);

    return response()->json(['valid' => $valid]);
})->middleware('throttle:30,1')->name('api.validate-cpf');

// --- Documentação pública ---
Route::view('/docs', 'docs')->name('docs');

// --- Chat Público ---
Route::get('/{company}', \App\Livewire\Chat\OrderChat::class)->name('chat.company');

// --- Fechar popup de conclusão de pagamento ---
Route::get('/payment/complete', fn () => view('payment.complete'))->name('payment.complete');

// --- Painel Administrativo da Empresa ---

// Rota de assinatura acessível mesmo com empresa bloqueada
Route::middleware(['auth', 'verified', 'company.role:company_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/billing', \App\Livewire\Admin\Settings\BillingSettings::class)->name('billing');
        Route::get('/wallet', \App\Livewire\Admin\Wallet\CompanyWallet::class)->name('wallet');
    });

Route::middleware(['auth', 'verified', 'company.active'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // Dashboard e pedidos: company_admin + branch_manager
        Route::middleware('company.role:company_admin,branch_manager')->group(function () {
            Route::get('/dashboard', \App\Livewire\Admin\Dashboard::class)->name('dashboard');
            Route::get('/orders', \App\Livewire\Admin\Orders\Index::class)->name('orders.index');
            Route::get('/orders/report', \App\Livewire\Admin\Orders\Report::class)->name('orders.report');
            Route::get('/orders/report/pdf', \App\Http\Controllers\Admin\Orders\ReportPdfController::class)->name('orders.report.pdf');
            Route::get('/orders/{order}/receipt', \App\Http\Controllers\Admin\Orders\ReceiptPdfController::class)->name('orders.receipt');
            Route::get('/orders/{order}', \App\Livewire\Admin\Orders\Show::class)->name('orders.show');
            Route::get('/stock', \App\Livewire\Admin\Stock\Index::class)->name('stock.index');
        });

        // Cardápio: company_admin + branch_manager
        Route::middleware('company.role:company_admin,branch_manager')->group(function () {
            Route::get('/categories', \App\Livewire\Admin\Categories\Index::class)->name('categories.index');

            Route::get('/products', \App\Livewire\Admin\Products\Index::class)->name('products.index');
            Route::get('/products/create', \App\Livewire\Admin\Products\Form::class)->name('products.create');
            Route::get('/products/{product}/edit', \App\Livewire\Admin\Products\Form::class)->name('products.edit');
        });

        // Fiscal — company_admin + branch_manager (com permissão)
        Route::middleware('company.role:company_admin,branch_manager')->group(function () {
            Route::get('/fiscal/notas', \App\Livewire\Admin\Fiscal\Notes::class)->name('fiscal.notes');
        });

        // Gestão completa: só company_admin
        Route::middleware('company.role:company_admin')->group(function () {
            Route::get('/settings', \App\Livewire\Admin\Settings\CompanySettings::class)->name('settings');
            // Route::get('/settings/whatsapp', \App\Livewire\Admin\Settings\WhatsAppSettings::class)->name('settings.whatsapp');

            Route::get('/roles', \App\Livewire\Admin\Roles\Index::class)->name('roles.index');

            Route::get('/users', \App\Livewire\Admin\Users\Index::class)->name('users.index');
            Route::get('/users/{user}/permissions', \App\Livewire\Admin\Users\Permissions::class)->name('users.permissions');

        });

        Route::get('/branches', \App\Livewire\Admin\Branches\Index::class)->name('branches.index');
        Route::get('/branches/create', \App\Livewire\Admin\Branches\Form::class)->name('branches.create');
        Route::get('/branches/{branch}/edit', \App\Livewire\Admin\Branches\Form::class)->name('branches.edit');
        Route::get('/branches/{branch}/delivery', \App\Livewire\Admin\Branches\DeliverySettings::class)->name('branches.delivery');

        Route::get('/coupons', \App\Livewire\Admin\Coupons\Index::class)->name('coupons.index');

        // PDV — exige plano PDV + permissão pdv.operate (verificado no componente)
        Route::get('/pdv', \App\Livewire\Admin\Pdv\Terminal::class)->name('pdv');
        Route::get('/pdv/report', \App\Livewire\Admin\Pdv\Report::class)->name('pdv.report');

    });

// --- Super Admin Panel (sem CompanyScope, sem identify.company) ---
Route::middleware(['auth', 'verified', 'super.admin'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {
        Route::get('/companies', \App\Livewire\SuperAdmin\Companies\Index::class)->name('companies.index');
        Route::get('/companies/create', \App\Livewire\SuperAdmin\Companies\Form::class)->name('companies.create');
        Route::get('/companies/{company}/edit', \App\Livewire\SuperAdmin\Companies\Form::class)->name('companies.edit');
        Route::get('/users', \App\Livewire\SuperAdmin\Users\Index::class)->name('users.index');
        Route::get('/users/{user}/permissions', \App\Livewire\SuperAdmin\Permissions\UserPermissions::class)->name('users.permissions');
        Route::get('/permissions', \App\Livewire\SuperAdmin\Permissions\Index::class)->name('permissions.index');

        Route::get('/card-taxas', \App\Livewire\SuperAdmin\Card\index::class)->name('card.index');

        Route::get('/financeiro/rendimentos', \App\Livewire\SuperAdmin\Finance\Rendimentos::class)->name('finance.rendimentos');

        Route::post('/simulate/asaas-payment', AsaasSimulatePaymentController::class)->name('simulate.asaas-payment');
        Route::post('/simulate/stark-payment', StarkSimulatePaymentController::class)->name('simulate.stark-payment');
    });

// --- API Financeira (escrow/marketplace) ---
Route::middleware(['auth', 'verified', 'company.role:company_admin', 'throttle:60,1'])
    ->prefix('api/company')
    ->name('api.company.')
    ->group(function () {
        Route::get('/balance', [\App\Http\Controllers\Api\CompanyBalanceController::class, 'balance'])
            ->name('balance');
        Route::get('/balance/forecast', [\App\Http\Controllers\Api\CompanyBalanceController::class, 'forecast'])
            ->name('balance.forecast');
        Route::post('/withdraw', [\App\Http\Controllers\Api\CompanyBalanceController::class, 'withdraw'])
            ->middleware('throttle:10,1')
            ->name('withdraw');
        Route::post('/anticipate', [\App\Http\Controllers\Api\CompanyBalanceController::class, 'anticipate'])
            ->middleware('throttle:10,1')
            ->name('anticipate');
    });

require __DIR__.'/settings.php';
