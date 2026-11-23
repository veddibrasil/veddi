<?php

namespace App\Observers;

use App\Jobs\SyncKeetaCatalogJob;
use App\Models\Company;

class KeetaCompanyObserver
{
    public function updated(Company $company): void
    {
        if ($company->wasChanged('email')) {
            SyncKeetaCatalogJob::debounceForCompany($company->id);
        }
    }
}
