<?php

namespace App\Observers;

use App\Jobs\SyncKeetaCatalogJob;
use App\Models\Branch;

class KeetaBranchObserver
{
    public function updated(Branch $branch): void
    {
        if ($branch->wasChanged(['phone', 'opens_at', 'closes_at', 'available_days', 'business_hours'])) {
            SyncKeetaCatalogJob::debounceForCompany((int) $branch->company_id, [$branch->id]);
        }
    }
}
