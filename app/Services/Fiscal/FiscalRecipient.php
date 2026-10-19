<?php

namespace App\Services\Fiscal;

use App\Models\Customer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FiscalRecipient
{
    public static function fromCustomer(?Customer $customer): array
    {
        if (! $customer) {
            return ['document' => ''];
        }

        return $customer->fiscal_recipient ?? [
            'document' => $customer->tax_id ?? '',
            'name' => $customer->name ?? '',
            'email' => $customer->email ?? '',
            'street' => $customer->address ?? '',
            'number' => $customer->number ?? '',
            'complement' => $customer->complement ?? '',
            'neighborhood' => $customer->neighborhood ?? '',
            'city' => $customer->city ?? '',
            'state' => $customer->state ?? '',
            'zip' => $customer->cep ?? '',
            'ie_indicator' => '9',
            'ie' => '',
        ];
    }

    public static function model(array $recipient): string
    {
        return strlen(preg_replace('/\D/', '', $recipient['document'] ?? '')) === 14 ? '55' : '65';
    }

    public static function validate(array $recipient): array
    {
        $recipient['document'] = preg_replace('/\D/', '', $recipient['document'] ?? '');
        $recipient['zip'] = preg_replace('/\D/', '', $recipient['zip'] ?? '');
        $recipient['state'] = strtoupper(trim($recipient['state'] ?? ''));
        foreach (['name', 'email', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip', 'ie', 'ie_indicator'] as $field) {
            if (isset($recipient[$field]) && trim((string) $recipient[$field]) === '') {
                $recipient[$field] = null;
            }
        }
        $company = self::model($recipient) === '55';
        $required = $company ? 'required' : 'nullable';
        $data = Validator::make($recipient, [
            'document' => ['nullable', 'regex:/^(\d{11}|\d{14})$/'],
            'name' => [$required, 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'street' => [$required, 'string', 'max:60'],
            'number' => [$required, 'string', 'max:60'],
            'complement' => ['nullable', 'string', 'max:60'],
            'neighborhood' => [$required, 'string', 'max:60'],
            'city' => [$required, 'string', 'max:60'],
            'state' => [$required, 'in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO'],
            'zip' => [$required, 'regex:/^\d{8}$/'],
            'ie_indicator' => [$required, 'in:1,2,9'],
            'ie' => [($company && ($recipient['ie_indicator'] ?? '') === '1') ? 'required' : 'nullable', 'string', 'max:14'],
        ], [], [
            'document' => 'CPF ou CNPJ', 'name' => 'razão social', 'street' => 'logradouro',
            'number' => 'número', 'neighborhood' => 'bairro', 'city' => 'município',
            'state' => 'UF', 'zip' => 'CEP', 'ie_indicator' => 'situação da inscrição estadual', 'ie' => 'inscrição estadual',
        ])->validate();

        if ($data['document'] !== '' && ! self::validDocument($data['document'])) {
            throw ValidationException::withMessages(['document' => 'CPF ou CNPJ inválido.']);
        }

        return $data;
    }

    private static function validDocument(string $digits): bool
    {
        if (preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
        $length = strlen($digits);
        if ($length === 11) {
            for ($i = 9; $i < 11; $i++) {
                $sum = 0;
                for ($j = 0; $j < $i; $j++) {
                    $sum += (int) $digits[$j] * ($i + 1 - $j);
                }
                if ((($sum * 10) % 11) % 10 !== (int) $digits[$i]) {
                    return false;
                }
            }

            return true;
        }
        for ($i = 12; $i < 14; $i++) {
            $sum = 0;
            $weight = $i - 7;
            for ($j = 0; $j < $i; $j++) {
                $sum += (int) $digits[$j] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }
            $digit = ($sum % 11) < 2 ? 0 : 11 - ($sum % 11);
            if ($digit !== (int) $digits[$i]) {
                return false;
            }
        }

        return true;
    }
}
