<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'order_number', 'customer_id', 'branch_id', 'subtotal', 'delivery_fee', 'total',
        'status', 'notes', 'scheduled_at', 'payment_method', 'order_type', 'coupon_id', 'discount', 'fee', 'net_value',
        'fee_billed_at',
        'delivery_address_id',
    ];

    protected $casts = [
        'fee_billed_at' => 'datetime',
        'delivered_email_sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->order_number = static::generateOrderNumber();
        });

        static::saving(function (Order $order) {
            if ($order->pendingDeliveryAddressData === []) {
                return;
            }

            $hasAny = collect($order->pendingDeliveryAddressData)
                ->filter(fn ($v) => $v !== null && trim((string) $v) !== '')
                ->isNotEmpty();

            if (! $hasAny) {
                $order->pendingDeliveryAddressData = [];

                return;
            }

            $address = $order->delivery_address_id ? Address::find($order->delivery_address_id) : null;
            if (! $address) {
                $address = new Address;
            }

            $address->fill($order->pendingDeliveryAddressData);
            $address->save();

            $order->delivery_address_id = $address->id;
            $order->pendingDeliveryAddressData = [];
        });

        static::deleting(function (Order $order) {
            if (! $order->delivery_address_id) {
                return;
            }

            $address = Address::find($order->delivery_address_id);
            $address?->delete();
        });
    }

    /**
     * Pending delivery address snapshot values set via mutators.
     *
     * @var array<string, mixed>
     */
    protected array $pendingDeliveryAddressData = [];

    private static function generateOrderNumber(): string
    {
        $year = now()->year;
        $prefix = app()->bound('current.company') ? app('current.company')->order_prefix : 'ORD';
        $suffix = Str::upper(Str::ulid()->toBase32());

        return sprintf('%s-%d-%s', $prefix, $year, $suffix);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function deliveryAddressRecord(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    // --- Virtual accessors/mutators for delivery snapshot fields ---

    public function getDeliveryAddressAttribute(): ?string
    {
        return $this->deliveryAddressRecord?->line1;
    }

    public function setDeliveryAddressAttribute($value): void
    {
        $this->pendingDeliveryAddressData['line1'] = $value;
    }

    public function getDeliveryNumberAttribute(): ?string
    {
        return $this->deliveryAddressRecord?->number;
    }

    public function setDeliveryNumberAttribute($value): void
    {
        $this->pendingDeliveryAddressData['number'] = $value;
    }

    public function getDeliveryComplementAttribute(): ?string
    {
        return $this->deliveryAddressRecord?->complement;
    }

    public function setDeliveryComplementAttribute($value): void
    {
        $this->pendingDeliveryAddressData['complement'] = $value;
    }

    public function getDeliveryNeighborhoodAttribute(): ?string
    {
        return $this->deliveryAddressRecord?->neighborhood;
    }

    public function setDeliveryNeighborhoodAttribute($value): void
    {
        $this->pendingDeliveryAddressData['neighborhood'] = $value;
    }

    public function getDeliveryCityAttribute(): ?string
    {
        return $this->deliveryAddressRecord?->city;
    }

    public function setDeliveryCityAttribute($value): void
    {
        $this->pendingDeliveryAddressData['city'] = $value;
    }

    public function getDeliveryCepAttribute(): ?string
    {
        return $this->deliveryAddressRecord?->cep;
    }

    public function setDeliveryCepAttribute($value): void
    {
        $this->pendingDeliveryAddressData['cep'] = $value;
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function isScheduled(): bool
    {
        return $this->scheduled_at !== null;
    }

    /** Returns the delivery address snapshot, falling back to customer address for legacy orders. */
    public function deliveryFullAddress(): string
    {
        $record = $this->deliveryAddressRecord ?? $this->customer?->addressRecord;

        return implode(', ', array_filter([
            $record?->line1,
            $record?->neighborhood,
            $record?->city,
        ]));
    }

    /** Returns the status codes that allow admin editing of address and items. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['pending', 'awaiting_payment', 'paid', 'preparing', 'ready', 'scheduled']);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pendente',
            'awaiting_payment' => 'Aguardando Pagamento',
            'scheduled' => 'Agendado',
            'paid' => 'Pago',
            'preparing' => 'Preparando',
            'ready' => 'Pronto',
            'delivered' => 'Entregue',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            default => $this->status,
        };
    }
}
