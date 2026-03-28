<?php

namespace App\Listeners;

use App\Events\CompanyActivated;
use App\Mail\WelcomeSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWelcomeSubscriptionEmail
{
    public function handle(CompanyActivated $event): void
    {
        $company = $event->company;

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
