<?php

namespace App\Providers;

use App\Contracts\AsaasServiceInterface;
use App\Contracts\OrderServiceInterface;
use App\Contracts\RefundServiceInterface;
use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\CompanyActivated;
use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Listeners\SendOrderDeliveredEmail;
use App\Listeners\SendWelcomeSubscriptionEmail;
use App\Listeners\SendWhatsAppOrderNotification;
use App\Listeners\SendWhatsAppStatusNotification;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Policies\BranchPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\CouponPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductCategoryPolicy;
use App\Policies\ProductPolicy;
use App\Services\Finance\TransactionService;
use App\Services\Finance\WalletService;
use App\Services\Order\OrderService;
use App\Services\Payment\AsaasService;
use App\Services\Refund\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        $this->app->bind(AsaasServiceInterface::class, AsaasService::class);
        $this->app->bind(OrderServiceInterface::class, OrderService::class);
        $this->app->bind(WalletServiceInterface::class, WalletService::class);
        $this->app->bind(TransactionServiceInterface::class, TransactionService::class);
        $this->app->bind(RefundServiceInterface::class, RefundService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerPolicies();
        $this->configureNotifications();
        $this->configureEvents();
    }

    protected function configureEvents(): void
    {
        Event::listen(CompanyActivated::class, SendWelcomeSubscriptionEmail::class);
        Event::listen(NewOrderPlaced::class, SendWhatsAppOrderNotification::class);
        Event::listen(OrderStatusUpdated::class, SendWhatsAppStatusNotification::class);
        Event::listen(OrderStatusUpdated::class, SendOrderDeliveredEmail::class);
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Coupon::class, CouponPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
    }

    protected function configureNotifications(): void
    {
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

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
