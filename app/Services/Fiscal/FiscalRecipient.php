<?php

namespace App\Services\Fiscal;

use App\Models\Customer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Destinatário da nota: normalização e validação de CPF/CNPJ e dos dados da empresa.
 *
 * O CNPJ alfanumérico (Receita, a partir de 31/07/2026) tem 12 posições com letras A–Z ou
 * dígitos e 2 dígitos verificadores numéricos. A normalização preserva letras e zeros iniciais;
 * remover tudo que não é dígito transformaria um CNPJ alfanumérico em outro documento.
 *
 * A escolha entre NFC-e e NF-e não é feita aqui: é da FiscalEmissionPolicy, que conhece filial,
 * operação e valor. model() só devolve o padrão histórico (CNPJ = NF-e) para quem ainda não tem
 * esse contexto.
 */
class FiscalRecipient
{
    public const TYPE_CPF = 'cpf';

    public const TYPE_CNPJ = 'cnpj';

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

    /** Padrão sem contexto de operação: CNPJ = NF-e; CPF ou vazio = NFC-e. */
    public static function model(array $recipient): string
    {
        return self::documentType($recipient['document'] ?? '') === self::TYPE_CNPJ ? '55' : '65';
    }

    /** "12.abc.345/01de-35" -> "12ABC34501DE35". Mantém letras e zeros iniciais. */
    public static function normalizeDocument(?string $document): string
    {
        return preg_replace('/[^0-9A-Z]/', '', mb_strtoupper(trim((string) $document)));
    }

    /**
     * Tipo pelo formato já normalizado: CPF tem 11 dígitos; CNPJ tem 12 posições alfanuméricas
     * e 2 dígitos verificadores. Qualquer outra forma (13 posições, CPF com letra) é null.
     */
    public static function documentType(?string $document): ?string
    {
        $normalized = self::normalizeDocument($document);

        return match (true) {
            (bool) preg_match('/^\d{11}$/', $normalized) => self::TYPE_CPF,
            (bool) preg_match('/^[0-9A-Z]{12}\d{2}$/', $normalized) => self::TYPE_CNPJ,
            default => null,
        };
    }

    public static function validate(array $recipient): array
    {
        $recipient['document'] = self::normalizeDocument($recipient['document'] ?? '');
        $recipient['zip'] = preg_replace('/\D/', '', $recipient['zip'] ?? '');
        $recipient['state'] = strtoupper(trim($recipient['state'] ?? ''));
        foreach (['name', 'email', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip', 'ie', 'ie_indicator'] as $field) {
            if (isset($recipient[$field]) && trim((string) $recipient[$field]) === '') {
                $recipient[$field] = null;
            }
        }
        $company = self::documentType($recipient['document']) === self::TYPE_CNPJ;
        $required = $company ? 'required' : 'nullable';
        $data = Validator::make($recipient, [
            'document' => ['nullable', 'string'],
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
            'ie_confirmed' => ['nullable', 'boolean'],
        ], [], [
            'document' => 'CPF ou CNPJ', 'name' => 'razão social', 'street' => 'logradouro',
            'number' => 'número', 'neighborhood' => 'bairro', 'city' => 'município',
            'state' => 'UF', 'zip' => 'CEP', 'ie_indicator' => 'situação da inscrição estadual', 'ie' => 'inscrição estadual',
        ])->validate();

        $data['document'] ??= '';
        if ($data['document'] !== '' && ! self::isValidDocument($data['document'])) {
            throw ValidationException::withMessages(['document' => 'CPF ou CNPJ inválido.']);
        }
        // Situação da IE vinda da consulta pública é sugestão; só vale como conferida quando
        // alguém salvou o formulário (ie_confirmed = true). CPF não tem IE a conferir.
        $data['ie_confirmed'] = $company && (bool) ($recipient['ie_confirmed'] ?? false);

        return $data;
    }

    /** CPF (11 dígitos) ou CNPJ numérico/alfanumérico com dígitos verificadores corretos; aceita máscara. */
    public static function isValidDocument(?string $document): bool
    {
        $normalized = self::normalizeDocument($document);

        return match (self::documentType($normalized)) {
            self::TYPE_CPF => self::validCpf($normalized),
            self::TYPE_CNPJ => self::validCnpj($normalized),
            default => false,
        };
    }

    /** 529.982.247-25 / 11.444.777/0001-61 / 12.ABC.345/01DE-35 — fora do formato volta como veio. */
    public static function formatDocument(?string $document): string
    {
        $normalized = self::normalizeDocument($document);

        return match (self::documentType($normalized)) {
            self::TYPE_CPF => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $normalized),
            self::TYPE_CNPJ => preg_replace('/^(.{2})(.{3})(.{3})(.{4})(\d{2})$/', '$1.$2.$3/$4-$5', $normalized),
            default => (string) $document,
        };
    }

    private static function validCpf(string $digits): bool
    {
        if (preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
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

    /**
     * Módulo 11 da Receita para CNPJ numérico e alfanumérico: cada posição vale o código ASCII
     * menos 48 (0–9 = 0–9, A = 17 ... Z = 42), pesos 2 a 9 da direita para a esquerda. Para
     * CNPJ só com dígitos o resultado é idêntico ao cálculo tradicional.
     */
    private static function validCnpj(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1+$/', $cnpj)) {
            return false;
        }
        for ($i = 12; $i < 14; $i++) {
            $sum = 0;
            $weight = $i - 7;
            for ($j = 0; $j < $i; $j++) {
                $sum += (ord($cnpj[$j]) - 48) * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }
            $digit = ($sum % 11) < 2 ? 0 : 11 - ($sum % 11);
            if ($digit !== (int) $cnpj[$i]) {
                return false;
            }
        }

        return true;
    }
}
