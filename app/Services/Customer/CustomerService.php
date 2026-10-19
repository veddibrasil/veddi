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
     * Registra o consentimento para notificações por WhatsApp (Meta + LGPD). O consentimento é
     * por empresa. Quem já consentiu mantém a data original; quem
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
        $companyId = app('current.company')->id;
        $customer = app(\App\Services\Chat\ChatAccess::class)->customer($companyId);
        abort_unless($customer && $customer->id === $customerId, 403);

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
