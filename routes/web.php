<?php

use App\Helpers\Validation;
use App\Http\Controllers\AsaasSimulatePaymentController;
use App\Http\Controllers\AsaasWebhookController;
use App\Http\Controllers\EmailProviderWebhookController;
use App\Http\Controllers\FiscalWebhookController;
use App\Http\Controllers\Food99WebhookController;
use App\Http\Controllers\IfoodWebhookController;
use App\Http\Controllers\MarketingTrackingController;
use App\Http\Controllers\MarketingUnsubscribeController;
use App\Http\Controllers\RegisterCompanyController;
use App\Http\Controllers\VindiSimulatePaymentController;
use App\Http\Controllers\VindiWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route(auth()->user()->homeRouteName());
    }

    // Rota protegida (admin.dashboard) faria o middleware 'auth' gravar ela como
    // intended na sessão, e o LoginResponse (redirect()->intended()) priorizaria
    // esse intended sobre homeRouteName() — garçom cairia sempre no dashboard.
    return redirect()->route('login');
});

Route::get('/health', \App\Http\Controllers\HealthController::class)->name('health');

// --- Onboarding público ---
Route::get('/cadastro', [RegisterCompanyController::class, 'create'])->name('register.create');
Route::post('/cadastro', [RegisterCompanyController::class, 'store'])->middleware('throttle:5,1')->name('register.store');

// --- Simulação de pagamento Asaas (somente APP_DEBUG=true) ---
Route::post('/dev/simulate/asaas-payment', AsaasSimulatePaymentController::class)->name('dev.simulate.asaas-payment');

// --- Simulação de pagamento Vindi (somente APP_DEBUG=true) ---
Route::post('/dev/simulate/vindi-payment', VindiSimulatePaymentController::class)->name('dev.simulate.vindi-payment');

// --- Webhook Asaas (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
Route::post('/webhooks/asaas', AsaasWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhook.asaas');

// --- Webhook Vindi Intermediador (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
Route::post('/webhooks/vindi', VindiWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhook.vindi');

// --- Webhook Focus NFe (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
Route::post('/webhooks/fiscal', FiscalWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhook.fiscal');

// --- Webhook iFood (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
Route::post('/webhooks/ifood', IfoodWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhook.ifood');

// --- Webhook 99Food (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
// Hub único: todos os callbacks de todas as lojas chegam dos servidores da 99, daí o limite maior.
Route::post('/webhooks/99food', Food99WebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhook.99food');

// --- Webhook WhatsApp Cloud API (sem auth, sem CSRF — coberto por webhooks/* em bootstrap/app.php) ---
// GET = verificação do endpoint pela Meta; POST = eventos de todas as WABAs (assinatura X-Hub-Signature-256).
// Throttle generoso: a Meta envia em rajadas e reenvia o que receber 429.
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:60,1')
    ->name('webhook.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:1200,1')
    ->name('webhook.whatsapp');

// --- Webhook do provedor de e-mail marketing (sem auth, sem CSRF — coberto por webhooks/*) ---
Route::post('/webhooks/email', EmailProviderWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhook.email');

// --- E-mail marketing: links públicos assinados por destinatário (sem login) ---
// POST do descadastro é o One-Click (List-Unsubscribe-Post), fora do CSRF em bootstrap/app.php.
Route::get('/email/u/{recipient}', [MarketingUnsubscribeController::class, 'show'])
    ->whereNumber('recipient')->middleware('throttle:marketing-links')->name('marketing.unsubscribe');
Route::post('/email/u/{recipient}', [MarketingUnsubscribeController::class, 'store'])
    ->whereNumber('recipient')->middleware('throttle:marketing-links')->name('marketing.unsubscribe.store');
Route::get('/email/o/{recipient}', [MarketingTrackingController::class, 'open'])
    ->whereNumber('recipient')->middleware('throttle:marketing-links')->name('marketing.open');
Route::get('/email/c/{recipient}', [MarketingTrackingController::class, 'click'])
    ->whereNumber('recipient')->middleware('throttle:marketing-links')->name('marketing.click');

// --- API pública ---
Route::post('/api/validate-cpf', function (Request $request) {
    $cpf = $request->input('cpf', '');
    $valid = Validation::isValidCpf($cpf);

    return response()->json(['valid' => $valid]);
})->middleware('throttle:30,1')->name('api.validate-cpf');

// --- Documentação pública ---
Route::view('/docs', 'docs')->name('docs');

// --- Termos legais públicos ---
Route::view('/termos-de-uso', 'legal.terms')->name('legal.terms');
Route::view('/politica-de-privacidade', 'legal.privacy')->name('legal.privacy');

// --- Chat Público ---
Route::get('/{company}/acompanhar', \App\Livewire\Chat\OrderChat::class)->name('chat.tracking');
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

        // Dashboard e pedidos: company_admin + branch_manager + cozinha + caixa + bar + entrega
        Route::middleware('company.role:company_admin,branch_manager,cozinha,caixa,bar,entrega')->group(function () {
            Route::get('/dashboard', \App\Livewire\Admin\Dashboard::class)->name('dashboard');
            Route::get('/orders', \App\Livewire\Admin\Orders\Index::class)->name('orders.index');
            Route::get('/orders/report', \App\Livewire\Admin\Orders\Report::class)->name('orders.report');
            Route::get('/orders/report/pdf', \App\Http\Controllers\Admin\Orders\ReportPdfController::class)->name('orders.report.pdf');
            Route::get('/orders/closing/pdf', \App\Http\Controllers\Admin\Orders\OrderClosingPdfController::class)->name('orders.closing.pdf');
            Route::get('/orders/{order}/receipt/{station?}', \App\Http\Controllers\Admin\Orders\ReceiptPdfController::class)->name('orders.receipt');
            Route::get('/orders/{order}/fiscal-note/print', \App\Http\Controllers\Admin\Orders\FiscalNoteReceiptController::class)->name('orders.fiscal-receipt');
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
            Route::get('/fiscal/documentos/{note}/{type}', \App\Http\Controllers\Admin\FiscalDocumentController::class)->name('fiscal.document');
            Route::get('/fiscal/controle', \App\Livewire\Admin\Fiscal\IssueControl::class)->name('fiscal.control');
            Route::get('/fiscal/notas', \App\Livewire\Admin\Fiscal\Notes::class)->name('fiscal.notes');
            Route::get('/fiscal/configuracoes', \App\Livewire\Admin\Fiscal\Config::class)->name('fiscal.config');
        });

        // Gestão completa: só company_admin
        Route::middleware('company.role:company_admin')->group(function () {
            Route::get('/settings', \App\Livewire\Admin\Settings\CompanySettings::class)->name('settings');
            Route::get('/settings/whatsapp', \App\Livewire\Admin\Settings\WhatsAppSettings::class)->name('settings.whatsapp');
            Route::get('/settings/ifood', \App\Livewire\Admin\Settings\IfoodIntegrationSettings::class)->name('settings.ifood');
            Route::get('/settings/99food', \App\Livewire\Admin\Settings\Food99IntegrationSettings::class)->name('settings.99food');
            Route::get('/integrations', \App\Livewire\Admin\Integrations\Index::class)->name('integrations.index');
            Route::get('/reports/sold-items', \App\Livewire\Admin\Reports\SoldItems::class)->name('reports.sold-items');

            Route::get('/roles', \App\Livewire\Admin\Roles\Index::class)->name('roles.index');

            Route::get('/users', \App\Livewire\Admin\Users\Index::class)->name('users.index');
            Route::get('/users/{user}/permissions', \App\Livewire\Admin\Users\Permissions::class)->name('users.permissions');

        });

        // Filiais e cupons: company_admin + branch_manager (autorização fina por Policy/permissão dentro do componente)
        Route::middleware('company.role:company_admin,branch_manager')->group(function () {
            Route::get('/branches', \App\Livewire\Admin\Branches\Index::class)->name('branches.index');
            Route::get('/branches/create', \App\Livewire\Admin\Branches\Form::class)->name('branches.create');
            Route::get('/branches/{branch}/edit', \App\Livewire\Admin\Branches\Form::class)->name('branches.edit');
            Route::get('/branches/{branch}/delivery', \App\Livewire\Admin\Branches\DeliverySettings::class)->name('branches.delivery');
            Route::get('/branches/{branch}/printer', \App\Livewire\Admin\Branches\PrinterSettings::class)->name('branches.printer');
            Route::get('/branches/{branch}/service-charges', \App\Livewire\Admin\Branches\ServiceCharges::class)->name('branches.service-charges');
            Route::get('/branches/{branch}/tables', \App\Livewire\Admin\Branches\RestaurantTables::class)->name('branches.tables');
            Route::get('/branches/{branch}/pauses', \App\Livewire\Admin\Branches\Pauses::class)->name('branches.pauses');

            Route::get('/coupons', \App\Livewire\Admin\Coupons\Index::class)->name('coupons.index');
        });

        // PDV — exige módulo PDV habilitado + permissão pdv.operate/pdv.waiter_operate (verificado no componente)
        Route::get('/pdv', \App\Livewire\Admin\Pdv\Selector::class)->name('pdv');
        Route::get('/pdv/impressao', \App\Livewire\Admin\Pdv\Terminal::class)->name('pdv.checkout');
        Route::get('/pdv/comandas', \App\Livewire\Admin\Pdv\TabTerminal::class)->name('pdv.tabs');
        Route::get('/pdv/report', \App\Livewire\Admin\Pdv\Report::class)->name('pdv.report');
        Route::get('/pdv/cash-sessions/{cashSession}/print', \App\Http\Controllers\Admin\Pdv\CashClosingReportController::class)->name('pdv.cash-session.print');
        Route::get('/pdv/print/receipt/{order}/{station?}', [\App\Http\Controllers\Admin\Pdv\PrintPayloadController::class, 'receipt'])->name('pdv.print.receipt');
        Route::get('/pdv/print/closing', [\App\Http\Controllers\Admin\Pdv\PrintPayloadController::class, 'closing'])->name('pdv.print.closing');
        Route::get('/pdv/print/fiscal-note/{order}', [\App\Http\Controllers\Admin\Pdv\PrintPayloadController::class, 'fiscalNote'])->name('pdv.print.fiscal-note');
        Route::get('/pdv/fiscal-note-status/{order}', [\App\Http\Controllers\Admin\Pdv\PrintPayloadController::class, 'fiscalNoteStatus'])->name('pdv.fiscal-note-status');
        Route::get('/pdv/qz-certificate', [\App\Http\Controllers\Admin\Pdv\QzTraySignatureController::class, 'certificate'])->name('pdv.qz-certificate');
        Route::post('/pdv/qz-sign', [\App\Http\Controllers\Admin\Pdv\QzTraySignatureController::class, 'sign'])->name('pdv.qz-sign');

    });

// --- Super Admin Panel (sem CompanyScope, sem identify.company) ---
Route::middleware(['auth', 'verified', 'super.admin'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {
        Route::get('/dashboard', \App\Livewire\SuperAdmin\Dashboard::class)->name('dashboard');
        Route::get('/companies', \App\Livewire\SuperAdmin\Companies\Index::class)->name('companies.index');
        Route::get('/companies/create', \App\Livewire\SuperAdmin\Companies\Form::class)->name('companies.create');
        Route::get('/companies/{company}', \App\Livewire\SuperAdmin\Companies\Show::class)->name('companies.show');
        Route::get('/companies/{company}/edit', \App\Livewire\SuperAdmin\Companies\Form::class)->name('companies.edit');
        Route::get('/users', \App\Livewire\SuperAdmin\Users\Index::class)->name('users.index');
        Route::get('/users/{user}/permissions', \App\Livewire\SuperAdmin\Permissions\UserPermissions::class)->name('users.permissions');
        Route::get('/permissions', \App\Livewire\SuperAdmin\Permissions\Index::class)->name('permissions.index');
        Route::get('/whatsapp', \App\Livewire\SuperAdmin\WhatsApp\Connections::class)->name('whatsapp.index');
        Route::get('/integracoes', \App\Livewire\SuperAdmin\Integrations\Connections::class)->name('integrations.index');

        // E-mail marketing (público: usuários das empresas; nunca clientes finais dos restaurantes)
        Route::prefix('marketing')->name('marketing.')->group(function () {
            Route::redirect('/', '/superadmin/marketing/campaigns')->name('index');
            Route::get('/campaigns', \App\Livewire\SuperAdmin\Marketing\Campaigns\Index::class)->name('campaigns.index');
            Route::get('/campaigns/create', \App\Livewire\SuperAdmin\Marketing\Campaigns\Wizard::class)->name('campaigns.create');
            Route::get('/campaigns/{campaign}/edit', \App\Livewire\SuperAdmin\Marketing\Campaigns\Wizard::class)->name('campaigns.edit');
            Route::get('/campaigns/{campaign}/export', \App\Http\Controllers\SuperAdmin\MarketingCampaignExportController::class)->name('campaigns.export');
            Route::get('/campaigns/{campaign}', \App\Livewire\SuperAdmin\Marketing\Campaigns\Show::class)->name('campaigns.show');
            Route::get('/templates', \App\Livewire\SuperAdmin\Marketing\Templates\Index::class)->name('templates.index');
            Route::get('/templates/create', \App\Livewire\SuperAdmin\Marketing\Templates\Form::class)->name('templates.create');
            Route::get('/templates/{template}/edit', \App\Livewire\SuperAdmin\Marketing\Templates\Form::class)->name('templates.edit');
            Route::get('/segments', \App\Livewire\SuperAdmin\Marketing\Segments\Index::class)->name('segments.index');
            Route::get('/contacts', \App\Livewire\SuperAdmin\Marketing\Contacts\Index::class)->name('contacts.index');
            Route::get('/contacts/{list}', \App\Livewire\SuperAdmin\Marketing\Contacts\Show::class)->name('contacts.show');
            Route::get('/automations', \App\Livewire\SuperAdmin\Marketing\Automations\Index::class)->name('automations.index');
            Route::get('/automations/create', \App\Livewire\SuperAdmin\Marketing\Automations\Form::class)->name('automations.create');
            Route::get('/automations/{automation}/edit', \App\Livewire\SuperAdmin\Marketing\Automations\Form::class)->name('automations.edit');
            Route::get('/suppressions', \App\Livewire\SuperAdmin\Marketing\Suppressions\Index::class)->name('suppressions.index');
        });

        Route::post('/simulate/asaas-payment', AsaasSimulatePaymentController::class)->name('simulate.asaas-payment');
        Route::post('/simulate/vindi-payment', VindiSimulatePaymentController::class)->name('simulate.vindi-payment');
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
        // Saque e antecipação foram desativados: saldo/saque/antecipação foi migrado
        // pro portal Vindi (ver commit a9ba8d2). O endpoint de saque nunca completava
        // a transferência real (ficava travado em "processing" pra sempre) e não
        // havia tela de operador pra resolver isso manualmente.
    });

require __DIR__.'/settings.php';
