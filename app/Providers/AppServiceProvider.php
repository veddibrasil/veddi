<?php

namespace App\Providers;

use App\Contracts\AsaasServiceInterface;
use App\Contracts\CnpjLookupInterface;
use App\Contracts\FiscalNoteProviderInterface;
use App\Contracts\IfoodGatewayContract;
use App\Contracts\OrderServiceInterface;
use App\Contracts\PrinterServiceInterface;
use App\Contracts\RefundServiceInterface;
use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Contracts\WhatsAppManagementInterface;
use App\Contracts\WhatsAppProviderInterface;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Observers\FiscalNoteObserver;
use App\Observers\ProductObserver;
use App\Policies\BranchPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\CouponPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductCategoryPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\Finance\TransactionService;
use App\Services\Finance\WalletService;
use App\Services\Fiscal\FocusNfeService;
use App\Services\Ifood\IfoodGatewayService;
use App\Services\Messaging\MetaCloudApiProvider;
use App\Services\Order\OrderService;
use App\Services\Payment\AsaasService;
use App\Services\Printer\EscPosPrinterService;
use App\Services\Refund\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\OutgoingRequest;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            \App\Exceptions\Handler::class,
        );

        $this->app->singleton(\App\Services\Payment\AsaasCircuitBreaker::class);

        $this->app->bind(\App\Contracts\ChatCodeSender::class, function () {
            return match (config('chat_security.code_channel')) {
                'email' => new \App\Services\Chat\EmailCodeSender,
                'whatsapp' => \App\Services\Chat\WhatsAppCodeSender::isEnabled()
                    ? new \App\Services\Chat\WhatsAppCodeSender
                    : new \App\Services\Chat\UnavailableCodeSender,
                default => new \App\Services\Chat\UnavailableCodeSender,
            };
        });

        $this->app->bind(AsaasServiceInterface::class, AsaasService::class);
        $this->app->bind(OrderServiceInterface::class, OrderService::class);
        $this->app->bind(CnpjLookupInterface::class, \App\Services\Fiscal\BrasilApiCnpjLookup::class);
        $this->app->bind(FiscalNoteProviderInterface::class, function () {
            // Só bate na API de produção da Focus NFe quando o app roda em produção —
            // qualquer outro APP_ENV (local, testing, staging) sempre usa homologação,
            // mesmo que a empresa esteja configurada como "produção", para nunca emitir
            // nota fiscal real fora do ambiente de produção.
            $useProducao = app()->isProduction() && config('fiscal.environment', 'homologacao') === 'producao';

            return new FocusNfeService(
                token: config('fiscal.focus_nfe.token', ''),
                baseUrl: config(
                    $useProducao
                        ? 'fiscal.focus_nfe.base_url_producao'
                        : 'fiscal.focus_nfe.base_url_homologacao',
                    'https://homologacao.focusnfe.com.br',
                ),
            );
        });
        $this->app->bind(WalletServiceInterface::class, WalletService::class);
        $this->app->bind(TransactionServiceInterface::class, TransactionService::class);
        $this->app->bind(RefundServiceInterface::class, RefundService::class);
        $this->app->bind(PrinterServiceInterface::class, EscPosPrinterService::class);
        $this->app->bind(IfoodGatewayContract::class, IfoodGatewayService::class);
        $this->app->bind(WhatsAppProviderInterface::class, MetaCloudApiProvider::class);
        $this->app->bind(WhatsAppManagementInterface::class, MetaCloudApiProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Middlewares de rota não rodam em /livewire/update por padrão: sem isto, uma aba aberta
        // seguia agindo depois de o usuário perder o papel, a empresa ser bloqueada ou o super
        // admin ser rebaixado (e current.branch não era bindado nos updates).
        \Livewire\Livewire::addPersistentMiddleware([
            \App\Http\Middleware\CheckCompanyRole::class,
            \App\Http\Middleware\RequireSuperAdmin::class,
            \App\Http\Middleware\EnsureCompanyIsActive::class,
        ]);

        $this->configureDefaults();
        $this->registerPolicies();
        $this->configureNotifications();
        $this->configureNightwatch();

        FiscalNote::observe(FiscalNoteObserver::class);
        Product::observe(ProductObserver::class);
    }

    /**
     * O Nightwatch grava a URL completa das requisições externas. A Graph API da Meta recebe
     * client_secret, code de uso único, tokens e appsecret_proof na query: os valores nunca
     * podem sair do servidor.
     */
    protected function configureNightwatch(): void
    {
        Nightwatch::redactRequests(function (\Laravel\Nightwatch\Records\Request $request) {
            if (str_ends_with($request->routeName, 'livewire.update') || $request->headers->has('X-Livewire')) {
                $request->payload->replace(['livewire' => '[redacted]']);
            }
        });

        Nightwatch::redactOutgoingRequests(function (OutgoingRequest $request) {
            $request->url = (string) preg_replace(
                '/([?&](?:client_secret|code|access_token|input_token|appsecret_proof|fb_exchange_token|token|pin)=)[^&#]*/i',
                '$1[redacted]',
                $request->url,
            );
        });
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Coupon::class, CouponPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        Gate::define('stock.view', function (User $user) {
            $company = app('current.company');

            return $company && $user->hasPermission('stock.view', $company);
        });
        Gate::define('stock.adjust', function (User $user) {
            $company = app('current.company');

            return $company && $user->hasPermission('stock.adjust', $company);
        });
        Gate::define('stock.toggle', function (User $user) {
            $company = app('current.company');

            return $company && $user->hasPermission('stock.toggle', $company);
        });
        Gate::define('company.settings', function (User $user) {
            $company = app('current.company');

            return $company && $user->hasPermission('company.settings', $company);
        });
    }

    protected function configureNotifications(): void
    {
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            // Host fixo do APP_URL: montar pelo host da request deixava o link com o token
            // apontar para um domínio injetado no cabeçalho.
            $url = rtrim(config('app.url'), '/').route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false);

            $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Redefinição de Senha')
                ->line('Você está recebendo este e-mail porque recebemos uma solicitação de redefinição de senha para sua conta.')
                ->action('Redefinir Senha', $url)
                ->line("Este link de redefinição de senha expirará em {$expire} minutos.")
                ->line('Se você não solicitou a redefinição de senha, nenhuma ação adicional é necessária.');
        });

        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Verificação de Endereço de E-mail')
                ->line('Clique no botão abaixo para verificar seu endereço de e-mail.')
                ->action('Verificar E-mail', $url)
                ->line('Se você não criou uma conta, nenhuma ação adicional é necessária.');
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        if (app()->isProduction()) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
