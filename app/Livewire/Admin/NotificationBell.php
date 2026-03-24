<?php

namespace App\Livewire\Admin;

use App\Models\CompanyNotification;
use Livewire\Component;

class NotificationBell extends Component
{
    public int $companyId = 0;
    public bool $confirmingClearAll = false;

    public function mount(): void
    {
        if (app()->bound('current.company')) {
            $this->companyId = app('current.company')->id;
        }
    }

    public function getListeners(): array
    {
        if (! $this->companyId) {
            return [];
        }

        return [
            "echo:orders.{$this->companyId},NewOrderPlaced"           => 'onNewOrder',
            "echo:admin-support.{$this->companyId},NewSupportMessage" => 'onNewSupportMessage',
        ];
    }

    public function onNewOrder(): void
    {
        // The DB record is created by CreateOrderNotification listener (server-side, once).
        // Here we just re-render to pick up the new notification from the database.
    }

    public function onNewSupportMessage(): void
    {
        // The DB record is created by CreateSupportNotification listener (server-side, once).
        // Here we just re-render to pick up the new notification from the database.
    }

    public function markRead(int $id): void
    {
        CompanyNotification::where('company_id', $this->companyId)
            ->where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        CompanyNotification::where('company_id', $this->companyId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function delete(int $id): void
    {
        CompanyNotification::where('company_id', $this->companyId)
            ->where('id', $id)
            ->delete();
    }

    public function confirmClearAll(): void
    {
        $this->confirmingClearAll = true;
    }

    public function cancelClearAll(): void
    {
        $this->confirmingClearAll = false;
    }

    public function clearAll(): void
    {
        $this->confirmingClearAll = false;
        CompanyNotification::where('company_id', $this->companyId)->delete();
    }

    public function render()
    {
        $notifications = CompanyNotification::where('company_id', $this->companyId)
            ->latest()
            ->limit(30)
            ->get();

        $unreadCount = $notifications->whereNull('read_at')->count();

        return view('livewire.admin.notification-bell', [
            'notifications' => $notifications,
            'unreadCount'   => $unreadCount,
            'modalName'     => 'confirm-clear-notifications-' . $this->getId(),
        ]);
    }
}
