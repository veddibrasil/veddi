<?php

namespace App\Models;

use App\Enums\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

class Company extends Model
{
    protected static function booted(): void
    {
        static::saved(function (Company $company) {
            Cache::forget("company:slug:{$company->slug}");
            Cache::forget("company:subdomain:{$company->subdomain}");

            if (($original = $company->getOriginal('slug')) && $original !== $company->slug) {
                Cache::forget("company:slug:{$original}");
            }
            if (($original = $company->getOriginal('subdomain')) && $original !== $company->subdomain) {
                Cache::forget("company:subdomain:{$original}");
            }
        });

        static::deleted(function (Company $company) {
            Cache::forget("company:slug:{$company->slug}");
            Cache::forget("company:subdomain:{$company->subdomain}");
        });
    }

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
        'tagline',
        'footer_text',
        'chat_highlights',
        'facebook_pixel_id',
        'google_analytics_id',
        'google_ads_id',
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
        'overdue_since',
        'asaas_setup_bank_slip_url',
        'asaas_credit_card_token',
        'asaas_credit_card_last_four',
        'asaas_credit_card_brand',
        'terms_accepted_at',
        'terms_accepted_by_user_id',
        'terms_version',
        'pix_fee_absorbed_by_company',
        'card_fee_absorbed_by_company',
        'schedule_min_advance_minutes',
        'fiscal_notes_enabled',
        'fiscal_addon_asaas_id',
        'pdv_module_enabled',
        'pdv_manual_discount_enabled',
        'waiter_module_enabled',
        'vindi_affiliate_token',
        'vindi_partner_id',
        'email',
    ];

    protected $attributes = [
        'pix_fee_absorbed_by_company' => true,
        'card_fee_absorbed_by_company' => true,
    ];

    protected $casts = [
        'active' => 'boolean',
        'pix_fee_absorbed_by_company' => 'boolean',
        'card_fee_absorbed_by_company' => 'boolean',
        'fiscal_notes_enabled' => 'boolean',
        'pdv_module_enabled' => 'boolean',
        'pdv_manual_discount_enabled' => 'boolean',
        'waiter_module_enabled' => 'boolean',
        'schedule_min_advance_minutes' => 'integer',
        'chat_highlights' => 'array',
        'plan' => Plan::class,
        'pending_plan' => Plan::class,
        'status' => 'string',
        'setup_fee_paid_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'overdue_since' => 'date',
    ];

    public function getScheduleMinAdvanceMinutesAttribute($value): int
    {
        return (int) ($value ?? 60);
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }
        $base = rtrim(config('filesystems.disks.s3.url', ''), '/');

        return $base.'/'.ltrim($this->logo_path, '/');
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

    public function transactions(): HasMany
    {
        return $this->hasMany(CompanyTransaction::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(CompanyBalance::class);
    }

    public function whatsappSetting(): HasOne
    {
        return $this->hasOne(WhatsAppSetting::class);
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

        $year = now()->year;
        $month = now()->month;

        $count = Cache::remember(
            "order_count:active:company:{$this->id}:{$year}-{$month}",
            now()->addSeconds(60),
            fn () => $this->orders()
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->whereNotIn('status', ['cancelled'])
                ->count()
        );

        return $count < $max;
    }

    public function feePercentageForOrder(?Order $order = null): float
    {
        $rate = $this->plan instanceof Plan ? $this->plan->feePercentage() : 0.0;

        if (! $this->isFree()) {
            return $rate;
        }

        $max = $this->plan?->maxOrdersPerMonth();

        if ($max === null) {
            return $rate;
        }

        $reference = $order?->created_at ?? now();

        // Count only confirmed (paid/delivered) orders to avoid abandoned pending checkouts
        // inflating the monthly total and triggering the over-limit rate prematurely.
        $monthlyOrderCount = Cache::remember(
            "order_count:confirmed:company:{$this->id}:{$reference->year}-{$reference->month}",
            now()->addSeconds(60),
            fn () => $this->orders()
                ->whereMonth('created_at', $reference->month)
                ->whereYear('created_at', $reference->year)
                ->whereIn('status', ['paid', 'scheduled', 'preparing', 'ready', 'out_for_delivery', 'delivered'])
                ->count()
        );

        if ($monthlyOrderCount < $max) {
            return $rate;
        }

        return max($rate, (float) config('plans.free.fee_percentage_over_limit', 0.03));
    }

    /**
     * Maximum number of branches allowed by this company's plan.
     */
    public function maxBranches(): int
    {
        return $this->plan instanceof Plan ? $this->plan->maxBranches() : 1;
    }

    public function schedulingEnabled(): bool
    {
        return $this->schedule_min_advance_minutes > 0;
    }

    public function hasSavedAsaasCard(): bool
    {
        return filled($this->asaas_credit_card_token);
    }

    public function savedAsaasCardLabel(): ?string
    {
        if (! $this->hasSavedAsaasCard()) {
            return null;
        }

        return trim(($this->asaas_credit_card_brand ?: 'Cartão').' •••• '.($this->asaas_credit_card_last_four ?: '****'));
    }

    /**
     * Persiste o token de cartão retornado pelo Asaas após uma cobrança aprovada,
     * para reuso em cobranças futuras sem re-enviar PAN/CVV. Não faz nada se o
     * Asaas não retornar token (tokenização não habilitada na conta).
     *
     * @param  array  $charge  Resposta de createCreditCardCharge/createSubscription do AsaasService
     */
    public function saveAsaasCreditCardFromCharge(array $charge): void
    {
        $token = $charge['creditCard']['creditCardToken'] ?? null;

        if (! $token) {
            return;
        }

        $this->update([
            'asaas_credit_card_token' => $token,
            'asaas_credit_card_last_four' => $charge['creditCard']['creditCardNumber'] ?? $this->asaas_credit_card_last_four,
            'asaas_credit_card_brand' => $charge['creditCard']['creditCardBrand'] ?? $this->asaas_credit_card_brand,
        ]);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'OVERDUE';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'BLOCKED';
    }

    public function canUseFiscalNotes(): bool
    {
        return (bool) $this->fiscal_notes_enabled;
    }

    public function fiscalConfig(): HasOne
    {
        return $this->hasOne(CompanyFiscalConfig::class);
    }

    public function fiscalNotes(): HasMany
    {
        return $this->hasMany(FiscalNote::class);
    }
}
