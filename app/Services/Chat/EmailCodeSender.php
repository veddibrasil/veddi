<?php

namespace App\Services\Chat;

use App\Contracts\ChatCodeSender;
use App\Mail\ChatVerificationCode;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Support\Facades\Mail;

class EmailCodeSender implements ChatCodeSender
{
    public function send(int $companyId, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $code, ?string $challengeId = null): void
    {
        $startedAt = hrtime(true);

        try {
            // Public checkout must never resolve a customer outside this company.
            $customer = Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('phone', $phone)->first();
            if (! $customer || ! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $companyName = Company::whereKey($companyId)->value('name') ?? config('app.name');
            Mail::to($customer->email)->queue(new ChatVerificationCode($code, $companyName));
        } finally {
            // Equalize ordinary lookup/queue timings without exposing whether a recipient exists.
            $remainingMicroseconds = 100_000 - (int) ((hrtime(true) - $startedAt) / 1000);
            if ($remainingMicroseconds > 0) {
                usleep($remainingMicroseconds);
            }
        }
    }
}
