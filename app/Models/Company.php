<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'abacatepay_token',
        'abacatepay_webhook_secret',
        'order_prefix',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
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
}
