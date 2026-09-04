<?php

namespace App\Services\Payment;

use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\CardBrand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentOrchestrator
{
    public function __construct(
        private readonly VindiService $vindi,
    ) {}

    /**
     * Vindi's order_number is unique per token_account, não por banco. Ambientes
     * distintos (staging A, staging B) compartilhando as mesmas credenciais Vindi
     * reusam o mesmo autoincrement de order.id, colidindo remotamente. Prefixamos
     * com um hash curto do nome do banco pra namespacear por ambiente.
     */
    private function vindiExternalRef(Order $order): string
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $namespace = substr(md5($database), 0, 6);

        return "{$namespace}-{$order->id}";
    }

    // ─────────────────────────────────────────────────────────────
    // Roteamento principal
    // ─────────────────────────────────────────────────────────────

    /**
     * Decide qual gateway usar com base no método e cria a cobrança.
     *
     * Retorna resposta padronizada:
     * {
     *   "id":         "...",
     *   "status":     "pending",
     *   "method":     "pix" | "credit_card" | "cash",
     *   "gateway":    "vindi" | "cash" | "card_machine",
     *   "qr_code":    "...",   // só PIX
     *   "copy_paste": "...",   // só PIX
     * }
     */
    public function process(
        Order $order,
        Customer $customer,
        Company $company,
        string $method,
        array $cardData = [],
        int $installments = 1,
    ): array {
        return match (strtoupper($method)) {
            'CREDIT_CARD' => $this->processCreditCard($order, $customer, $company, $cardData, $installments),
            'CASH' => $this->processCash($order),
            'CARD_MACHINE' => $this->processCardMachine($order),
            default => $this->processPix($order, $customer, $company),
        };
    }

    /**
     * Processa PIX via Vindi Intermediador e persiste o Payment.
     */
    public function processPix(
        Order $order,
        Customer $customer,
        Company $company
    ): array {
        $chargeAmount = (float) $order->total;
        $affiliateEmail = $company->email;

        if (! $affiliateEmail && config('app.env') === 'production') {
            Log::channel('discord')->critical('Empresa sem email em produção — pagamento PIX sem split de afiliado', [
                'type' => 'payments',
                'company_id' => $company->id,
                'order_id' => $order->id,
                'amount' => $chargeAmount,
            ]);
        }

        Log::channel('discord_pix')->info('PIX: iniciando geração de cobrança', [
            'order_id' => $order->id,
            'company_id' => $company->id,
            'amount' => $chargeAmount,
            'has_affiliate' => (bool) $affiliateEmail,
        ]);

        $gatewayRate = (float) config('payments.vindi_pix_rate', 0.0085);
        $platformRate = (float) config('payments.vindi_pix_platform_rate', 0.0014);
        $planExtraRate = $company->feePercentageForOrder($order);

        // Same rule as card: platform commission (1%/3%) applies to the actual
        // net received after gateway fees — not the inflated charge — with
        // delivery fee carved out first.
        $netAfterGateway = round($chargeAmount * (1.0 - $gatewayRate - $platformRate), 3);
        $deliveryFee = (float) ($order->delivery_fee ?? 0);
        $platformFeeBase = round($netAfterGateway - $deliveryFee, 3);
        $commissionAmount = round($platformFeeBase * $planExtraRate, 3);
        $affiliateAmount = round($netAfterGateway - $commissionAmount, 3);
        $affiliatePercentual = $chargeAmount > 0
            ? round($affiliateAmount / $chargeAmount * 100, 4)
            : round((1.0 - $gatewayRate - $platformRate - $planExtraRate) * 100, 4);

        Log::channel('payments')->info('Orchestrator: criando cobrança PIX via Vindi', [
            'order_id' => $order->id,
            'subtotal' => $order->subtotal,
            'delivery_fee' => $order->delivery_fee,
            'charge_amount' => $chargeAmount,
            'commission_amount' => $commissionAmount,
            'platform_fee_base' => $platformFeeBase,
            'affiliate_percentual' => $affiliatePercentual,
            'has_affiliate' => (bool) $affiliateEmail,
            'gateway_rate_pct' => round($gatewayRate * 100, 3).'%',
            'platform_rate_pct' => round($platformRate * 100, 3).'%',
            'plan_extra_rate_pct' => round($planExtraRate * 100, 3).'%',
        ]);

        $tokenAccount = config('payments.vindi_token_account');

        if (! $tokenAccount && config('app.env') === 'production') {
            Log::channel('discord')->critical('Produção sem credenciais Vindi configuradas — PIX caindo em modo simulação', [
                'type' => 'payments',
                'order_id' => $order->id,
                'company_id' => $company->id,
            ]);
        }

        if ($tokenAccount) {
            try {
                $result = $this->vindi->createPixCharge(
                    amount: $chargeAmount,
                    externalRef: $this->vindiExternalRef($order),
                    customer: $customer,
                    affiliateEmail: $affiliateEmail,
                    affiliatePercentual: $affiliatePercentual,
                    address: $this->vindiAddressFromOrder($order, $customer),
                    deliveryFee: (float) ($order->delivery_fee ?? 0),
                );
            } catch (\Throwable $e) {
                Log::channel('discord_pix')->error('PIX: falha ao criar cobrança via Vindi', [
                    'order_id' => $order->id,
                    'company_id' => $company->id,
                    'amount' => $chargeAmount,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'vindi_transaction_token' => $result['transaction_token'],
                'vindi_transaction_id' => $result['transaction_id'] ?? null,
                'payment_gateway' => 'vindi',
                'pix_qr_code' => $result['pix_qr_code'],
                'pix_copy_paste' => $result['pix_copy_paste'],
                'amount' => $chargeAmount,
                'pix_fee' => 0.0,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(30),
                'payment_token' => hash('sha256', $order->id.$customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('PIX Vindi criado', [
                'order_id' => $order->id,
                'vindi_transaction_token' => $result['transaction_token'],
                'charge_amount' => $chargeAmount,
            ]);

            Log::channel('discord_pix')->info('PIX: cobrança criada com sucesso', [
                'order_id' => $order->id,
                'vindi_transaction_token' => $result['transaction_token'],
                'amount' => $chargeAmount,
                'has_qr_code' => ! empty($result['pix_qr_code']),
            ]);
        } else {
            // Modo simulação (sem credenciais Vindi configuradas)
            $companyName = $company->name ?? config('app.name');
            $payment = Payment::create([
                'order_id' => $order->id,
                'vindi_transaction_token' => 'sim_vindi_'.uniqid(),
                'payment_gateway' => 'vindi',
                'pix_qr_code' => null,
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix0136SIMULACAO-VINDI52040000530398654'
                    .number_format($chargeAmount, 2, '', '')
                    .'5802BR5924'
                    .mb_substr(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($companyName)), 0, 25)
                    .'6009SAO PAULO62070503***6304ABCD',
                'amount' => $chargeAmount,
                'pix_fee' => 0.0,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(30),
                'payment_token' => hash('sha256', $order->id.$customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('PIX Vindi simulado (sem credenciais)', [
                'order_id' => $order->id,
            ]);

            Log::channel('discord_pix')->info('PIX: cobrança simulada (sem credenciais Vindi)', [
                'order_id' => $order->id,
                'amount' => $chargeAmount,
            ]);
        }

        return [
            'id' => $payment->vindi_transaction_token,
            'status' => 'pending',
            'method' => 'pix',
            'gateway' => 'vindi',
            'qr_code' => $payment->pix_qr_code,
            'copy_paste' => $payment->pix_copy_paste,
        ];
    }

    /**
     * Processa cartão de crédito via Vindi Intermediador, persiste Payment e, se aprovado,
     * credita carteira e registra transação.
     *
     * @return array{id: string|null, approved: bool, status: string, method: string, gateway: string}
     */
    public function processCreditCard(
        Order $order,
        Customer $customer,
        Company $company,
        array $cardData,
        int $installments = 1,
    ): array {
        $affiliateEmail = $company->email;

        if (! $affiliateEmail && config('app.env') === 'production') {
            Log::channel('discord')->critical('Empresa sem email em produção — pagamento cartão sem split de afiliado', [
                'type' => 'payments',
                'company_id' => $company->id,
                'order_id' => $order->id,
                'amount' => (float) $order->total,
            ]);
        }

        $savedCard = null;
        if (! empty($cardData['saved_card_id'])) {
            $savedCard = $customer->cards()->find($cardData['saved_card_id']);

            if (! $savedCard) {
                throw new \RuntimeException('Cartão salvo não encontrado.');
            }
        }

        $brand = $savedCard ? CardBrand::from($savedCard->brand) : CardBrand::fromNumber($cardData['number'] ?? '');
        $cardRate = $brand->rate();
        $cardFeeAbsorbed = (bool) ($company->card_fee_absorbed_by_company ?? false);

        // Fee not absorbed: inflate charge so customer pays the card fee
        // Fee absorbed: company takes the hit from their net
        $chargeAmount = (! $cardFeeAbsorbed && $cardRate < 1.0)
            ? round((float) $order->total / (1.0 - $cardRate), 3)
            : (float) $order->total;

        $cardFee = round($chargeAmount * $cardRate, 3);
        $planFeeRate = $company->feePercentageForOrder($order);

        // Platform commission (1%/3%) is applied straight to the actual amount received
        // after the card fee — not inflated by it — with delivery fee carved out first.
        $netAfterCard = round($chargeAmount - $cardFee, 3);
        $deliveryFee = (float) ($order->delivery_fee ?? 0);
        $platformFeeBase = round($netAfterCard - $deliveryFee, 3);
        $platformFeeAmount = round($platformFeeBase * $planFeeRate, 3);
        $targetCompanyNet = round($netAfterCard - $platformFeeAmount, 3);
        $affiliatePercentual = $chargeAmount > 0
            ? round($targetCompanyNet / $chargeAmount * 100, 4)
            : round((1.0 - $planFeeRate) * 100, 4);

        Log::channel('payments')->info('Orchestrator: criando cobrança cartão via Vindi', [
            'order_id' => $order->id,
            'original_amount' => $order->total,
            'charge_amount' => $chargeAmount,
            'installments' => $installments,
            'card_brand' => $brand->value,
            'card_rate_pct' => round($cardRate * 100, 3).'%',
            'card_fee_absorbed' => $cardFeeAbsorbed,
            'plan_fee_pct' => round($planFeeRate * 100, 3).'%',
            'platform_fee_base' => $platformFeeBase,
            'affiliate_percentual' => $affiliatePercentual,
            'has_affiliate' => (bool) $affiliateEmail,
        ]);

        $tokenAccount = config('payments.vindi_token_account');

        if (! $tokenAccount && config('app.env') === 'production') {
            Log::channel('discord')->critical('Produção sem credenciais Vindi configuradas — cartão caindo em modo simulação', [
                'type' => 'payments',
                'order_id' => $order->id,
                'company_id' => $company->id,
            ]);
        }

        if ($tokenAccount) {
            $vindiAddress = $this->vindiAddressFromOrder($order, $customer);

            try {
                $result = $this->vindi->createCreditCardCharge(
                    amount: $chargeAmount,
                    externalRef: $this->vindiExternalRef($order),
                    card: new CreditCardDTO(
                        holderName: $savedCard ? ($customer->name ?? '') : $cardData['holderName'],
                        number: $savedCard ? '' : $cardData['number'],
                        expiryMonth: $savedCard ? '' : $cardData['expiryMonth'],
                        expiryYear: $savedCard ? '' : $cardData['expiryYear'],
                        ccv: $cardData['ccv'],
                        token: $savedCard?->vindi_card_token,
                    ),
                    holder: new CreditCardHolderDTO(
                        name: $customer->name,
                        email: $customer->email ?? '',
                        cpfCnpj: $cardData['cpfCnpj'] ?? $customer->tax_id ?? '',
                        postalCode: $cardData['postalCode'] ?? $vindiAddress['postal_code'] ?? '',
                        addressNumber: $cardData['addressNumber'] ?? $vindiAddress['number'] ?? 'S/N',
                        phone: $customer->phone ?? null,
                        street: $vindiAddress['street'],
                        complement: $vindiAddress['complement'],
                        neighborhood: $vindiAddress['neighborhood'],
                        city: $vindiAddress['city'],
                        state: $vindiAddress['state'],
                    ),
                    brand: $brand,
                    installments: $installments,
                    affiliateEmail: $affiliateEmail,
                    affiliatePercentual: $affiliatePercentual,
                    company: $company,
                    deliveryFee: (float) ($order->delivery_fee ?? 0),
                );
            } catch (\Throwable $e) {
                Log::channel('discord')->error('Cartão: falha ao criar cobrança via Vindi', [
                    'type' => 'payments',
                    'order_id' => $order->id,
                    'company_id' => $company->id,
                    'amount' => $chargeAmount,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            $transactionToken = $result['transaction_token'];
            $transactionId = $result['transaction_id'] ?? null;
            $approved = ($result['status_name'] ?? '') === 'Aprovada';
            $cardToken = $result['card_token'] ?? null;

            // Mesmo conjunto de status finais de falha usado pelo webhook
            // (ProcessVindiWebhook::handlePaymentFailed) — distingue recusa
            // definitiva de status intermediário (ex: análise antifraude
            // assíncrona), que ainda aguarda confirmação por webhook.
            $declined = in_array($result['status_name'] ?? '', ['Cancelada', 'Não Aprovada', 'Reprovada'], true);
            $declineReason = $result['payment_response'] ?? null;

            Log::channel('payments')->info('Cartão Vindi criado', [
                'order_id' => $order->id,
                'vindi_transaction_token' => $transactionToken,
                'charge_amount' => $chargeAmount,
                'installments' => $installments,
                'approved' => $approved,
                'declined' => $declined,
                'status_name' => $result['status_name'] ?? null,
            ]);
        } else {
            // Modo simulação (sem credenciais Vindi configuradas): aprova
            // direto, igual à maquininha/dinheiro no PDV, pra não travar o
            // fluxo de dev esperando um webhook que nunca vai chegar.
            $transactionToken = 'sim_vindi_'.uniqid();
            $transactionId = null;
            $approved = true;
            $cardToken = null;
            $declined = false;
            $declineReason = null;

            Log::channel('payments')->info('Cartão Vindi simulado (sem credenciais)', [
                'order_id' => $order->id,
                'charge_amount' => $chargeAmount,
            ]);
        }

        $paymentToken = hash('sha256', $order->id.$customer->id.Str::random(32));

        try {
            DB::transaction(function () use ($order, $customer, $cardData, $savedCard, $brand, $cardToken, $transactionToken, $transactionId, $chargeAmount, $cardFee, $cardRate, $installments, $paymentToken, $approved) {
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'vindi_transaction_token' => $transactionToken,
                    'vindi_transaction_id' => $transactionId,
                    'payment_gateway' => 'vindi',
                    'amount' => $chargeAmount,
                    'original_amount' => (float) $order->total,
                    'card_fee' => $cardFee,
                    'card_fee_rate' => $cardRate,
                    'installments' => $installments,
                    'status' => $approved ? 'paid' : 'pending',
                    'paid_at' => $approved ? now() : null,
                    'payment_token' => $paymentToken,
                ]);

                if ($approved) {
                    $order->update(['status' => 'paid']);
                    $fresh = $order->fresh();
                    app(WalletServiceInterface::class)->creditForOrder($fresh, $payment);
                    app(TransactionServiceInterface::class)->createForPayment($fresh, $payment);
                }

                // Vindi retorna um card_token novo a cada cobrança aprovada (mesmo
                // reusando um salvo) — atualiza sempre, senão o token salvo expira.
                if ($approved && $cardToken) {
                    if ($savedCard) {
                        $savedCard->update(['vindi_card_token' => $cardToken]);
                    } else {
                        $customer->saveVindiCardToken($cardToken, $cardData['number'] ?? '', $brand->value);
                    }
                }
            });
        } catch (\Throwable $e) {
            // Vindi já pode ter aprovado a cobrança (ver log "Cartão Vindi criado" acima)
            // mas o commit local falhou e a transação foi revertida — cliente cobrado,
            // pedido não marcado como pago. Precisa de reconciliação manual.
            Log::channel('discord')->critical('Cartão: transação local falhou após resposta da Vindi — possível cobrança sem pedido pago', [
                'type' => 'payments',
                'order_id' => $order->id,
                'vindi_transaction_token' => $transactionToken,
                'gateway_approved' => $approved,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::channel('payments')->info('Cartão: pagamento local persistido', [
            'order_id' => $order->id,
            'vindi_transaction_token' => $transactionToken,
            'approved' => $approved,
        ]);

        return [
            'id' => $transactionToken,
            'approved' => $approved,
            'status' => $approved ? 'paid' : 'pending',
            'method' => 'credit_card',
            'gateway' => 'vindi',
            'declined' => $declined,
            'decline_reason' => $declineReason,
        ];
    }

    /**
     * Pagamento em dinheiro (PDV). Cria Payment marcado como pago imediatamente.
     * Troco calculado com base em cash_received salvo no pedido.
     */
    public function processCash(Order $order, ?float $amount = null, ?float $cashReceived = null): array
    {
        $chargeAmount = $amount ?? (float) $order->total;
        $received = $cashReceived ?? (float) ($order->cash_received ?? $chargeAmount);
        $change = max(0.0, round($received - $chargeAmount, 2));

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'cash',
            'amount' => $chargeAmount,
            'pix_fee' => 0.0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_token' => hash('sha256', 'cash'.$order->id.now()->timestamp.Str::random(8)),
        ]);

        Log::channel('payments')->info('Pagamento em dinheiro registrado (PDV)', [
            'order_id' => $order->id,
            'amount' => $chargeAmount,
            'cash_received' => $received,
            'change' => $change,
        ]);

        return [
            'id' => $payment->id,
            'status' => 'paid',
            'method' => 'cash',
            'gateway' => 'cash',
            'change' => $change,
        ];
    }

    /**
     * Confirma pagamento coletado no ato da entrega (PDV "receber na entrega").
     * O pedido nasceu 'awaiting_payment' sem Payment; aqui cria o Payment e marca 'paid'.
     */
    public function confirmDeliveryPayment(Order $order): array
    {
        $gateway = match ($order->payment_method) {
            'cash' => 'cash',
            'credit_card' => 'card_machine',
            'pix' => 'pix_manual',
            default => $order->payment_method,
        };

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => $gateway,
            'amount' => (float) $order->total,
            'pix_fee' => 0.0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_token' => hash('sha256', 'delivery'.$order->id.now()->timestamp),
        ]);

        $order->update(['status' => 'paid']);

        Log::channel('payments')->info('Pagamento na entrega confirmado (PDV)', [
            'order_id' => $order->id,
            'amount' => $order->total,
            'gateway' => $gateway,
        ]);

        return [
            'id' => $payment->id,
            'status' => 'paid',
            'gateway' => $gateway,
        ];
    }

    /**
     * Pedido do iFood chega pré-pago — o iFood já custodiou o pagamento, não a
     * Vindi/Asaas do Veddi. Só registra o Payment local (mesmo racional de
     * processCash/processCardMachine); não credita carteira aqui — o crédito
     * líquido real (descontada a comissão do iFood) vem só da conciliação de
     * repasses (Fase 5.2), que lê o extrato real da Financial API do iFood.
     */
    public function processIfoodPrepaid(Order $order): array
    {
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'ifood',
            'amount' => (float) $order->total,
            'pix_fee' => 0.0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_token' => hash('sha256', 'ifood'.$order->id.now()->timestamp.Str::random(8)),
        ]);

        Log::channel('payments')->info('Pagamento iFood (pré-pago) registrado', [
            'order_id' => $order->id,
            'amount' => $order->total,
        ]);

        return [
            'id' => $payment->id,
            'status' => 'paid',
            'gateway' => 'ifood',
        ];
    }

    private function vindiAddressFromOrder(Order $order, Customer $customer): array
    {
        $branch = $order->branch;

        return [
            'street' => $order->delivery_address ?? $customer->address ?? $branch?->address,
            'number' => $order->delivery_number ?? $customer->number ?? $branch?->number,
            'complement' => $order->delivery_complement ?? $customer->complement ?? $branch?->complement,
            'neighborhood' => $order->delivery_neighborhood ?? $customer->neighborhood ?? $branch?->neighborhood,
            'city' => $order->delivery_city ?? $customer->city ?? $branch?->city,
            'state' => $customer->state ?? $branch?->state,
            'postal_code' => $order->delivery_cep ?? $customer->cep ?? $branch?->cep,
        ];
    }

    /**
     * Maquininha física no PDV — operador cobrou no terminal externo, só registra.
     */
    public function processCardMachine(Order $order, ?float $amount = null): array
    {
        $chargeAmount = $amount ?? (float) $order->total;

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'card_machine',
            'amount' => $chargeAmount,
            'pix_fee' => 0.0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_token' => hash('sha256', 'card'.$order->id.now()->timestamp.Str::random(8)),
        ]);

        Log::channel('payments')->info('Pagamento em cartão (maquininha PDV) registrado', [
            'order_id' => $order->id,
            'amount' => $chargeAmount,
        ]);

        return [
            'id' => $payment->id,
            'status' => 'paid',
            'method' => 'credit_card',
            'gateway' => 'card_machine',
        ];
    }

    /**
     * Pix manual no PDV — operador confirmou o recebimento (ex: pix na maquininha
     * ou QR estático da empresa), só registra. Sem geração de cobrança no gateway.
     */
    public function processPixManual(Order $order, ?float $amount = null): array
    {
        $chargeAmount = $amount ?? (float) $order->total;

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'pix_manual',
            'amount' => $chargeAmount,
            'pix_fee' => 0.0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_token' => hash('sha256', 'pix'.$order->id.now()->timestamp.Str::random(8)),
        ]);

        Log::channel('payments')->info('Pagamento Pix manual (PDV) registrado', [
            'order_id' => $order->id,
            'amount' => $chargeAmount,
        ]);

        return [
            'id' => $payment->id,
            'status' => 'paid',
            'method' => 'pix',
            'gateway' => 'pix_manual',
        ];
    }

    /**
     * Processa múltiplas partes de pagamento pro mesmo pedido (split no PDV). Cada
     * parte vira um Payment próprio via processCash/processCardMachine/processPixManual.
     * A soma já foi validada na camada Livewire antes de abrir a transação — aqui é
     * defesa em profundidade, não a validação principal.
     *
     * @param  array<int, array{method: string, amount: float, cash_received?: float}>  $parts
     * @return array<int, array>
     */
    public function processSplit(Order $order, array $parts): array
    {
        $sum = round(array_sum(array_column($parts, 'amount')), 2);

        if (abs($sum - (float) $order->total) > 0.01) {
            throw new \RuntimeException('Soma das partes do pagamento não bate com o total do pedido.');
        }

        $results = [];
        foreach ($parts as $part) {
            $results[] = match ($part['method']) {
                'cash' => $this->processCash($order, (float) $part['amount'], $part['cash_received'] ?? null),
                'credit_card' => $this->processCardMachine($order, (float) $part['amount']),
                'pix' => $this->processPixManual($order, (float) $part['amount']),
                default => throw new \RuntimeException("Método inválido no split: {$part['method']}"),
            };
        }

        return $results;
    }
}
