<?php

namespace App\Livewire\Admin\Settings;

use App\Enums\Plan;
use App\Jobs\CreateAsaasSubscription;
use App\Models\Subscription;
use App\Services\AsaasService;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;

class BillingSettings extends Component
{
    public string $plan        = 'free';
    public string $status      = 'ACTIVE';
    public ?string $nextDueDate      = null;
    public ?string $lastPaymentAt    = null;
    public ?string $setupFeePaidAt   = null;
    public ?float  $amount           = null;
    public ?string $asaasSubscriptionId = null;
    public array   $payments         = [];

    public bool   $confirmingPlanChange = false;
    public string $targetPlan           = '';

    public function mount(AsaasService $asaasService): void
    {
        $company = app('current.company');

        $this->plan                = $company->plan?->value ?? 'free';
        $this->status              = $company->status ?? 'ACTIVE';
        $this->asaasSubscriptionId = $company->asaas_subscription_id;
        $this->setupFeePaidAt      = $company->setup_fee_paid_at?->format('d/m/Y');

        /** @var Subscription|null $subscription */
        $subscription = $company->subscriptions()->latest()->first();

        if ($subscription) {
            $this->amount        = (float) $subscription->amount;
            $this->nextDueDate   = $subscription->next_due_date?->format('d/m/Y');
            $this->lastPaymentAt = $subscription->last_payment_at?->format('d/m/Y');
        }

        if ($this->asaasSubscriptionId) {
            $this->payments = Cache::remember(
                "asaas_payments_{$this->asaasSubscriptionId}",
                now()->addMinutes(5),
                fn () => $asaasService->getSubscriptionPayments($this->asaasSubscriptionId),
            );
        }
    }

    public function confirmChangePlan(string $plan): void
    {
        if ($plan === $this->plan) {
            return;
        }

        $this->targetPlan           = $plan;
        $this->confirmingPlanChange = true;
    }

    public function changePlan(AsaasService $asaasService): void
    {
        $company    = app('current.company');
        $targetPlan = Plan::tryFrom($this->targetPlan);

        if (! $targetPlan) {
            $this->confirmingPlanChange = false;
            return;
        }

        $goingToFree = $targetPlan === Plan::Free;

        if ($goingToFree) {
            // Downgrade to free: cancel subscription, keep ACTIVE (setup fee already paid)
            if ($company->asaas_subscription_id) {
                $asaasService->cancelSubscription($company->asaas_subscription_id);

                Subscription::where('company_id', $company->id)
                    ->where('asaas_subscription_id', $company->asaas_subscription_id)
                    ->whereIn('status', ['active', 'pending'])
                    ->update(['status' => 'cancelled']);
            }

            $company->update([
                'plan'                  => 'free',
                'status'                => 'ACTIVE',
                'active'                => true,
                'asaas_subscription_id' => null,
            ]);

            $this->plan                = 'free';
            $this->status              = 'ACTIVE';
            $this->asaasSubscriptionId = null;
            $this->amount              = null;
            $this->nextDueDate         = null;
            $this->lastPaymentAt       = null;
            $this->payments            = [];
        } else {
            // Upgrade or cross-grade to a paid plan (Essencial or PRO)

            // Cancel existing subscription if switching between paid plans
            if ($company->asaas_subscription_id) {
                $asaasService->cancelSubscription($company->asaas_subscription_id);

                Subscription::where('company_id', $company->id)
                    ->where('asaas_subscription_id', $company->asaas_subscription_id)
                    ->whereIn('status', ['active', 'pending'])
                    ->update(['status' => 'cancelled']);
            }

            $company->update([
                'plan'                  => $targetPlan->value,
                'status'                => 'PENDING_PAYMENT',
                'active'                => false,
                'asaas_subscription_id' => null,
            ]);

            CreateAsaasSubscription::dispatch($company->fresh());

            $this->plan                = $targetPlan->value;
            $this->status              = 'PENDING_PAYMENT';
            $this->asaasSubscriptionId = null;
            $this->amount              = null;
            $this->nextDueDate         = null;
            $this->lastPaymentAt       = null;
            $this->payments            = [];
        }

        $this->confirmingPlanChange = false;
        $this->targetPlan           = '';
    }

    public function cancelPlanChange(): void
    {
        $this->confirmingPlanChange = false;
        $this->targetPlan           = '';
    }

    public function render(): View
    {
        return view('livewire.admin.settings.billing-settings')
            ->layout('layouts.app', ['title' => 'Assinatura']);
    }
}
