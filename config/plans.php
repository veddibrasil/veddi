<?php

/**
 * Plan definitions.
 *
 * Each key is a Plan enum value (App\Enums\Plan).
 * To add a new plan:
 *   1. Add a new case to App\Enums\Plan.
 *   2. Add its entry here with the required keys.
 *   3. No migration needed — plan columns are VARCHAR.
 */
return [

    'free' => [
        // Price charged per month (0 = no charge).
        'monthly_price' => 0.00,

        // Per-order fee deducted from the order total (1% = 0.01).
        'fee_percentage' => 0.01,

        // One-time setup fee charged on activation (0 = sem cobrança para o plano free).
        'setup_fee' => 0.00,

        // Whether this plan has a recurring monthly Asaas subscription.
        'has_monthly_subscription' => false,

        // Maximum orders per month (null = unlimited).
        'max_orders_per_month' => 50,

        // Maximum branches allowed.
        'max_branches' => 1,

        // Description sent to Asaas when creating the setup fee charge.
        'asaas_description' => 'Taxa de ativação — Plano VEDDI FREE',
    ],

    'essencial' => [
        'monthly_price' => (float) env('PLAN_ESSENCIAL_PRICE', 59.00),
        'fee_percentage' => 0.00,
        'setup_fee' => (float) env('PLAN_SETUP_FEE', 99.00),
        'has_monthly_subscription' => true,
        'max_orders_per_month' => null,
        'max_branches' => 1,
        'asaas_description' => 'Assinatura Plano VEDDI ESSENCIAL',
    ],

    'pro' => [
        'monthly_price' => (float) env('PLAN_PRO_PRICE', 119.00),
        'fee_percentage' => 0.00,
        'setup_fee' => (float) env('PLAN_SETUP_FEE', 99.00),
        'has_monthly_subscription' => true,
        'max_orders_per_month' => null,
        'max_branches' => 3,
        'asaas_description' => 'Assinatura Plano VEDDI PRO',
    ],

];
