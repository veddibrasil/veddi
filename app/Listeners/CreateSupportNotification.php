<?php

namespace App\Listeners;

use App\Events\NewSupportMessage;
use App\Models\CompanyNotification;

class CreateSupportNotification
{
    public function handle(NewSupportMessage $event): void
    {
        CompanyNotification::create([
            'company_id' => $event->companyId,
            'type'       => 'support',
            'title'      => 'Mensagem de suporte',
            'subtitle'   => $event->ticket->customer?->name ?? 'Cliente',
            'link'       => route('admin.support.index'),
        ]);
    }
}
