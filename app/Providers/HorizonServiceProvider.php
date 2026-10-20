<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // E-mail sozinho não basta (conta com e-mail não verificado, troca de e-mail):
        // exige também a flag de super admin, que só se ganha pelo banco.
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null
                && $user->isSuperAdmin()
                && in_array($user->email, [
                    'guilhermeieski@gmail.com',
                ], true);
        });
    }
}
