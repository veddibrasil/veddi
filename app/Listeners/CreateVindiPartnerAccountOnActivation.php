<?php

namespace App\Listeners;

use App\Events\CompanyActivated;
use App\Jobs\CreateVindiPartnerAccount;

class CreateVindiPartnerAccountOnActivation
{
    public function handle(CompanyActivated $event): void
    {
        CreateVindiPartnerAccount::dispatch($event->company);
    }
}
