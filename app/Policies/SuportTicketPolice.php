<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use App\Models\Company;

class SuportTicketPolice
{

    /**
     * Determine whether the user can view the model.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('support.view', $this->company());
    }

    public function view(User $user, SupportTicket $suport): bool
    {
        return $user->hasPermission('support.view', $this->company())
                    && $suport->company_id === $this->company()->id;

    }

    private function company(): Company
    {
        return app('current.company');
    }

}
