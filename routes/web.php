<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// --- Public Chat ---
Route::get('/', \App\Livewire\Chat\OrderChat::class)->name('chat.index');

// --- Webhook (no auth, no CSRF) ---
Route::post('/webhooks/abacatepay', [WebhookController::class, 'abacatepay'])
    ->name('webhook.abacatepay');

// --- Admin Panel ---
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', \App\Livewire\Admin\Dashboard::class)->name('dashboard');

    Route::get('/branches', \App\Livewire\Admin\Branches\Index::class)->name('branches.index');
    Route::get('/branches/create', \App\Livewire\Admin\Branches\Form::class)->name('branches.create');
    Route::get('/branches/{branch}/edit', \App\Livewire\Admin\Branches\Form::class)->name('branches.edit');

    Route::get('/categories', \App\Livewire\Admin\Categories\Index::class)->name('categories.index');

    Route::get('/products', \App\Livewire\Admin\Products\Index::class)->name('products.index');
    Route::get('/products/create', \App\Livewire\Admin\Products\Form::class)->name('products.create');
    Route::get('/products/{product}/edit', \App\Livewire\Admin\Products\Form::class)->name('products.edit');

    Route::get('/orders', \App\Livewire\Admin\Orders\Index::class)->name('orders.index');
    Route::get('/orders/{order}', \App\Livewire\Admin\Orders\Show::class)->name('orders.show');
});

// --- Auth ---
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
