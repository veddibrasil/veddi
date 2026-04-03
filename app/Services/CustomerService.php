<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class CustomerService
{
    /**
     * Localiza o cliente pelo telefone dentro da empresa corrente
     * (respeita o global scope de BelongsToCompany).
     */
    public function findByPhone(string $phone): ?Customer
    {
        return Customer::findByPhone($phone);
    }

    /**
     * Localiza o cliente pelo telefone em qualquer empresa (sem escopo global).
     * Útil para importar dados de um cliente que já existe em outra filial/empresa.
     */
    public function findFromGlobal(string $phone): ?Customer
    {
        return Customer::findByPhoneGlobally($phone);
    }

    /**
     * Cria um novo cliente para a empresa atual copiando os dados de outro registro
     * encontrado globalmente.
     */
    public function createFromGlobal(string $phone, Customer $source): Customer
    {
        $customer = Customer::create([
            'phone'        => preg_replace('/\D/', '', $phone),
            'name'         => $source->name,
            'email'        => $source->email,
            'address'      => $source->address,
            'complement'   => $source->complement,
            'neighborhood' => $source->neighborhood,
            'number'       => $source->number,
            'city'         => $source->city,
            'cep'          => $source->cep,
            'tax_id'       => $source->tax_id,
        ]);

        Log::channel('chat')->info('Cliente importado de outra empresa', [
            'customer_id'       => $customer->id,
            'source_company_id' => $source->company_id,
            'phone'             => $phone,
        ]);

        return $customer;
    }

    /**
     * Atualiza os dados de perfil de um cliente existente.
     */
    public function updateProfile(int $customerId, array $data): Customer
    {
        $customer = Customer::findOrFail($customerId);

        $fillable = array_intersect_key($data, array_flip([
            'name', 'email', 'address', 'complement',
            'neighborhood', 'number', 'city', 'cep', 'tax_id',
        ]));

        if (isset($fillable['cep'])) {
            $fillable['cep'] = preg_replace('/\D/', '', $fillable['cep']);
        }

        $customer->update($fillable);

        return $customer->fresh();
    }
}
