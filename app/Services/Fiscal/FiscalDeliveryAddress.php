<?php

namespace App\Services\Fiscal;

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Endereço do destinatário na NFC-e de entrega a domicílio (presenca_comprador = 4). A SEFAZ
 * rejeita essa NFC-e sem o endereço do destinatário (rejeição 788), e o endereço só existe
 * dentro do grupo do destinatário, que exige CPF ou CNPJ.
 *
 * Vale o endereço da entrega do pedido; sem ele (ou incompleto), o endereço do destinatário nos
 * dados fiscais do pedido, que o lojista consegue corrigir na tela. Os dois nunca se misturam.
 * Os endereços do chat e do iFood não guardam a UF: ela vem da filial quando a entrega é na
 * cidade da filial, senão da consulta pública do CEP. A consulta só completa campos vazios.
 */
class FiscalDeliveryAddress
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    /** Campos que a SEFAZ exige no endereço do destinatário (o número sai como S/N na falta). */
    private const REQUIRED = ['street' => 'logradouro', 'neighborhood' => 'bairro', 'city' => 'município', 'state' => 'UF'];

    /** @var array<string, array<string, string>> */
    private array $postalCodeResults = [];

    public function __construct(private readonly FiscalPostalCodeLookup $postalCodes) {}

    /** NFC-e que sai com presença 4 (FocusNfeService::mapPresencaComprador). */
    public static function required(Order $order, ?string $model): bool
    {
        return $model === '65' && $order->order_type === 'delivery';
    }

    /**
     * @param  Branch[]  $branches  filiais da venda (emitente e a do pedido) para deduzir a UF pela cidade
     * @return array{address: array{street: ?string, number: string, complement: ?string, neighborhood: ?string, city: ?string, state: ?string, zip: ?string}, missing: array<int, string>}
     */
    public function resolve(Order $order, array $recipient, array $branches = []): array
    {
        $record = $order->delivery_address_id ? $order->deliveryAddressRecord : null;
        $candidates = array_filter([
            $record ? [
                'street' => $record->line1, 'number' => $record->number, 'complement' => $record->complement,
                'neighborhood' => $record->neighborhood, 'city' => $record->city, 'state' => $record->state, 'zip' => $record->cep,
            ] : null,
            collect(['street', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip'])
                ->mapWithKeys(fn ($field) => [$field => $recipient[$field] ?? null])->all(),
        ]);

        $first = null;
        foreach ($candidates as $candidate) {
            $resolved = $this->complete($candidate, $branches);
            if ($resolved['missing'] === []) {
                return $resolved;
            }
            $first ??= $resolved;
        }

        return $first;
    }

    private function complete(array $address, array $branches): array
    {
        $address = array_map(fn ($value) => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null, $address);
        $zip = preg_replace('/\D/', '', (string) $address['zip']);
        $address['zip'] = strlen($zip) === 8 ? $zip : null;
        $address['state'] = $address['state'] !== null ? mb_strtoupper($address['state']) : null;
        if ($address['state'] !== null && ! in_array($address['state'], self::UFS, true)) {
            $address['state'] = null;
        }

        if ($address['state'] === null && $address['city'] !== null) {
            foreach ($branches as $branch) {
                if ($branch && filled($branch->state) && self::sameCity($branch->city, $address['city'])) {
                    $address['state'] = mb_strtoupper(trim($branch->state));
                    break;
                }
            }
        }

        if ($address['zip'] !== null && $this->missing($address) !== []) {
            // Memo por CEP: o Controle de notas confere vários pedidos com a mesma instância e a
            // consulta que falhou não fica no cache da FiscalPostalCodeLookup.
            $this->postalCodeResults[$address['zip']] ??= $this->postalCodes->find($address['zip']) ?? [];
            foreach ($this->postalCodeResults[$address['zip']] as $field => $value) {
                $address[$field] ??= $field === 'state' ? mb_strtoupper($value) : $value;
            }
            if ($address['state'] !== null && ! in_array($address['state'], self::UFS, true)) {
                $address['state'] = null;
            }
        }

        $address['number'] ??= 'S/N';
        foreach (['street', 'number', 'complement', 'neighborhood', 'city'] as $field) {
            if ($address[$field] !== null) {
                $address[$field] = mb_substr($address[$field], 0, 60);
            }
        }

        return ['address' => $address, 'missing' => $this->missing($address)];
    }

    /** @return array<int, string> rótulos dos campos obrigatórios vazios */
    private function missing(array $address): array
    {
        return array_values(array_filter(self::REQUIRED, fn ($field) => blank($address[$field] ?? null), ARRAY_FILTER_USE_KEY));
    }

    private static function sameCity(?string $a, ?string $b): bool
    {
        $normalize = fn (?string $city) => Str::of((string) $city)->ascii()->lower()->squish()->toString();

        return $normalize($a) !== '' && $normalize($a) === $normalize($b);
    }
}
