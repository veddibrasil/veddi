<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOptionGroup extends Model
{
    protected $fillable = ['company_id', 'name', 'total_qty', 'fixed', 'sort_order'];

    protected $casts = [
        'total_qty' => 'integer',
        'fixed' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'option_group_product')
            ->withPivot('sort_order');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order');
    }

    public function activeOptions(): HasMany
    {
        return $this->hasMany(ProductOption::class)->where('active', true)->orderBy('sort_order');
    }

    public function inactiveOptions(): HasMany
    {
        return $this->hasMany(ProductOption::class)->where('active', false);
    }
}
