<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->instance(LoginResponse::class, new class implements LoginResponse
        {
            public function toResponse($request)
            {
                $user = $request->user();

                if ($user?->isSuperAdmin()) {
                    return redirect()->intended(route('superadmin.dashboard'));
                }

                // Não usa app('current.company'): o IdentifyCompany roda antes do Auth::login()
                // desta mesma request, então o usuário ainda não estava autenticado quando ele
                // tentou resolver a empresa — resolve direto pela relação do usuário.
                $company = $user?->companies()->orderBy('id')->first();

                // Garçom só tem acesso ao PDV — pular o dashboard evita 403 logo após o login.
                if ($company && $user?->roleForCompany($company) === 'garcom') {
                    return redirect()->intended(route('admin.pdv.tabs'));
                }

                // Entregador só usa a fila de pedidos — pular o dashboard genérico, direto pra tela dele.
                if ($company && $user?->roleForCompany($company) === 'entrega') {
                    return redirect()->intended(route('admin.orders.index'));
                }

                // Cozinha/bar só usam a fila da própria estação — pular o dashboard genérico.
                if ($company && in_array($user?->roleForCompany($company), ['cozinha', 'bar'])) {
                    return redirect()->intended(route('admin.orders.index'));
                }

                return redirect()->intended(route('admin.dashboard'));
            }
        });

        $this->app->instance(LogoutResponse::class, new class implements LogoutResponse
        {
            public function toResponse($request)
            {
                return redirect('/admin/login');
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
