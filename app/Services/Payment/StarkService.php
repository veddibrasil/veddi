<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use StarkBank\Balance;
use StarkBank\BrcodePayment;
use StarkBank\Invoice;
use StarkBank\Project;
use StarkBank\Settings;
use StarkBank\Transfer;

class StarkService implements PaymentGatewayInterface
{
    public function __construct()
    {
        $this->configureProject();
    }

    // ─────────────────────────────────────────────────────────────
    // PIX — Cobrança
    // ─────────────────────────────────────────────────────────────

    /**
     * Cria um Invoice PIX no Stark Bank.
     *
     * @return array{id: string, brcode: string, qr_code_url: string|null, amount: float}
     */
    public function createPixCharge(
        float $amount,
        string $description,
        string $externalRef,
        Customer $customer
    ): array {
        $amountInCents = (int) round($amount * 100);
        $due = new \DateTime('+30 minutes');

        $invoice = new Invoice([
            'amount' => $amountInCents,
            'name' => $customer->name,
            'taxId' => preg_replace('/\D/', '', $customer->tax_id ?? '00000000000'),
            'due' => $due,
            'expiration' => 1800, // 30 minutos em segundos
            'descriptions' => [
                ['key' => 'Pedido', 'value' => mb_substr($description, 0, 20)],
            ],
            'tags' => ['order:'.$externalRef],
        ]);

        $created = Invoice::create([$invoice]);
        $result = $created[0];

        $qrCodeBase64 = null;
        try {
            $qrCodeBase64 = base64_encode(Invoice::qrcode($result->id));
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Stark Bank: falha ao buscar QR code', [
                'invoice_id' => $result->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('payments')->info('Stark Bank Invoice criado', [
            'invoice_id' => $result->id,
            'amount' => $amount,
            'external_ref' => $externalRef,
        ]);

        return [
            'id' => $result->id,
            'brcode' => $result->brcode,
            'qr_code_url' => $qrCodeBase64,
            'amount' => $amount,
        ];
    }

    /**
     * Consulta o status de um Invoice PIX.
     * Retorna: created | expired | overdue | paid | canceled
     */
    public function getPixStatus(string $invoiceId): string
    {
        $invoice = Invoice::get($invoiceId);

        return $invoice->status ?? 'unknown';
    }

    // ─────────────────────────────────────────────────────────────
    // PIX — Pagamento de Brcode (sandbox/teste)
    // ─────────────────────────────────────────────────────────────

    /**
     * Paga um brcode PIX (Invoice ou DynamicBrcode).
     * Útil para simular pagamento no ambiente sandbox.
     *
     * @return array{id: string, status: string}
     */
    public function payBrcode(
        string $brcode,
        string $taxId,
        string $description,
        int $amountInCents
    ): array {
        $payment = BrcodePayment::create([
            new BrcodePayment([
                'brcode' => $brcode,
                'taxId' => preg_replace('/\D/', '', $taxId),
                'description' => mb_substr($description, 0, 100),
                'amount' => $amountInCents,
                'scheduled' => new \DateTime('now'),
                'tags' => ['sandbox-test'],
            ]),
        ])[0];

        Log::channel('payments')->info('Stark Bank BrcodePayment criado', [
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'amount' => $amountInCents,
        ]);

        return [
            'id' => $payment->id,
            'status' => $payment->status,
        ];
    }

    /**
     * Consulta o status de um BrcodePayment pelo ID.
     * Retorna: created | processing | success | failed
     */
    public function getBrcodePaymentStatus(string $paymentId): string
    {
        $payment = BrcodePayment::get($paymentId);

        return $payment->status ?? 'unknown';
    }

    // ─────────────────────────────────────────────────────────────
    // Transferências (Saques)
    // ─────────────────────────────────────────────────────────────

    /**
     * Cria uma transferência PIX ou TED via Stark Bank.
     *
     * Para PIX: $data deve conter 'pix_key' e 'pix_key_type'.
     * Para TED: $data deve conter 'bank_code', 'branch_code', 'account_number',
     *           'account_type', 'account_digit', 'owner_name', 'owner_cpf_cnpj'.
     *
     * @return array{id: string, status: string}
     */
    public function createTransfer(array $data): array
    {
        $amountInCents = (int) round((float) $data['amount'] * 100);

        $transferParams = [
            'amount' => $amountInCents,
            'name' => $data['owner_name'],
            'taxId' => preg_replace('/\D/', '', $data['owner_cpf_cnpj'] ?? ''),
            'externalId' => $data['external_id'] ?? null,
        ];

        if (isset($data['pix_key'])) {
            // PIX por chave
            $transferParams = array_merge($transferParams, [
                'bankCode' => '20018183', // ISPB do Stark Bank (para PIX)
                'branchCode' => '0001',
                'accountNumber' => '00000000-0',
                'accountType' => 'payment',
                'rules' => [
                    new Transfer\Rule(['key' => 'resendingLimit', 'value' => 5]),
                ],
                'tags' => ['withdrawal:'.($data['external_id'] ?? '')],
            ]);

            // Quando há chave PIX, usar DictKey como destino
            $transferParams['bankCode'] = $this->resolvePixKeyBankCode($data['pix_key_type'] ?? 'cpf');
            $transferParams['accountNumber'] = $data['pix_key'];
        } else {
            // TED / transferência bancária
            $transferParams = array_merge($transferParams, [
                'bankCode' => $data['bank_code'],
                'branchCode' => $data['branch_code'] ?? $data['bank_agency'],
                'accountNumber' => $data['account_number'] ?? $data['bank_account'],
                'accountType' => $this->mapAccountType($data['account_type'] ?? 'checking'),
            ]);
        }

        $transfer = new Transfer($transferParams);
        $created = Transfer::create([$transfer]);
        $result = $created[0];

        Log::channel('payments')->info('Stark Bank Transfer criado', [
            'transfer_id' => $result->id,
            'amount' => $data['amount'],
            'status' => $result->status,
        ]);

        return [
            'id' => $result->id,
            'status' => $result->status,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Saldo
    // ─────────────────────────────────────────────────────────────

    /**
     * Retorna o saldo disponível em reais.
     */
    public function getBalance(): float
    {
        $balance = Balance::current();

        return $balance->amount / 100;
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────

    private function configureProject(): void
    {
        $projectId = config('services.stark.project_id');
        $privateKey = config('services.stark.private_key');
        $environment = config('services.stark.environment', 'sandbox');

        if (empty($projectId) || empty($privateKey)) {
            Log::channel('payments')->warning('Stark Bank: credenciais não configuradas');

            return;
        }

        Settings::setUser(new Project([
            'environment' => $environment,
            'id' => $projectId,
            'privateKey' => $privateKey,
        ]));
    }

    /**
     * Mapeia tipo de conta para o formato Stark.
     */
    private function mapAccountType(string $type): string
    {
        return match (strtolower($type)) {
            'corrente', 'checking' => 'checking',
            'poupanca', 'savings' => 'savings',
            'salario', 'salary' => 'salary',
            default => 'checking',
        };
    }

    /**
     * Para PIX por chave, o bankCode do destino não precisa ser do Stark.
     * O Stark resolve internamente via DICT. Retorna um placeholder.
     */
    private function resolvePixKeyBankCode(string $keyType): string
    {
        // Stark Bank resolve via DICT — bankCode não importa para PIX por chave.
        // Usa '20018183' (próprio Stark) como padrão; o SDK lida com o roteamento.
        return '20018183';
    }
}
