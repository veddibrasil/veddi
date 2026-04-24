<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('support.view', $this->company());
    }

    public function view(User $user, SupportTicket $supportTicket): bool
    {
        return $user->hasPermission('support.view', $this->company())
            && $supportTicket->company_id === $this->company()->id;
    }

    private function company(): Company
    {
        return app('current.company');
    }
}
