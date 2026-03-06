<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'order_number', 'customer_id', 'branch_id', 'subtotal', 'total', 'status', 'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->order_number = static::generateOrderNumber();
        });
    }

    private static function generateOrderNumber(): string
    {
        $year   = now()->year;
        $prefix = app()->bound('current.company') ? app('current.company')->order_prefix : 'ORD';
        $count  = static::whereYear('created_at', $year)->count() + 1;
        return sprintf('%s-%d-%05d', $prefix, $year, $count);
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
        return $this->hasOne(Payment::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending'          => 'Pendente',
            'awaiting_payment' => 'Aguardando Pagamento',
            'paid'             => 'Pago',
            'preparing'        => 'Preparando',
            'ready'            => 'Pronto',
            'delivered'        => 'Entregue',
            'cancelled'        => 'Cancelado',
            default            => $this->status,
        };
    }
}
