<?php

namespace App\Events;

use App\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CompanyActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Company $company) {}
}
