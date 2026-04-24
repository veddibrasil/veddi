<?php

namespace App\Livewire\Admin\Settings;

use App\Models\WhatsAppSetting;
use Livewire\Component;

class WhatsAppSettings extends Component
{
    public bool $enabled = false;

    public bool $notifyOnNewOrder = true;

    public bool $notifyOnAwaitingPayment = true;

    public bool $notifyOnPaid = true;

    public bool $notifyOnPreparing = true;

    public bool $notifyOnReady = true;

    public bool $notifyOnDelivered = true;

    public bool $notifyOnCancelled = true;

    public bool $notifyOnAdminMessage = false;

    public bool $hasGlobalCredentials = false;

    public function mount(): void
    {
        $this->hasGlobalCredentials = WhatsAppSetting::hasGlobalCredentials();

        $settings = app('current.company')->whatsappSetting;

        if ($settings) {
            $this->enabled = $settings->enabled;
            $this->notifyOnNewOrder = $settings->notify_on_new_order;
            $this->notifyOnAwaitingPayment = $settings->notify_on_awaiting_payment;
            $this->notifyOnPaid = $settings->notify_on_paid;
            $this->notifyOnPreparing = $settings->notify_on_preparing;
            $this->notifyOnReady = $settings->notify_on_ready;
            $this->notifyOnDelivered = $settings->notify_on_delivered;
            $this->notifyOnCancelled = $settings->notify_on_cancelled;
            $this->notifyOnAdminMessage = $settings->notify_on_admin_message;
        }
    }

    public function save(): void
    {
        $company = app('current.company');

        WhatsAppSetting::updateOrCreate(
            ['company_id' => $company->id],
            [
                'enabled' => $this->enabled,
                'notify_on_new_order' => $this->notifyOnNewOrder,
                'notify_on_awaiting_payment' => $this->notifyOnAwaitingPayment,
                'notify_on_paid' => $this->notifyOnPaid,
                'notify_on_preparing' => $this->notifyOnPreparing,
                'notify_on_ready' => $this->notifyOnReady,
                'notify_on_delivered' => $this->notifyOnDelivered,
                'notify_on_cancelled' => $this->notifyOnCancelled,
                'notify_on_admin_message' => $this->notifyOnAdminMessage,
            ]
        );

        session()->flash('status', 'Configurações de WhatsApp salvas com sucesso.');
        $this->redirect(route('admin.settings.whatsapp'));
    }

    public function render()
    {
        return view('livewire.admin.settings.whats-app-settings')
            ->layout('layouts.app', ['title' => 'Notificações WhatsApp']);
    }
}
