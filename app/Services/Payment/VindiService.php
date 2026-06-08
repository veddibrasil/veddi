<?php

namespace App\Services\Payment;

use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
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
        ?string $affiliateToken = null,
        float $affiliatePercentual = 100.0,
    ): array {
        $payload = $this->buildBasePayload($externalRef);
        $payload['customer'] = $this->buildCustomer($customer);
        $payload['payment'] = ['type_payment' => 'pix'];

        if ($affiliateToken) {
            $payload['affiliates'] = $this->buildAffiliate($affiliateToken, $affiliatePercentual);
        }

        $payload['transaction']['amount'] = number_format($amount, 2, '.', '');

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
        ?string $affiliateToken = null,
        float $affiliatePercentual = 100.0,
    ): array {
        $payload = $this->buildBasePayload($externalRef);
        $payload['customer'] = [
            'name' => $holder->name,
            'cpf' => preg_replace('/\D/', '', $holder->cpfCnpj),
            'email' => $holder->email,
        ];
        $payload['payment'] = [
            'type_payment' => $this->detectCardBrand($card->number),
            'card_number' => preg_replace('/\D/', '', $card->number),
            'card_expdate_month' => $card->expiryMonth,
            'card_expdate_year' => $card->expiryYear,
            'card_cvv' => $card->ccv,
            'installments' => $installments,
        ];

        if ($affiliateToken) {
            $payload['affiliates'] = $this->buildAffiliate($affiliateToken, $affiliatePercentual);
        }

        $payload['transaction']['amount'] = number_format($amount, 2, '.', '');

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

    /**
     * Solicita saque da subconta afiliada da empresa para a conta bancária/PIX informada.
     *
     * Yapay autentica com o token da empresa (affiliate_token) + reseller_token da plataforma.
     * O saldo debitado é o da subconta da empresa, não da plataforma.
     *
     * $data esperado:
     *   affiliate_token   string  vindi_affiliate_token da empresa
     *   amount            float   valor bruto (sem taxa PIX já descontada)
     *   external_id       string  'withdrawal-{id}'
     *   owner_name        string
     *   owner_cpf_cnpj    string
     *   -- PIX --
     *   pix_key           string
     *   pix_key_type      string  cpf|cnpj|email|phone|random
     *   -- TED --
     *   bank_code         string
     *   bank_agency       string
     *   bank_account      string
     *   account_digit     string
     *   account_type      string  checking|savings
     */
    public function createTransfer(array $data): array
    {
        $payload = [
            'token_account' => $data['affiliate_token'],
            'reseller_token' => config('payments.vindi_reseller_token'),
            'amount' => number_format($data['amount'], 2, '.', ''),
            'order_number' => $data['external_id'],
            'owner_name' => $data['owner_name'],
            'owner_document' => preg_replace('/\D/', '', $data['owner_cpf_cnpj']),
        ];

        if (isset($data['pix_key'])) {
            $payload['pix_key'] = $data['pix_key'];
            $payload['pix_key_type'] = $data['pix_key_type'];
            $endpoint = "{$this->baseUrl}/affiliates/withdrawals/pix";
        } else {
            $payload['bank_code'] = $data['bank_code'];
            $payload['bank_agency'] = $data['bank_agency'];
            $payload['bank_account'] = $data['bank_account'];
            $payload['bank_account_digit'] = $data['account_digit'];
            $payload['bank_account_type'] = $data['account_type'] ?? 'checking';
            $endpoint = "{$this->baseUrl}/affiliates/withdrawals/ted";
        }

        $response = Http::asForm()->post($endpoint, $payload);

        if ($response->failed()) {
            Log::channel('payments')->error('Vindi saque falhou', [
                'external_id' => $data['external_id'],
                'affiliate_token' => $data['affiliate_token'],
                'amount' => $data['amount'],
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Vindi withdrawal error {$response->status()}: {$response->body()}");
        }

        $result = $response->json();

        Log::channel('payments')->info('Vindi saque solicitado', [
            'external_id' => $data['external_id'],
            'affiliate_token' => $data['affiliate_token'],
            'amount' => $data['amount'],
            'id' => $result['id'] ?? $result['withdrawal_id'] ?? null,
        ]);

        return $result;
    }

    public function getBalance(): float
    {
        $response = Http::get("{$this->baseUrl}/accounts/balance", [
            'token_account' => config('payments.vindi_token_account'),
            'reseller_token' => config('payments.vindi_reseller_token'),
        ]);

        if ($response->failed()) {
            Log::channel('payments')->warning('Vindi saldo indisponível', [
                'status' => $response->status(),
            ]);

            return 0.0;
        }

        return (float) ($response->json('balance') ?? 0.0);
    }

    public function getTransactionStatus(string $transactionToken): string
    {
        $response = Http::get("{$this->baseUrl}/transactions/{$transactionToken}", [
            'token_account' => config('payments.vindi_token_account'),
            'reseller_token' => config('payments.vindi_reseller_token'),
        ]);

        $data = $response->json();

        return $data['transaction']['status_name'] ?? 'unknown';
    }

    private function createTransaction(array $payload): array
    {
        $response = Http::asForm()->post("{$this->baseUrl}/transactions/payment", $payload);

        if ($response->failed()) {
            Log::channel('payments')->error('Vindi API erro', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new \RuntimeException("Vindi API error {$response->status()}: {$response->body()}");
        }

        $data = $response->json();
        $transaction = $data['transaction'] ?? null;

        if (! $transaction || empty($transaction['token'])) {
            Log::channel('payments')->error('Vindi resposta inesperada', ['body' => $data]);
            throw new \RuntimeException('Vindi: resposta sem transaction token');
        }

        $result = [
            'transaction_token' => $transaction['token'],
            'status_name' => $transaction['status_name'] ?? 'pending',
        ];

        // PIX: extrai QR code e copia-e-cola
        if (isset($transaction['pix_qr_code'])) {
            $result['pix_qr_code'] = $transaction['pix_qr_code'];
            $result['pix_copy_paste'] = $transaction['pix_copy_paste'] ?? null;
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

    private function buildCustomer(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'cpf' => preg_replace('/\D/', '', $customer->tax_id ?? ''),
            'email' => $customer->email,
        ];
    }

    private function buildAffiliate(string $affiliateToken, float $percentual): array
    {
        return [
            [
                'token_account' => $affiliateToken,
                'percentual' => number_format($percentual, 2, '.', ''),
            ],
        ];
    }

    private function detectCardBrand(string $number): string
    {
        $n = preg_replace('/\D/', '', $number);

        if (preg_match('/^4/', $n)) {
            return 'visa';
        }
        if (preg_match('/^5[1-5]|^2[2-7]/', $n)) {
            return 'master';
        }
        if (preg_match('/^3[47]/', $n)) {
            return 'amex';
        }
        if (preg_match('/^(4011|4312|4389|4514|4576|5041|5066|5067|509|6277|6362|6504|6505|6516|6550)/', $n)) {
            return 'elo';
        }

        return 'master'; // fallback
    }
}
