<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'tax_id',
        'address_id',
        // virtual address fields (persisted on addresses table)
        'address',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'cep',
    ];

    /**
     * Pending address values set via mutators (stored in addresses table).
     *
     * @var array<string, mixed>
     */
    protected array $pendingAddressData = [];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if ($customer->pendingAddressData === []) {
                return;
            }

            $hasAny = collect($customer->pendingAddressData)
                ->filter(fn ($v) => $v !== null && trim((string) $v) !== '')
                ->isNotEmpty();

            if (! $hasAny) {
                $customer->pendingAddressData = [];

                return;
            }

            $address = $customer->address_id ? Address::find($customer->address_id) : null;
            if (! $address) {
                $address = new Address;
            }

            $address->fill($customer->pendingAddressData);
            $address->save();

            $customer->address_id = $address->id;
            $customer->pendingAddressData = [];
        });

        static::deleting(function (Customer $customer) {
            if (! $customer->address_id) {
                return;
            }

            $address = Address::find($customer->address_id);
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

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public static function findByPhone(string $phone): ?self
    {
        $normalized = preg_replace('/\D/', '', $phone);

        // A CompanyScope (global scope do BelongsToCompany) já filtra por company_id quando
        // current.company está vinculado. O filtro explícito abaixo é uma camada adicional
        // de segurança para garantir isolamento mesmo se o scope for contornado.
        $query = static::where('phone', $normalized);

        if (app()->bound('current.company')) {
            $query->where('company_id', app('current.company')->id);
        }

        return $query->first();
    }

    public static function findByPhoneGlobally(string $phone): ?self
    {
        $normalized = preg_replace('/\D/', '', $phone);

        return static::withoutGlobalScopes()
            ->where('phone', $normalized)
            ->first();
    }
}
