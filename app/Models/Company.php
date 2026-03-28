<?php

namespace App\Models;

use App\Enums\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Subscription;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'subdomain',
        'primary_color',
        'primary_color_dark',
        'primary_color_light',
        'secondary_color',
        'secondary_color_light',
        'accent_color',
        'logo_path',
        'favicon_path',
        'tagline',
        'footer_text',
        'chat_highlights',
        'order_prefix',
        'active',
        'plan',
        'pending_plan',
        'status',
        'asaas_customer_id',
        'asaas_subscription_id',
        'setup_fee_paid_at',
        'owner_cpf_cnpj',
        'asaas_setup_charge_id',
        'subscription_payment_method',
        'asaas_setup_invoice_url',
        'asaas_setup_pix_qr_code',
        'asaas_setup_pix_copy_paste',
        'asaas_setup_bank_slip_url',
        // Payout defaults
        'default_payout_type',
        'default_pix_key',
        'default_pix_key_type',
        'default_bank_code',
        'default_bank_agency',
        'default_bank_account',
        'default_bank_account_digit',
        'default_bank_account_type',
        'default_bank_owner_cpf_cnpj',
        'default_bank_owner_name',
    ];

    protected $casts = [
        'active'            => 'boolean',
        'chat_highlights'   => 'array',
        'plan'              => Plan::class,
        'pending_plan'      => Plan::class,
        'status'            => 'string',
        'setup_fee_paid_at' => 'datetime',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }
        $base = rtrim(config('filesystems.disks.s3.url', ''), '/');
        return $base . '/' . ltrim($this->logo_path, '/');
    }

    public function getFaviconUrlAttribute(): ?string
    {
        if (! $this->favicon_path) {
            return null;
        }
        $base = rtrim(config('filesystems.disks.s3.url', ''), '/');
        return $base . '/' . ltrim($this->favicon_path, '/');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot(['role', 'branch_id'])
            ->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function walletEntries(): HasMany
    {
        return $this->hasMany(CompanyWalletEntry::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(CompanyWithdrawal::class);
    }

    public function walletBalance(): float
    {
        return CompanyWalletEntry::balanceFor($this->id);
    }

    public function isPro(): bool
    {
        return $this->plan === Plan::Pro;
    }

    public function isEssencial(): bool
    {
        return $this->plan === Plan::Essencial;
    }

    public function isFree(): bool
    {
        return $this->plan === Plan::Free;
    }

    /**
     * Whether this company's plan includes a recurring monthly subscription.
     */
    public function hasMonthlySubscription(): bool
    {
        return $this->plan instanceof Plan && $this->plan->hasMonthlySubscription();
    }

    /**
     * Whether the one-time setup fee has been paid.
     */
    public function hasSetupFeePaid(): bool
    {
        return $this->setup_fee_paid_at !== null;
    }

    /**
     * Whether the company is within its plan's monthly order limit.
     */
    public function isWithinOrderLimit(): bool
    {
        $max = $this->plan instanceof Plan ? $this->plan->maxOrdersPerMonth() : null;

        if ($max === null) {
            return true;
        }

        return $this->orders()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->whereNotIn('status', ['cancelled'])
            ->count() < $max;
    }

    /**
     * Maximum number of branches allowed by this company's plan.
     */
    public function maxBranches(): int
    {
        return $this->plan instanceof Plan ? $this->plan->maxBranches() : 1;
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function isPendingPayment(): bool
    {
        return $this->status === 'PENDING_PAYMENT';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'BLOCKED';
    }
}
