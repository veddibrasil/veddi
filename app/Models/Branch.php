<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Branch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'address_id',
        // virtual address fields (persisted on addresses table)
        'address',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'cep',
        // other branch fields
        'phone',
        'active',
        'opens_at',
        'closes_at',
        'available_days',
        'business_hours',
    ];

    protected $casts = [
        'active' => 'boolean',
        'available_days' => 'array',
        'business_hours' => 'array',
    ];

    /**
     * Pending address values set via mutators (stored in addresses table).
     *
     * @var array<string, mixed>
     */
    protected array $pendingAddressData = [];

    protected static function booted(): void
    {
        static::saving(function (Branch $branch) {
            if ($branch->pendingAddressData === []) {
                return;
            }

            $hasAny = collect($branch->pendingAddressData)
                ->filter(fn ($v) => $v !== null && trim((string) $v) !== '')
                ->isNotEmpty();

            if (! $hasAny) {
                $branch->pendingAddressData = [];

                return;
            }

            $address = $branch->address_id ? Address::find($branch->address_id) : null;
            if (! $address) {
                $address = new Address;
            }

            $address->fill($branch->pendingAddressData);
            $address->save();

            $branch->address_id = $address->id;
            $branch->pendingAddressData = [];
        });

        static::deleting(function (Branch $branch) {
            if (! $branch->address_id) {
                return;
            }

            $address = Address::find($branch->address_id);
            $address?->delete();
        });
    }

    public function addressRecord(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'address_id');
    }

    // --- Virtual accessors/mutators for address fields ---

    public function getAddressAttribute(): ?string
    {
        return $this->addressRecord?->line1;
    }

    public function setAddressAttribute($value): void
    {
        $this->pendingAddressData['line1'] = $value;
    }

    public function getNumberAttribute(): ?string
    {
        return $this->addressRecord?->number;
    }

    public function setNumberAttribute($value): void
    {
        $this->pendingAddressData['number'] = $value;
    }

    public function getComplementAttribute(): ?string
    {
        return $this->addressRecord?->complement;
    }

    public function setComplementAttribute($value): void
    {
        $this->pendingAddressData['complement'] = $value;
    }

    public function getNeighborhoodAttribute(): ?string
    {
        return $this->addressRecord?->neighborhood;
    }

    public function setNeighborhoodAttribute($value): void
    {
        $this->pendingAddressData['neighborhood'] = $value;
    }

    public function getCityAttribute(): ?string
    {
        return $this->addressRecord?->city;
    }

    public function setCityAttribute($value): void
    {
        $this->pendingAddressData['city'] = $value;
    }

    public function getStateAttribute(): ?string
    {
        return $this->addressRecord?->state;
    }

    public function setStateAttribute($value): void
    {
        $this->pendingAddressData['state'] = $value;
    }

    public function getCepAttribute(): ?string
    {
        return $this->addressRecord?->cep;
    }

    public function setCepAttribute($value): void
    {
        $this->pendingAddressData['cep'] = $value;
    }

    public function getOpensAtAttribute($value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    public function getClosesAtAttribute($value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot(['available', 'quantity', 'min_quantity', 'track_stock'])
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliverySetting(): HasOne
    {
        return $this->hasOne(DeliverySetting::class);
    }

    public function hoursForDay(int $day): array
    {
        $perDay = $this->business_hours[$day] ?? $this->business_hours[(string) $day] ?? null;

        return [
            'opens_at' => $perDay['opens_at'] ?? $this->getRawOriginal('opens_at') ?? $this->opens_at,
            'closes_at' => $perDay['closes_at'] ?? $this->getRawOriginal('closes_at') ?? $this->closes_at,
        ];
    }

    public function isOpen(): bool
    {
        $now = now(config('app.timezone'));
        $currentDay = (int) $now->format('w');
        $currentTime = $now->format('H:i');

        $days = $this->available_days;
        if ($days !== null && ! in_array($currentDay, $days)) {
            return false;
        }

        $hours = $this->hoursForDay($currentDay);

        return $currentTime >= $hours['opens_at'] && $currentTime <= $hours['closes_at'];
    }
}
