<?php

namespace App\Services\Customer;

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
     * Usado só para reaproveitar o NOME de quem já comprou em outra empresa
     * (ver createFromGlobal).
     */
    public function findFromGlobal(string $phone): ?Customer
    {
        return Customer::findByPhoneGlobally($phone);
    }

    /**
     * Cria um novo cliente para a empresa atual a partir de um cadastro de OUTRA
     * empresa, copiando só o nome. O chat identifica o cliente só pelo telefone
     * (sem verificação), então copiar CPF, e-mail e endereço entregava esses dados
     * a quem digitasse o telefone na loja de qualquer empresa — e os gravava nela.
     * Endereço e CPF são pedidos de novo no checkout desta empresa.
     */
    public function createFromGlobal(string $phone, Customer $source): Customer
    {
        $customer = Customer::create([
            'phone' => preg_replace('/\D/', '', $phone),
            'name' => $source->name,
        ]);

        Log::channel('chat')->info('Cliente importado de outra empresa (só o nome)', [
            'customer_id' => $customer->id,
            'source_company_id' => $source->company_id,
        ]);

        return $customer;
    }

    /**
     * Registra o consentimento para notificações por WhatsApp (Meta + LGPD). O consentimento é
     * por empresa: createFromGlobal não o herda. Quem já consentiu mantém a data original; quem
     * tinha pedido para parar (opt-out) volta a receber ao consentir de novo. Desmarcar o
     * checkbox no chat nunca chega aqui — só o opt-out pelo WhatsApp revoga.
     */
    public function registerWhatsAppOptIn(Customer $customer): Customer
    {
        if ($customer->canReceiveWhatsApp()) {
            return $customer;
        }

        $customer->forceFill([
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_out_at' => null,
        ])->save();

        Log::channel('chat')->info('Cliente aceitou notificações por WhatsApp', ['customer_id' => $customer->id]);

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
