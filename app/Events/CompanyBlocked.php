<?php

namespace App\Events;

use App\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CompanyBlocked
{
    use Dispatchable, SerializesModels;

    public function __construct(public Company $company) {}
}
