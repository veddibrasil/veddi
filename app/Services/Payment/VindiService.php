<?php

namespace App\Services\Payment;

use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\CardBrand;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VindiService
{
    private string $baseUrl;

    public function __construct()
    {
        $sandbox = config('app.env') !== 'production';
        $this->baseUrl = $sandbox
            ? 'https://api.intermediador.sandbox.yapay.com.br/api/v3'
            : 'https://api.intermediador.yapay.com.br/api/v3';
    }

    public function createPixCharge(
        float $amount,
        string $externalRef,
        Customer $customer,
        ?string $affiliateEmail = null,
        float $affiliatePercentual = 100.0,
        array $address = [],
    ): array {
        $payload = $this->buildBasePayload($externalRef);
        $payload['customer'] = $this->buildCustomerPayload($customer, $address);
        $payload['payment'] = ['payment_method_id' => '27'];

        $affiliates = [];
        if ($affiliateEmail) {
            $commissionAmount = round($amount * $affiliatePercentual / 100, 2);
            $affiliates[] = $this->buildAffiliate($affiliateEmail, $commissionAmount);
        }

        if ($affiliates !== []) {
            $payload['affiliates'] = $affiliates;
            $payload['payment']['split'] = '1';
        }

        $payload['transaction']['amount'] = number_format($amount, 2, '.', '');
        $payload['transaction_product'] = [
            [
                'description' => 'Pedido #'.$externalRef,
                'quantity' => '1',
                'price_unit' => number_format($amount, 2, '.', ''),
            ],
        ];

        $result = $this->createTransaction($payload);

        Log::channel('payments')->info('Vindi PIX criado', [
            'external_ref' => $externalRef,
            'transaction_token' => $result['transaction_token'] ?? null,
            'amount' => $amount,
        ]);

        return [
            'transaction_token' => $result['transaction_token'],
            'status' => 'pending',
            'pix_qr_code' => $result['pix_qr_code'] ?? null,
            'pix_copy_paste' => $result['pix_copy_paste'] ?? null,
        ];
    }

    public function createCreditCardCharge(
        float $amount,
        string $externalRef,
        CreditCardDTO $card,
        CreditCardHolderDTO $holder,
        int $installments = 1,
        ?string $affiliateEmail = null,
        float $affiliatePercentual = 100.0,
        ?Company $company = null,
    ): array {
        $payload = $this->buildBasePayload($externalRef);
        $payload['customer'] = $this->buildHolderPayload($holder);

        $basePayment = [
            'payment_method_id' => CardBrand::fromNumber($card->number)->methodId(),
            'card_number' => preg_replace('/\D/', '', $card->number),
            'card_name' => $card->holderName,
            'card_expdate_month' => $card->expiryMonth,
            'card_expdate_year' => $card->expiryYear,
            'card_cvv' => $card->ccv,
        ];

        $affiliates = [];
        if ($affiliateEmail) {
            $commissionAmount = round($amount * $affiliatePercentual / 100, 2);
            $affiliates[] = $this->buildAffiliate($affiliateEmail, $commissionAmount);
        }

        if ($affiliates !== []) {
            $payload['affiliates'] = $affiliates;
            $payload['payment'] = array_merge($basePayment, ['split' => '1']);
            $payload['transaction']['available_payment_methods'] = '3,4,5,6,16,20,25,27';
            $payload['transaction']['max_split_transaction'] = '1';
        } else {
            $payload['payment'] = array_merge($basePayment, ['split' => (string) $installments]);
            $payload['transaction']['available_payment_methods'] = '3,4,5,6,16,20,25,27';
            $payload['transaction']['max_split_transaction'] = (string) $installments;
        }

        $payload['transaction']['price_additional'] = null;
        $payload['transaction']['price_discount'] = null;
        $payload['transaction']['shipping_price'] = null;
        $payload['transaction']['shipping_type'] = 'Frete';
        $payload['transaction_product'] = [
            [
                'description' => 'Pedido #'.$externalRef,
                'quantity' => '1',
                'price_unit' => number_format($amount, 2, '.', ''),
            ],
        ];

        Log::channel('payments')->debug('Vindi payload cartão', [
            'payload' => $payload,
        ]);

        $result = $this->createTransaction($payload);

        Log::channel('payments')->info('Vindi cartão criado', [
            'external_ref' => $externalRef,
            'transaction_token' => $result['transaction_token'] ?? null,
            'amount' => $amount,
            'installments' => $installments,
        ]);

        return [
            'transaction_token' => $result['transaction_token'],
            'status' => 'pending',
            'status_name' => $result['status_name'] ?? 'pending',
        ];
    }

    public function getTransactionStatus(string $transactionToken): string
    {
        $response = Http::get("{$this->baseUrl}/transactions/{$transactionToken}", [
            'token_account' => config('payments.vindi_token_account'),
            'reseller_token' => config('payments.vindi_reseller_token'),
        ]);

        $data = $response->json();

        return $data['data_response']['transaction']['status_name'] ?? 'unknown';
    }

    private function createTransaction(array $payload): array
    {
        $response = Http::asJson()->post("{$this->baseUrl}/transactions/payment", $payload);

        $data = $response->json();

        Log::channel('payments')->debug('Vindi createTransaction resposta', [
            'status' => $response->status(),
            'response' => $data,
            'payload' => $payload,
        ]);

        // Yapay sometimes returns HTTP 4xx with validation warnings but still creates the transaction.
        // Check additional_data for a valid token before deciding to fail.
        $fallbackToken = $data['additional_data']['token_transaction'] ?? null;
        $fallbackStatus = $data['additional_data']['status_name'] ?? null;

        if ($response->failed() && ! $fallbackToken) {
            Log::channel('payments')->error('Vindi API erro', [
                'body' => json_encode($data ?? $response->body()),
                'payload' => $payload,
            ]);

            throw new \RuntimeException("Vindi API error {$response->status()}: {$response->body()}");
        }

        if ($response->failed() && $fallbackToken) {
            $validationErrors = $data['error_response']['validation_errors'] ?? [];
            Log::channel('payments')->warning('Vindi soft validation error — transação criada via additional_data', [
                'token' => $fallbackToken,
                'status' => $fallbackStatus,
                'validation_errors' => $validationErrors,
            ]);
        }

        $transaction = $data['data_response']['transaction'] ?? null;
        $tokenTransaction = $transaction['token_transaction'] ?? $fallbackToken ?? null;

        if (! $tokenTransaction) {
            Log::channel('payments')->error('Vindi resposta inesperada', ['body' => $data]);
            throw new \RuntimeException('Vindi: resposta sem transaction token');
        }

        $result = [
            'transaction_token' => $tokenTransaction,
            'status_name' => $transaction['status_name'] ?? $fallbackStatus ?? 'pending',
        ];

        $payment = $transaction['payment'] ?? [];
        if (! empty($payment['qrcode_original_path'])) {
            $result['pix_qr_code'] = $payment['qrcode_path'] ?? null;
            $result['pix_copy_paste'] = $payment['qrcode_original_path'];
        }

        return $result;
    }

    private function buildBasePayload(string $externalRef): array
    {
        return [
            'token_account' => config('payments.vindi_token_account'),
            'reseller_token' => config('payments.vindi_reseller_token'),
            'finger_print' => null,
            'transaction' => [
                'order_number' => $externalRef,
                'customer_ip' => request()->ip() ?? '127.0.0.1',
                'url_notification' => route('webhook.vindi'),
            ],
        ];
    }

    private function buildCustomerPayload(Customer $customer, array $address = []): array
    {
        $taxId = preg_replace('/\D/', '', $customer->tax_id ?? '');
        $isCnpj = strlen($taxId) === 14;

        $data = [
            'name' => $customer->name,
            'email' => $customer->email,
            'cpf' => $isCnpj ? null : $taxId,
            'cnpj' => $isCnpj ? $taxId : null,
            'company_name' => null,
            'trade_name' => null,
        ];

        $phone = preg_replace('/\D/', '', $customer->phone ?? '');
        if ($phone !== '') {
            $data['contacts'] = [
                [
                    'type_contact' => strlen($phone) === 11 ? 'M' : 'H',
                    'number_contact' => $phone,
                ],
            ];
        }

        $rawPostal = $address['postal_code'] ?? $customer->cep ?? '';
        $digits = preg_replace('/\D/', '', $rawPostal);
        $postalCode = strlen($digits) === 8
            ? substr($digits, 0, 5).'-'.substr($digits, 5)
            : $rawPostal;

        $stateRaw = strtoupper(trim((string) ($address['state'] ?? $customer->state ?? '')));
        if ($stateRaw === '') {
            $stateRaw = $this->inferStateFromPostalCode($digits);
        }
        $state = $this->stateFullName($stateRaw);

        $data['addresses'] = [
            [
                'type_address' => 'D',
                'street' => trim((string) ($address['street'] ?? $customer->address ?? '')),
                'number' => trim((string) ($address['number'] ?? $customer->number ?? '')),
                'completion' => trim((string) ($address['complement'] ?? $customer->complement ?? '')),
                'neighborhood' => trim((string) ($address['neighborhood'] ?? $customer->neighborhood ?? '')),
                'city' => trim((string) ($address['city'] ?? $customer->city ?? '')),
                'state' => $state,
                'postal_code' => $postalCode,
            ],
        ];

        return $data;
    }

    private function buildHolderPayload(CreditCardHolderDTO $holder): array
    {
        $cpfCnpj = preg_replace('/\D/', '', $holder->cpfCnpj);
        $isCnpj = strlen($cpfCnpj) === 14;

        $data = [
            'name' => $holder->name,
            'email' => $holder->email,
            'cpf' => $isCnpj ? null : $cpfCnpj,
            'cnpj' => $isCnpj ? $cpfCnpj : null,
            'company_name' => null,
            'trade_name' => null,
        ];

        $phone = preg_replace('/\D/', '', $holder->mobilePhone ?? $holder->phone ?? '');
        if ($phone !== '') {
            $data['contacts'] = [
                [
                    'type_contact' => strlen($phone) === 11 ? 'M' : 'H',
                    'number_contact' => $phone,
                ],
            ];
        }

        $digits = preg_replace('/\D/', '', $holder->postalCode ?? '');
        $postalCode = strlen($digits) === 8
            ? substr($digits, 0, 5).'-'.substr($digits, 5)
            : ($holder->postalCode ?? '');

        $stateRaw = strtoupper(trim((string) ($holder->state ?? '')));
        if ($stateRaw === '') {
            $stateRaw = $this->inferStateFromPostalCode($digits);
        }
        $state = $this->stateFullName($stateRaw);

        $data['addresses'] = [
            [
                'type_address' => 'D',
                'street' => trim((string) ($holder->street ?? '')),
                'number' => trim((string) ($holder->addressNumber ?? '')),
                'completion' => trim((string) ($holder->complement ?? '')),
                'neighborhood' => trim((string) ($holder->neighborhood ?? '')),
                'city' => trim((string) ($holder->city ?? '')),
                'state' => $state,
                'postal_code' => $postalCode,
            ],
        ];

        return $data;
    }

    private function buildAffiliate(string $affiliateEmail, float $commissionAmount): array
    {
        return [
            'account_email' => $affiliateEmail,
            'commission_amount' => number_format($commissionAmount, 2, '.', ''),
        ];
    }

    private function stateFullName(string $abbr): string
    {
        return match (strtoupper($abbr)) {
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Santo',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondônia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'São Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins',
            default => $abbr,
        };
    }

    private function inferStateFromPostalCode(string $postalCode): string
    {
        if (strlen($postalCode) !== 8) {
            return '';
        }

        $cep = (int) substr($postalCode, 0, 5);

        return match (true) {
            $cep <= 19999 => 'SP',
            $cep <= 28999 => 'RJ',
            $cep <= 29999 => 'ES',
            $cep <= 39999 => 'MG',
            $cep <= 48999 => 'BA',
            $cep <= 49999 => 'SE',
            $cep <= 56999 => 'PE',
            $cep <= 57999 => 'AL',
            $cep <= 58999 => 'PB',
            $cep <= 59999 => 'RN',
            $cep <= 63999 => 'CE',
            $cep <= 64999 => 'PI',
            $cep <= 65999 => 'MA',
            $cep <= 68899 => 'PA',
            $cep <= 68999 => 'AP',
            $cep <= 69299 => 'AM',
            $cep <= 69399 => 'RR',
            $cep <= 69899 => 'AM',
            $cep <= 69999 => 'AC',
            $cep <= 72799 => 'DF',
            $cep <= 72999 => 'GO',
            $cep <= 73699 => 'DF',
            $cep <= 76799 => 'GO',
            $cep <= 76999 => 'RO',
            $cep <= 77999 => 'TO',
            $cep <= 78899 => 'MT',
            $cep <= 78999 => 'RO',
            $cep <= 79999 => 'MS',
            $cep <= 87999 => 'PR',
            $cep <= 89999 => 'SC',
            $cep <= 99999 => 'RS',
            default => '',
        };
    }
}
