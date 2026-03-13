<?php

use App\Helpers\Validation;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// --- API pública ---
Route::post('/api/validate-cpf', function (Request $request) {
    $cpf = $request->input('cpf', '');
    $valid = Validation::isValidCpf($cpf);

    return response()->json(['valid' => $valid]);
})->name('api.validate-cpf');

// --- Chat Público ---
Route::get('/{company}', \App\Livewire\Chat\OrderChat::class)->name('chat.company');

// --- Webhook (sem auth, sem CSRF, sem escopo de empresa) ---
Route::match(['get', 'post'], '/webhooks/abacatepay', [WebhookController::class, 'abacatepay'])
    ->name('webhook.abacatepay');

// --- Fechar popup de conclusão de pagamento ---
Route::get('/payment/complete', fn () => view('payment.complete'))->name('payment.complete');

// --- Painel Administrativo da Empresa ---
Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // Dashboard e pedidos: company_admin + branch_manager
        Route::middleware('company.role:company_admin,branch_manager')->group(function () {
            Route::get('/dashboard', \App\Livewire\Admin\Dashboard::class)->name('dashboard');
            Route::get('/orders', \App\Livewire\Admin\Orders\Index::class)->name('orders.index');
            Route::get('/orders/{order}', \App\Livewire\Admin\Orders\Show::class)->name('orders.show');
            Route::get('/stock', \App\Livewire\Admin\Stock\Index::class)->name('stock.index');
        });

        // Gestão completa: só company_admin
        Route::middleware('company.role:company_admin')->group(function () {
            Route::get('/branches', \App\Livewire\Admin\Branches\Index::class)->name('branches.index');
            Route::get('/branches/create', \App\Livewire\Admin\Branches\Form::class)->name('branches.create');
            Route::get('/branches/{branch}/edit', \App\Livewire\Admin\Branches\Form::class)->name('branches.edit');
            Route::get('/branches/{branch}/delivery', \App\Livewire\Admin\Branches\DeliverySettings::class)->name('branches.delivery');

            Route::get('/categories', \App\Livewire\Admin\Categories\Index::class)->name('categories.index');

            Route::get('/products', \App\Livewire\Admin\Products\Index::class)->name('products.index');
            Route::get('/products/create', \App\Livewire\Admin\Products\Form::class)->name('products.create');
            Route::get('/products/{product}/edit', \App\Livewire\Admin\Products\Form::class)->name('products.edit');

            Route::get('/settings', \App\Livewire\Admin\Settings\CompanySettings::class)->name('settings');

            Route::get('/roles', \App\Livewire\Admin\Roles\Index::class)->name('roles.index');

            Route::get('/users', \App\Livewire\Admin\Users\Index::class)->name('users.index');
            Route::get('/users/{user}/permissions', \App\Livewire\Admin\Users\Permissions::class)->name('users.permissions');
        });
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
    });

require __DIR__.'/settings.php';
