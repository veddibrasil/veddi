<?php

namespace App\Listeners;

use App\Events\CompanyActivated;
use App\Mail\WelcomeSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWelcomeSubscriptionEmail
{
    public function handle(CompanyActivated $event): void
    {
        $company = $event->company;

        // Idempotency guard: ensure the welcome email is sent at most once per company.
        // Cache::add() is an atomic SET NX — returns false if the key already exists.
        if (! Cache::add("welcome_email_sent_{$company->id}", true, now()->addYear())) {
            return;
        }

        $admin = $company->users()
            ->wherePivot('role', 'company_admin')
            ->first();

        if (! $admin) {
            Log::warning('SendWelcomeSubscriptionEmail: no company_admin found', [
                'company_id' => $company->id,
            ]);

            return;
        }

        Mail::to($admin->email)->send(new WelcomeSubscription($admin, $company));
    }
}
