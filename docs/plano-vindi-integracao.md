# Plano: Migração para Vindi Intermediador (Cartão + PIX com Split)

## Contexto

Substituir Stark Bank (PIX) e Asaas cartão por **Vindi Intermediador (Yapay)** para pagamentos de pedidos.
Asaas permanece apenas para assinaturas e taxa de ativação de empresas.

### Estado atual

| Gateway | Responsabilidade atual |
|---------|----------------------|
| **Stark Bank** | PIX de pedidos (`StarkService`, `ProcessStarkWebhook`, `StarkWebhookController`, `StarkRefundGateway`) |
| **Asaas** | Cartão de pedidos + assinatura/setup fee de empresas |

### Estado final

| Gateway | Responsabilidade |
|---------|-----------------|
| **Vindi Intermediador** | PIX + cartão de pedidos, com split para subconta da empresa |
| **Asaas** | Apenas assinatura mensal e taxa de ativação de empresas |
| **Stark Bank** | Removido |

### Produtos Vindi

- **Vindi Intermediador (Yapay)** — pagamentos avulsos (pedidos)
  - Sandbox: `https://api.intermediador.sandbox.yapay.com.br/api/v3/`
  - Produção: `https://api.intermediador.yapay.com.br/api/v3/`
  - Auth: `token_account` + `reseller_token` no body
  - Split: array `affiliates` com token da subconta e percentual
  - Webhook: `transaction[url_notification]` por transação (form-data POST, retry a cada 12h por 3 dias)

---

## Fase 1 — Banco de dados

### Migration: `payments` table

```php
$table->string('vindi_transaction_token')->nullable()->after('stark_payment_id');
// stark_payment_id e asaas_payment_id mantidos (dados históricos)
```

### Migration: `companies` table

```php
$table->string('vindi_affiliate_token')->nullable();
```

### `config/payments.php`

```php
// Renomear stark_pix_fee → vindi_pix_fee
'vindi_pix_fee'         => env('VINDI_PIX_FEE', 0.50),

// Adicionar
'vindi_token_account'   => env('VINDI_TOKEN_ACCOUNT'),
'vindi_reseller_token'  => env('VINDI_RESELLER_TOKEN'),
```

### `.env` (novas variáveis)

```env
VINDI_TOKEN_ACCOUNT=
VINDI_RESELLER_TOKEN=
VINDI_PIX_FEE=0.50
```

---

## Fase 2 — `VindiService`

**Arquivo:** `app/Services/Payment/VindiService.php`

### Métodos

```php
// Base — monta e envia request para Vindi
private function createTransaction(array $payload): array

// PIX
public function createPixCharge(
    float $amount,
    string $externalRef,
    Customer $customer,
    ?string $affiliateToken = null
): array

// Cartão
public function createCreditCardCharge(
    float $amount,
    string $externalRef,
    CreditCardDTO $card,
    CreditCardHolderDTO $holder,
    int $installments,
    ?string $affiliateToken = null
): array

// Consulta status
public function getTransactionStatus(string $transactionToken): string
```

### Body base — cartão

```json
{
  "token_account": "{{VINDI_TOKEN_ACCOUNT}}",
  "reseller_token": "{{VINDI_RESELLER_TOKEN}}",
  "finger_print": null,
  "transaction": {
    "order_number": "{{order_id}}",
    "customer_ip": "{{ip}}",
    "url_notification": "https://{{host}}/webhooks/vindi"
  },
  "customer": {
    "name": "{{customer.name}}",
    "cpf": "{{customer.tax_id}}",
    "email": "{{customer.email}}"
  },
  "payment": {
    "type_payment": "visa|master|elo|amex",
    "card_number": "...",
    "card_expdate_month": "...",
    "card_expdate_year": "...",
    "card_cvv": "...",
    "installments": 1
  },
  "affiliates": [
    {
      "token_account": "{{company.vindi_affiliate_token}}",
      "percentual": "{{net_percentual}}"
    }
  ]
}
```

### Body PIX

Igual ao cartão, mas `payment.type_payment = "pix"` e sem campos de cartão.

### Retorno padronizado

```php
// PIX
[
    'transaction_token' => '...',
    'status'            => 'pending',
    'pix_qr_code'       => '...',
    'pix_copy_paste'    => '...',
]

// Cartão
[
    'transaction_token' => '...',
    'status'            => 'pending',
]
```

---

## Fase 3 — `VindiRefundGateway`

**Arquivo:** `app/Services/Refund/VindiRefundGateway.php`

Implementa `PaymentRefundGatewayInterface`:

```php
public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array
```

Usa `payment->vindi_transaction_token` para chamar endpoint de cancelamento/estorno da Vindi Intermediador.

---

## Fase 4 — Webhook Vindi

### `VindiWebhookController`

**Arquivo:** `app/Http/Controllers/VindiWebhookController.php`

```php
public function __invoke(Request $request): JsonResponse
{
    // Payload chega como form-data
    $data = $request->all();

    // Validar token_account no payload
    $tokenAccount = $data['token_account'] ?? '';
    if (!hash_equals(config('payments.vindi_token_account'), $tokenAccount)) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $transactionToken = $data['transaction']['token'] ?? null;
    $status = $data['transaction']['status_name'] ?? null;

    if (!$transactionToken || !$status) {
        return response()->json(['error' => 'Missing data'], 422);
    }

    ProcessVindiWebhook::dispatch($status, $data);

    return response()->json(['status' => 'queued']);
}
```

### `ProcessVindiWebhook` job

**Arquivo:** `app/Jobs/ProcessVindiWebhook.php`

| Status Vindi | Ação |
|---|---|
| `Aprovada` | `handleOrderPayment()` — lockForUpdate, idempotência, `WalletService::creditForOrder`, `TransactionService::createForPayment` |
| `Cancelada` / `Não Aprovada` | `payment.status = failed`, `order.status = cancelled` |
| `Estornada` | `handleRefundConfirmed()` |

Lookup do Payment: `Payment::where('vindi_transaction_token', $transactionToken)`
Lookup do pedido: `transaction[order_number]` = `order_id`

Idempotência: `hash('sha256', 'vindi:' . $transactionToken . ':paid')`

---

## Fase 5 — `PaymentOrchestrator`

**Arquivo:** `app/Services/Payment/PaymentOrchestrator.php`

### Mudanças

```php
// Constructor: substituir StarkService por VindiService
public function __construct(private readonly VindiService $vindi) {}

// processPix(): reescrever
// - taxa: config('payments.vindi_pix_fee')
// - chamar $this->vindi->createPixCharge(...)
// - Payment::create com 'vindi_transaction_token'
// - retornar 'gateway' => 'vindi'

// processCreditCard(): absorver lógica de ProcessOrder::processCreditCard
// - receber $cardData e $installments como parâmetros
// - calcular taxas via PaymentCalculatorService
// - chamar $this->vindi->createCreditCardCharge(...)
// - Payment::create com 'vindi_transaction_token'

// calculateFees(): trocar 'stark_pix_fee' por 'vindi_pix_fee'
```

---

## Fase 6 — `ProcessOrder`

**Arquivo:** `app/Jobs/ProcessOrder.php`

### Mudanças

```php
// handle(): remover AsaasServiceInterface da injeção
public function handle(PaymentOrchestrator $orchestrator): void

// processCreditCard(): remover método inteiro
// (lógica migrada para PaymentOrchestrator)

// processPix(): sem mudança de interface, já delega ao Orchestrator

// Remover imports:
// - AsaasServiceInterface
// - AsaasCustomerDTO, CreditCardDTO, CreditCardHolderDTO
// - AsaasCircuitOpenException
```

---

## Fase 7 — `RefundService`

**Arquivo:** `app/Services/Refund/RefundService.php`

```php
public function resolveGateway(Payment $payment): string
{
    if ($payment->vindi_transaction_token) return 'vindi';
    if ($payment->stark_payment_id) return 'stark'; // legado
    return 'asaas'; // legado
}

public function getGatewayDriver(string $gateway): PaymentRefundGatewayInterface
{
    return match ($gateway) {
        'vindi'  => app(VindiRefundGateway::class),
        'stark'  => app(StarkRefundGateway::class),  // remover na Fase 10
        default  => app(AsaasRefundGateway::class),  // legado assinatura
    };
}
```

---

## Fase 8 — Model `Payment`

**Arquivo:** `app/Models/Payment.php`

```php
// fillable: adicionar
'vindi_transaction_token',

// getExternalIdAttribute():
public function getExternalIdAttribute(): ?string
{
    return $this->vindi_transaction_token
        ?? $this->stark_payment_id
        ?? $this->asaas_payment_id;
}
```

---

## Fase 9 — Rotas

**Arquivo:** `routes/web.php`

```php
// Adicionar
Route::post('/webhooks/vindi', VindiWebhookController::class)
    ->name('webhook.vindi');

// Dev (somente APP_DEBUG=true)
Route::post('/dev/simulate/vindi-payment', VindiSimulatePaymentController::class)
    ->name('dev.simulate.vindi-payment');
```

---

## Fase 10 — Remover Stark Bank

> Executar somente após Vindi validado em produção.

### Arquivos a deletar

```
app/Services/Payment/StarkService.php
app/Services/Refund/StarkRefundGateway.php
app/Http/Controllers/StarkWebhookController.php
app/Http/Controllers/StarkSimulatePaymentController.php
app/Jobs/ProcessStarkWebhook.php
app/Jobs/TransferAsaasBalanceToStark.php
```

### Rotas a remover

```php
Route::post('/webhooks/stark', ...)
Route::post('/dev/simulate/stark-payment', ...)
Route::get('/dev/simulate/stark-status/{paymentId}', ...)
```

### Dependência

```bash
composer remove starkbank/starkbank
```

### `config/payments.php`

Remover chave `stark_pix_fee`.

### `RefundService`

Remover case `'stark'` de `getGatewayDriver()`.

---

## Fase 11 — Limpar Asaas (cartão/PIX de pedidos)

> Executar somente após Vindi validado em produção.

### `AsaasServiceInterface` — remover métodos de pedido

```php
// Remover:
createCreditCardCharge()
createOrderCharge()
getPaymentPixQrCode()
refundPayment()          // se não usado em assinatura
simulateAnticipation()
createAnticipation()

// Manter (assinatura):
probeHealth()
createCustomer()
findOrCreateCustomer()
createCharge()           // setup fee
createSubscription()
cancelSubscription()
getSubscriptionPayments()
validateWebhookToken()
getBalance()
createTransfer()
```

### `ProcessAsaasWebhook` — remover handlers de pedido

```php
// Remover:
handleOrderPayment()
handleRefundConfirmed()
handleTransferEvent()    // TransferAsaasBalanceToStark removido

// Manter:
handlePaymentConfirmed() // setup fee + assinatura
handlePaymentOverdue()   // assinatura vencida
handleAnticipationEvent()
handleFiscalAddonPayment()
```

### Remover

```
app/Services/Payment/AsaasCircuitBreaker.php
app/Exceptions/AsaasCircuitOpenException.php
```

---

## Fase 12 — Testes

### Novos arquivos

```
tests/Feature/Payment/VindiPixPaymentTest.php
tests/Feature/Payment/VindiCardPaymentTest.php
tests/Feature/Payment/VindiWebhookTest.php      — idempotência, mapeamento de status
tests/Feature/Payment/VindiSplitTest.php        — affiliate token no payload
```

### Atualizar

```
tests/Feature/ProcessOrderTest.php — mockar VindiService em vez de Asaas/Stark
```

---

## Subcontas (Affiliates)

1. Admin cadastra empresa no painel Vindi Intermediador como affiliate.
2. Vindi retorna token da subconta.
3. Admin insere token em `Admin > Configurações > Pagamentos` (novo campo UI).
4. Salvo em `companies.vindi_affiliate_token`.
5. `PaymentOrchestrator` lê `$company->vindi_affiliate_token` e inclui no array `affiliates` do request.
6. Se empresa não tiver token, processa sem split (plataforma recebe tudo).

---

## Ordem de execução

```
F1  Migrations (payments + companies + config)
F2  VindiService
F3  VindiRefundGateway
F4  VindiWebhookController + ProcessVindiWebhook
F5  PaymentOrchestrator (reescrever processPix + processCreditCard)
F6  ProcessOrder (remover lógica de cartão)
F7  RefundService (adicionar vindi)
F8  Payment model (vindi_transaction_token)
F9  Rotas
F12 Testes
────────────────────────────────
    ↓ validar em produção ↓
────────────────────────────────
F10 Remover Stark
F11 Limpar Asaas cartão
```

---

## Checklist de validação antes de ir a produção

- [ ] PIX Vindi gera QR Code e copy-paste válidos
- [ ] Cartão Vindi aprova transação no sandbox
- [ ] Webhook recebido e pedido marcado como pago
- [ ] Split correto: plataforma retém taxa, empresa recebe líquido
- [ ] Estorno funciona via `VindiRefundGateway`
- [ ] Idempotência: webhook duplicado não credita duas vezes
- [ ] Assinatura Asaas continua funcionando sem regressão
- [ ] Teste de pedido com empresa sem `vindi_affiliate_token` (sem split)
