<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCard extends Model
{
    protected $fillable = [
        'customer_id',
        'vindi_card_token',
        'last_four',
        'brand',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function label(): string
    {
        return trim(ucfirst($this->brand ?: 'Cartão').' •••• '.$this->last_four);
    }
}
