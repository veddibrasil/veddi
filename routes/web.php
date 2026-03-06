<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// --- Public Chat ---
Route::get('/', \App\Livewire\Chat\OrderChat::class)->name('chat.index');
Route::get('/{company}', \App\Livewire\Chat\OrderChat::class)->name('chat.company');

// --- Webhook (no auth, no CSRF, no company scope) ---
Route::match(['get', 'post'], '/webhooks/abacatepay', [WebhookController::class, 'abacatepay'])
    ->name('webhook.abacatepay');

// --- Company Admin Panel ---
Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // Dashboard e pedidos: company_admin + branch_manager
        Route::middleware('company.role:company_admin,branch_manager')->group(function () {
            Route::get('/dashboard', \App\Livewire\Admin\Dashboard::class)->name('dashboard');
            Route::get('/orders', \App\Livewire\Admin\Orders\Index::class)->name('orders.index');
            Route::get('/orders/{order}', \App\Livewire\Admin\Orders\Show::class)->name('orders.show');
        });

        // Gestão completa: só company_admin
        Route::middleware('company.role:company_admin')->group(function () {
            Route::get('/branches', \App\Livewire\Admin\Branches\Index::class)->name('branches.index');
            Route::get('/branches/create', \App\Livewire\Admin\Branches\Form::class)->name('branches.create');
            Route::get('/branches/{branch}/edit', \App\Livewire\Admin\Branches\Form::class)->name('branches.edit');

            Route::get('/categories', \App\Livewire\Admin\Categories\Index::class)->name('categories.index');

            Route::get('/products', \App\Livewire\Admin\Products\Index::class)->name('products.index');
            Route::get('/products/create', \App\Livewire\Admin\Products\Form::class)->name('products.create');
            Route::get('/products/{product}/edit', \App\Livewire\Admin\Products\Form::class)->name('products.edit');

            Route::get('/settings', \App\Livewire\Admin\Settings\CompanySettings::class)->name('settings');
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
    });

require __DIR__.'/settings.php';
