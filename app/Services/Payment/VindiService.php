<?php

namespace App\Services\Payment;

use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Obtém access_token somente quando o fluxo opcional de autorização foi configurado.
     * Para criar/consultar transações Vindi Pagamentos, a API usa apenas token_account.
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'vindi_access_token_'.md5(config('payments.vindi_consumer_key') ?? '');

        if ($token = Cache::get($cacheKey)) {
            return $token;
        }

        if (config('payments.vindi_access_token') && config('payments.vindi_refresh_token')) {
            return $this->refreshAccessToken(config('payments.vindi_access_token'));
        }

        if (! config('payments.vindi_authorization_code')) {
            throw new \RuntimeException(
                'Vindi access_token não configurado. Criação de transações usa apenas VINDI_TOKEN_ACCOUNT; endpoints protegidos exigem authorization code ou refresh token.'
            );
        }

        $response = Http::post($this->authorizationUrl('/api/authorizations/access_token'), [
            'consumer_key' => config('payments.vindi_consumer_key'),
            'consumer_secret' => config('payments.vindi_consumer_secret'),
            'code' => config('payments.vindi_authorization_code'),
            'type_response' => 'J',
        ]);

        if ($response->failed()) {
            Log::channel('payments')->error('Vindi access_token falhou', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Vindi OAuth error {$response->status()}: {$response->body()}");
        }

        $token = $response->json('data_response.authorization.access_token');

        if (empty($token)) {
            Log::channel('payments')->error('Vindi access_token resposta sem token', ['body' => $response->json()]);
            throw new \RuntimeException('Vindi: resposta sem access_token');
        }

        Cache::put($cacheKey, $token, now()->addHours(23));

        if ($refreshToken = $response->json('data_response.authorization.refresh_token')) {
            Cache::put($this->refreshTokenCacheKey(), $refreshToken, now()->addMonths(11));
        }

        Log::channel('payments')->info('Vindi access_token obtido', [
            'expires_at' => $response->json('data_response.authorization.access_token_expiration'),
        ]);

        return $token;
    }

    public function refreshAccessToken(?string $accessToken = null): string
    {
        $cacheKey = 'vindi_access_token_'.md5(config('payments.vindi_consumer_key') ?? '');
        $refreshToken = Cache::get($this->refreshTokenCacheKey()) ?? config('payments.vindi_refresh_token');

        $response = Http::post($this->authorizationUrl('/api/v1/authorizations/refresh'), [
            'access_token' => $accessToken ?? Cache::get($cacheKey) ?? config('payments.vindi_access_token'),
            'refresh_token' => $refreshToken,
            'type_response' => 'J',
        ]);

        if ($response->failed()) {
            Log::channel('payments')->error('Vindi refresh_token falhou', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Vindi OAuth refresh error {$response->status()}: {$response->body()}");
        }

        $token = $response->json('data_response.authorization.access_token');

        if (empty($token)) {
            Log::channel('payments')->error('Vindi refresh_token resposta sem token', ['body' => $response->json()]);
            throw new \RuntimeException('Vindi: resposta sem access_token no refresh');
        }

        Cache::put($cacheKey, $token, now()->addHours(23));

        if ($newRefreshToken = $response->json('data_response.authorization.refresh_token')) {
            Cache::put($this->refreshTokenCacheKey(), $newRefreshToken, now()->addMonths(11));
        }

        return $token;
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

        if ($affiliateEmail) {
            $commissionAmount = round($amount * $affiliatePercentual / 100, 2);
            $payload['affiliates'] = $this->buildAffiliate($affiliateEmail, $commissionAmount);
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
    ): array {
        $payload = $this->buildBasePayload($externalRef);
        $payload['customer'] = $this->buildHolderPayload($holder);

        $basePayment = [
            'payment_method_id' => $this->cardBrandToMethodId($card->number),
            'card_number' => preg_replace('/\D/', '', $card->number),
            'card_name' => $card->holderName,
            'card_expdate_month' => $card->expiryMonth,
            'card_expdate_year' => $card->expiryYear,
            'card_cvv' => $card->ccv,
        ];

        if ($affiliateEmail) {
            $commissionAmount = round($amount * $affiliatePercentual / 100, 2);
            $payload['affiliates'] = $this->buildAffiliate($affiliateEmail, $commissionAmount);
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

    /**
     * Consulta o saldo disponível para antecipação na subconta afiliada da empresa.
     * Retorna o payload completo da Yapay ou null em falha (não bloqueia fluxo).
     */
    public function getAnticipationBalance(string $affiliateToken): ?array
    {
        try {
            $token = $this->getAccessToken();
        } catch (\RuntimeException) {
            return null;
        }

        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/affiliates/anticipations/calculate", [
                'token_account' => $affiliateToken,
            ]);

        if ($response->failed()) {
            Log::channel('payments')->warning('Vindi antecipação balance indisponível', [
                'affiliate_token' => substr($affiliateToken, 0, 8).'...',
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        Log::channel('payments')->debug('Vindi antecipação balance', [
            'affiliate_token' => substr($affiliateToken, 0, 8).'...',
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    /**
     * Solicita antecipação de recebíveis na subconta afiliada da empresa na Yapay.
     * Lança RuntimeException em falha — o chamador deve abortar a operação local.
     */
    public function requestAnticipation(string $affiliateToken, float $amount): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->asForm()
            ->post("{$this->baseUrl}/affiliates/anticipations", [
                'token_account' => $affiliateToken,
                'amount' => number_format($amount, 2, '.', ''),
            ]);

        if ($response->failed()) {
            Log::channel('payments')->error('Vindi antecipação falhou', [
                'affiliate_token' => substr($affiliateToken, 0, 8).'...',
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Vindi anticipation error {$response->status()}: {$response->body()}");
        }

        $result = $response->json();

        Log::channel('payments')->info('Vindi antecipação solicitada', [
            'affiliate_token' => substr($affiliateToken, 0, 8).'...',
            'amount' => $amount,
            'response' => $result,
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

    /**
     * Consulta o saldo real da subconta afiliada de uma empresa na Yapay.
     * Permite reconciliar o saldo interno com o que a Yapay efetivamente retém.
     *
     * Retorna null se o endpoint falhar (não bloquear fluxo do sistema).
     */
    public function getAffiliateBalance(string $affiliateToken): ?float
    {
        $response = Http::get("{$this->baseUrl}/accounts/balance", [
            'token_account' => $affiliateToken,
            'reseller_token' => config('payments.vindi_reseller_token'),
        ]);

        if ($response->failed()) {
            Log::channel('payments')->warning('Vindi saldo afiliado indisponível', [
                'affiliate_token' => substr($affiliateToken, 0, 8).'...',
                'status' => $response->status(),
            ]);

            return null;
        }

        return (float) ($response->json('balance') ?? 0.0);
    }

    /**
     * Lista transações da conta principal por período.
     * Usado pelo VindiReconciliationJob para comparar com CompanyTransactions locais.
     *
     * Retorna array de transações ou [] em caso de falha.
     */
    public function listTransactions(string $startDate, string $endDate, int $page = 1): array
    {
        $response = Http::get("{$this->baseUrl}/sales", [
            'token_account' => config('payments.vindi_token_account'),
            'reseller_token' => config('payments.vindi_reseller_token'),
            'date_from' => $startDate,
            'date_to' => $endDate,
            'page' => $page,
        ]);

        if ($response->failed()) {
            Log::channel('payments')->warning('Vindi listagem de transações falhou', [
                'status' => $response->status(),
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            return [];
        }

        return $response->json('transactions') ?? $response->json() ?? [];
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

    private function tokenizeCard(CreditCardDTO $card): string
    {
        $tokenUrl = str_replace('/api/v3', '/api/v1', $this->baseUrl);
        $response = Http::asForm()->post("{$tokenUrl}/transactions/token_card", [
            'token_account' => config('payments.vindi_token_account'),
            'card_number' => preg_replace('/\D/', '', $card->number),
            'card_name' => $card->holderName,
            'card_expdate_month' => $card->expiryMonth,
            'card_expdate_year' => $card->expiryYear,
            'card_cvv' => $card->ccv,
        ]);

        if ($response->failed()) {
            Log::channel('payments')->error('Vindi tokenização de cartão falhou', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException("Vindi card tokenization error {$response->status()}: {$response->body()}");
        }

        $token = $response->json('data_response.token')
            ?? $response->json('data_response.card.token')
            ?? $response->json('token')
            ?? null;

        if (empty($token)) {
            Log::channel('payments')->error('Vindi tokenização sem token', ['body' => $response->json()]);
            throw new \RuntimeException('Vindi: tokenização sem token');
        }

        return $token;
    }

    private function buildAffiliate(string $affiliateEmail, float $commissionAmount): array
    {
        return [
            [
                'account_email' => $affiliateEmail,
                'commission_amount' => number_format($commissionAmount, 2, '.', ''),
            ],
        ];
    }

    private function cardBrandToMethodId(string $number): string
    {
        $n = preg_replace('/\D/', '', $number);

        if (preg_match('/^4/', $n)) {
            return '3';
        }
        if (preg_match('/^5[1-5]|^2[2-7]/', $n)) {
            return '4';
        }
        if (preg_match('/^3[47]/', $n)) {
            return '5';
        }
        if (preg_match('/^(4011|4312|4389|4514|4576|5041|5066|5067|509|6277|6362|6504|6505|6516|6550)/', $n)) {
            return '16';
        }

        return '4';
    }

    private function authorizationUrl(string $path): string
    {
        $host = config('app.env') !== 'production'
            ? 'https://api.intermediador.sandbox.yapay.com.br'
            : 'https://api.intermediador.yapay.com.br';

        return $host.$path;
    }

    private function refreshTokenCacheKey(): string
    {
        return 'vindi_refresh_token_'.md5(config('payments.vindi_consumer_key') ?? '');
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
