<?php

namespace App\Enums;

enum Plan: string
{
    case Free     = 'free';
    case Essencial = 'essencial';
    case Pro      = 'pro';

    /**
     * Human-readable label for this plan.
     */
    public function label(): string
    {
        return match($this) {
            Plan::Free      => 'Grátis',
            Plan::Essencial => 'Essencial',
            Plan::Pro       => 'PRO',
        };
    }

    /**
     * Monthly price in BRL (R$). Reads from config so prices can be overridden per environment.
     */
    public function monthlyPrice(): float
    {
        return (float) config("plans.{$this->value}.monthly_price", 0.0);
    }

    /**
     * Per-order fee as a decimal fraction (e.g. 0.01 = 1%).
     * Reads from config so fees can be adjusted without code changes.
     */
    public function feePercentage(): float
    {
        return (float) config("plans.{$this->value}.fee_percentage", 0.0);
    }

    /**
     * One-time setup fee charged on activation (R$).
     */
    public function setupFee(): float
    {
        return (float) config("plans.{$this->value}.setup_fee", 99.00);
    }

    /**
     * Whether this plan has a recurring monthly Asaas subscription.
     */
    public function hasMonthlySubscription(): bool
    {
        return (bool) config("plans.{$this->value}.has_monthly_subscription", false);
    }

    /**
     * Maximum orders per month. Null means unlimited.
     */
    public function maxOrdersPerMonth(): ?int
    {
        $value = config("plans.{$this->value}.max_orders_per_month");

        return $value !== null ? (int) $value : null;
    }

    /**
     * Maximum number of branches allowed for this plan.
     */
    public function maxBranches(): int
    {
        return (int) config("plans.{$this->value}.max_branches", 1);
    }

    /**
     * Description used when creating an Asaas charge or subscription.
     */
    public function asaasDescription(): string
    {
        return config("plans.{$this->value}.asaas_description", "Assinatura Plano {$this->label()}");
    }

    /**
     * All valid plan string values. Use in validation rules.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Try to create a Plan from a string value, returning null if invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
