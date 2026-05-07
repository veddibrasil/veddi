# Documentação do Sistema — Mister Coxinha / VEDDI

## Visão Geral

Plataforma SaaS multiempresa para operação de pedidos, cardápio e gestão financeira de negócios de alimentação.

**Stack:** Laravel 12, PHP 8.2+, Livewire 4, Flux 2, Tailwind CSS 4, Vite 7, Pest 3, Reverb (WebSockets)

---

## Módulos Principais

| Módulo | Rota base | Acesso |
|--------|-----------|--------|
| Onboarding público | `/cadastro` | Público |
| Chat de pedidos | `/{company}` | Público |
| Painel da empresa | `/admin` | Autenticado + papel |
| Painel super admin | `/superadmin` | Super admin |

---

## Rotas Públicas

### Onboarding
| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/cadastro` | Formulário de cadastro de nova empresa |
| POST | `/cadastro` | Cria empresa, filial e usuário admin |
| GET | `/cadastro/pendente` | Status de pagamento pendente da taxa de ativação |

### Chat / Pedidos
| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/{company}` | Chat público da empresa (slug) para montagem de pedido |
| GET | `/payment/complete` | Conclusão de pagamento por cartão |

### Utilitários
| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/health` | Health check — verifica conexão DB |
| POST | `/api/validate-cpf` | Validação de CPF/CNPJ (throttle 30/min) |
| GET | `/` | Redireciona para veddi.com.br |

### Webhooks
| Método | Rota | Gateway | Autenticação |
|--------|------|---------|--------------|
| POST | `/webhooks/asaas` | Asaas | Header `asaas-access-token` |
| POST | `/webhooks/stark` | Stark Bank | Header `Authorization: Bearer <token>` |

---

## Painel Administrativo (`/admin`)

Requer: `auth`, `verified`, `company.role:company_admin`

### Faturamento e Carteira (acessíveis mesmo com empresa bloqueada)
| Rota | Componente | Descrição |
|------|-----------|-----------|
| `admin/billing` | BillingSettings | Plano atual, histórico de cobranças |
| `admin/wallet` | CompanyWallet | Saldo, entradas, retiradas |

### Operação (requer empresa ativa)
| Rota | Componente | Descrição |
|------|-----------|-----------|
| `admin/dashboard` | Dashboard | Métricas gerais, pedidos recentes |
| `admin/orders` | Orders/Index | Lista de pedidos com filtros |
| `admin/orders/report` | Orders/Report | Relatório de vendas com analytics |
| `admin/orders/{order}` | Orders/Show | Detalhe do pedido |
| `admin/stock` | Stock/Index | Gestão de estoque por filial |
| `admin/categories` | Categories/Index | Categorias de produtos |
| `admin/products` | Products/Index | Lista de produtos |
| `admin/products/create` | Products/Form | Cadastro de produto |
| `admin/products/{product}/edit` | Products/Form | Edição de produto |
| `admin/branches` | Branches/Index | Lista de filiais |
| `admin/branches/create` | Branches/Form | Cadastro de filial |
| `admin/branches/{branch}/edit` | Branches/Form | Edição de filial |
| `admin/branches/{branch}/delivery` | Branches/DeliverySettings | Configuração de entrega |
| `admin/coupons` | Coupons/Index | Cupons de desconto |
| `admin/settings` | Settings/CompanySettings | Configurações da empresa (admin only) |
| `admin/roles` | Roles/Index | Papéis e permissões |
| `admin/users` | Users/Index | Usuários da empresa |
| `admin/users/{user}/permissions` | Users/Permissions | Permissões individuais |

### API Financeira (autenticada)
| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/api/company/balance` | Saldo atual da empresa |
| GET | `/api/company/balance/forecast` | Previsão financeira 30 dias |
| POST | `/api/company/withdraw` | Solicitar retirada (PIX/TED) |
| POST | `/api/company/anticipate` | Solicitar antecipação de recebíveis |

---

## Painel Super Admin (`/superadmin`)

Requer middleware `super.admin`

| Rota | Descrição |
|------|-----------|
| `superadmin/companies` | Listagem e gestão de todas as empresas |
| `superadmin/companies/create` | Criar empresa manualmente |
| `superadmin/companies/{company}/edit` | Editar empresa |
| `superadmin/users` | Todos os usuários da plataforma |
| `superadmin/permissions` | Gerenciamento global de permissões |
| `superadmin/card-taxas` | Taxas de cartão |
| `superadmin/simulate/asaas-payment` | Simular pagamento Asaas (debug) |

---

## Configurações (`/settings`)

| Rota | Descrição |
|------|-----------|
| `settings/profile` | Perfil do usuário |
| `settings/password` | Alteração de senha |
| `settings/appearance` | Tema e aparência |
| `settings/two-factor` | Autenticação de dois fatores (Fortify) |

---

## Modelos de Dados

### `Company` — Empresa (raiz do multi-tenancy)
Campos principais: `name`, `slug`, `plan` (Free/Essencial/Pro), `status` (ACTIVE/PENDING_PAYMENT/OVERDUE/BLOCKED), `asaas_customer_id`, `asaas_subscription_id`, `owner_cpf_cnpj`

Configurações de marca: `primary_color`, `logo_path`, `favicon_path`, `tagline`, `footer_text`, `chat_highlights`, `order_prefix`

Configurações financeiras: `default_payout_type`, `default_pix_key`, `pix_fee_absorbed_by_company`, `card_fee_absorbed_by_company`

**Relacionamentos:** `users` (BelongsToMany), `branches` (HasMany), `orders` (HasMany), `products` (HasMany), `walletEntries` (HasMany), `transactions` (HasMany), `balance` (HasOne), `subscriptions` (HasMany)

---

### `User` — Usuário
Campos: `name`, `email`, `password`, `is_super_admin`

**Relacionamentos:** `companies` (BelongsToMany) — pivot com `role` e `branch_id`

**Métodos:** `isSuperAdmin()`, `roleForCompany()`, `hasPermission()`, `isCompanyAdmin()`, `isBranchManager()`

---

### `Order` — Pedido
Campos: `company_id`, `order_number` (auto-gerado com prefixo), `customer_id`, `branch_id`, `subtotal`, `delivery_fee`, `total`, `status`, `payment_method` (PIX/CREDIT_CARD), `order_type` (delivery/pickup), `coupon_id`, `discount`, `fee`, `net_value`, `notes`

**Status do pedido:**
```
pending → awaiting_payment → paid → preparing → ready → delivered
                                  ↘ cancelled / refunded / chargeback
```

**Escopo global:** `CompanyScope` — filtra automaticamente por empresa

---

### `Payment` — Pagamento
Campos: `order_id`, `asaas_payment_id`, `stark_payment_id`, `payment_gateway` (stark/asaas), `pix_qr_code`, `pix_copy_paste`, `amount`, `pix_fee`, `card_fee`, `card_fee_rate`, `installments`, `anticipation_days`, `status` (pending/paid/expired), `paid_at`, `expires_at`

---

### `Product` — Produto
Campos: `company_id`, `product_category_id`, `name`, `description`, `price`, `image_path`, `active`, `sort_order`

**Relacionamentos:** `category`, `branches` (BelongsToMany — pivot com `available`, `quantity`, `min_quantity`, `track_stock`), `optionGroups`

---

### `Branch` — Filial
Campos: `company_id`, `name`, `address`, `city`, `phone`, `active`, `opens_at`, `closes_at`

**Métodos:** `isOpen()`

---

### `Customer` — Cliente (chat)
Campos: `company_id`, `name`, `phone`, `email`, `tax_id`, `address`, `number`, `complement`, `neighborhood`, `city`, `cep`

---

### `CompanyTransaction` — Ledger de liquidação
Campos: `company_id`, `order_id`, `payment_id`, `type` (pix/cartao/boleto), `status` (pending/confirmed/released/withdrawn/refunded/chargeback), `value`, `net_value`, `payment_date`, `release_date`, `withdrawn`, `is_anticipated`, `anticipation_fee`

---

### `CompanyBalance` — Snapshot de saldo
Campos: `company_id`, `total_balance`, `blocked_balance`, `available_balance`, `withdrawn_balance`, `reserve_balance` (10% do total)

---

### `CompanyWalletEntry` — Log de carteira
Campos: `company_id`, `order_id`, `type` (credit/fee/pix_fee/card_fee/withdrawal), `amount`, `description`

---

### `CompanyWithdrawal` — Solicitação de retirada
Campos: `company_id`, `amount`, `pix_fee`, `status` (pending/processing/done/failed), `payout_type` (PIX/TED), dados bancários, `asaas_transfer_id`

---

### `Coupon` — Cupom de desconto
Campos: `code`, `name`, `type` (percentage/fixed/free_delivery/free_product), `discount_value`, `free_product_id`, `scope` (global/categories/products), `scope_ids`, `minimum_order_value`, `max_uses`, `max_uses_per_customer`, `starts_at`, `expires_at`

---

### `DeliverySetting` — Configuração de entrega por filial
Campos: `branch_id`, `fee_type` (flat/neighborhood/distance), `flat_fee`, `minimum_order_value`, `free_delivery_above`, `branch_latitude`, `branch_longitude`

**Relacionamentos:** `neighborhoods` (HasMany), `distanceTiers` (HasMany)

---

## Planos

Definidos em `config/plans.php` e `app/Enums/Plan.php`.

| Plano | Mensalidade | Taxa/pedido | Taxa ativação | Pedidos/mês | Filiais |
|-------|------------|------------|--------------|-------------|---------|
| Free | R$0 | 1% | R$0 | 50 | 1 |
| Essencial | R$59 | 0% | R$99 | Ilimitado | 1 |
| Pro | R$119 | 0% | R$99 | Ilimitado | 3 |

**Status da empresa:**
- `PENDING_PAYMENT` — Criada, aguardando confirmação da taxa de ativação
- `ACTIVE` — Operacional
- `OVERDUE` — Assinatura mensal falhou, período de graça de 3 dias úteis
- `BLOCKED` — Período de graça expirou, sem acesso

---

## Fluxo de Pedido

```
1. Cliente acessa /{company} (slug)
2. Chat identifica empresa e filial disponível
3. Estado: IDENTIFY_PHONE → endereço → carrinho → checkout → pagamento
4. OrderService.createOrder() — valida preços, estoque, cupom no backend
5. ProcessOrder job despachado (fila critical, retry 24h)
6. PaymentOrchestrator roteia:
   - PIX → StarkService
   - Cartão → AsaasService
7. Payment record criado com qr_code/copy_paste ou token de cartão
8. Order status → awaiting_payment
9. Webhook chega (Stark ou Asaas)
10. Payment status → paid
11. WalletService credita carteira da empresa
12. TransactionService cria CompanyTransaction (status=confirmed)
13. ReleaseCompanyTransactionsJob libera diariamente (00:00 UTC)
```

---

## Cancelamento e Estorno (Refund)

Backlog técnico e desenho sugerido: `docs/cancelamento-estorno.md`.

---

## Fluxo de Onboarding

```
1. POST /cadastro → RegisterCompanyController.store()
2. OnboardingDTO normaliza entrada
3. OnboardingService.handle():
   a. Cria cliente no Asaas (antes da transação)
   b. Transação atômica: cria Company + Branch + User
   c. Plano Free → ACTIVE imediatamente
   d. Planos pagos → PENDING_PAYMENT + despacha CreateAsaasSetupFee job
4. Webhook PAYMENT_CONFIRMED do Asaas → CompanyService.activate()
5. Empresa ativa → painel disponível
6. Planos pagos → CreateAsaasSubscription job cria assinatura recorrente
```

---

## Fluxo Financeiro

### Cálculo de Valores
```
subtotal = soma(item.unit_price × item.quantity)
desconto = coupon.calculate(subtotal)
total = subtotal - desconto + delivery_fee
fee = FeeCalculator.calculate(subtotal, plan.feePercentage())  // só sobre produtos
net_value = total - fee - pix_fee (se absorvida) - card_fee (se absorvida)
```

### Prazo de Liberação
- PIX: D+2
- Boleto: D+2
- Cartão: D+15

### Taxas de Cartão
| Parcelamento | Taxa |
|-------------|------|
| 1x | 2,99% |
| 2x a 6x | 3,49% |
| 7x a 12x | 3,99% |

### Antecipação
| Prazo | Taxa extra |
|-------|-----------|
| D+2 | 2,99% |
| D+7 | 2,49% |
| D+15 | 1,99% |
| D+30 | 0% |

### Saldo
```
total_balance     = confirmed + released (não sacado)
blocked_balance   = confirmed apenas (não liberado)
available_balance = released_não_sacado - 10% reserva
reserve_balance   = 10% do total_balance
```

### Fluxo de Retirada
```
1. POST /api/company/withdraw
2. WithdrawalService.validateWithdrawal() — verifica saldo disponível
3. CompanyWithdrawal criado (status=pending)
4. Transactions elegíveis bloqueadas para saque
5. ProcessWithdrawal job → StarkService.createTransfer() ou AsaasService
6. CompanyWithdrawal → done/failed
7. CompanyTransactions → withdrawn
```

---

## Services

| Service | Responsabilidade |
|---------|-----------------|
| `OnboardingService` | Criação atômica de empresa + filial + usuário |
| `OrderService` | Criação de pedido com validação backend |
| `PaymentOrchestrator` | Roteamento de pagamento para gateway correto |
| `PaymentService` | Workflow de pagamento (disparo, simulação, expiração) |
| `PaymentCalculatorService` | Cálculo de taxas de parcelamento |
| `AsaasService` | Client do gateway Asaas (com circuit breaker) |
| `StarkService` | Client do gateway Stark Bank (PIX + transferências) |
| `FeeCalculator` | Cálculo de taxa da plataforma |
| `CouponService` | Validação e aplicação de cupons |
| `WalletService` | Crédito/débito na carteira da empresa |
| `TransactionService` | Criação e transições de CompanyTransaction |
| `BalanceService` | Cálculo de saldo em tempo real + forecast |
| `WithdrawalService` | Validação e solicitação de retiradas |
| `AnticipationService` | Antecipação de recebíveis com taxa |
| `ReleaseService` | Liberação diária de transações confirmadas |
| `DeliveryService` | Cálculo de taxa de entrega (flat/bairro/distância) |
| `CompanyService` | Transições de status da empresa (ativar, bloquear, etc.) |
| `CustomerService` | Gestão de perfil do cliente do chat |
| `StockService` | Controle de estoque por filial |
| `CouponService` | Validação + desconto + registro de uso |
| `UserPermissionService` | Overrides de permissão por usuário |
| `WhatsAppService` | Envio de mensagens via Z-API |
| `AsaasCircuitBreaker` | Tolerância a falhas do Asaas (abre após 5 erros 5xx) |

---

## Jobs

| Job | Fila | Descrição |
|-----|------|-----------|
| `ProcessOrder` | critical | Processa pagamento do pedido (retry 24h) |
| `ProcessAsaasWebhook` | critical | Handler do webhook Asaas (3 tentativas: 10s/60s/300s) |
| `ProcessStarkWebhook` | critical | Handler do webhook Stark Bank |
| `CreateAsaasSetupFee` | default | Cria cobrança de taxa de ativação no Asaas |
| `CreateAsaasSubscription` | default | Cria assinatura recorrente após ativação |
| `ProcessWithdrawal` | default | Executa transferência via gateway |
| `ReleaseCompanyTransactionsJob` | scheduled | Libera transações confirmadas (00:00 UTC diário) |
| `UpdateCompanyBalancesJob` | scheduled | Atualiza snapshots de saldo (22:00 UTC diário) |
| `RefundPayment` | default | Processa estorno no gateway |
| `SendWhatsAppMessage` | default | Envia notificação WhatsApp de status do pedido |

---

## Webhooks

### Asaas (`POST /webhooks/asaas`)
**Autenticação:** Header `asaas-access-token`  
**Throttle:** 60/min

Eventos processados:

| Evento | Ação |
|--------|------|
| `PAYMENT_CONFIRMED` | Pagamento de pedido → confirma, credita carteira; ou taxa de ativação → ativa empresa |
| `PAYMENT_RECEIVED` | Mesmo que PAYMENT_CONFIRMED |
| `PAYMENT_OVERDUE` | Assinatura vencida → `CompanyService.markOverdue()` |

**Distinção:** Se o payload tem `externalReference` → pedido do cliente. Se tem `subscription` → cobrança da plataforma.

---

### Stark Bank (`POST /webhooks/stark`)
**Autenticação:** Header `Authorization: Bearer <token>`

Eventos processados:

| Evento | Ação |
|--------|------|
| `credited` | PIX recebido → confirma pagamento, credita carteira da empresa |

---

## Multi-Tenancy

- `CompanyScope` aplicado globalmente em todos os modelos com trait `BelongsToCompany`
- Contexto resolvido via `app('current.company')`
- `withoutGlobalScopes()` usado apenas em: webhooks, super admin, onboarding, manutenção interna
- Isolamento coberto por testes em `tests/Feature/CustomerMultiTenancyTest.php`

**Exceções ao escopo (sem global scope):**
- `Subscription` — webhooks precisam consultar sem tenant
- `CompanyBalance` — upsert usa `company_id` como chave

---

## Sistema de Permissões

### Papéis
- `company_admin` — acesso total ao painel da empresa
- `branch_manager` — acesso à filial específica
- (outros papéis configuráveis)

### Hierarquia de verificação
1. Papel do usuário na empresa
2. Permissões do papel
3. Overrides individuais via `UserPermission` (granted = true/false)

**Cache:** 5 minutos por verificação

---

## Integrações Externas

### Asaas
- **Uso:** PIX, boleto, cartão de crédito, assinaturas recorrentes, transferências
- **Circuit breaker:** Abre após 5 erros 5xx consecutivos
- **Sandbox:** controlado por `ASAAS_SANDBOX=true`

### Stark Bank
- **Uso:** PIX (pagamentos e retiradas), consulta de saldo
- **Sandbox:** controlado por `STARK_SANDBOX=true`

### Z-API (WhatsApp)
- **Uso:** Notificações de status de pedido
- **Config:** `ZAPI_INSTANCE_ID`, `ZAPI_TOKEN`, `ZAPI_CLIENT_TOKEN`

### Reverb (WebSockets)
- **Uso:** Atualizações em tempo real no painel admin (novos pedidos, status)

---

## Configurações de Pagamento (`config/payments.php`)

```
pix_payment_fee:      R$0,50 por transação PIX
pix_withdrawal_fee:   R$0,50 por saque PIX
release_days:
  pix:    2 dias
  boleto: 2 dias
  cartao: 15 dias
```

---

## Variáveis de Ambiente Relevantes

| Variável | Descrição |
|----------|-----------|
| `ASAAS_API_KEY` | Chave de API Asaas |
| `ASAAS_SANDBOX` | Modo sandbox Asaas |
| `ASAAS_WEBHOOK_TOKEN` | Token de validação webhook Asaas |
| `STARK_PROJECT_ID` | ID do projeto Stark Bank |
| `STARK_PRIVATE_KEY` | Chave privada Stark Bank |
| `STARK_SANDBOX` | Modo sandbox Stark Bank |
| `STARK_WEBHOOK_TOKEN` | Token de validação webhook Stark |
| `ZAPI_INSTANCE_ID` | Instância Z-API WhatsApp |
| `ZAPI_TOKEN` | Token Z-API |
| `ZAPI_CLIENT_TOKEN` | Client token Z-API |
| `PLAN_ESSENCIAL_PRICE` | Preço plano Essencial (default: 59.00) |
| `PLAN_PRO_PRICE` | Preço plano Pro (default: 119.00) |
| `PLAN_SETUP_FEE` | Taxa de ativação (default: 99.00) |

---

## Comandos de Desenvolvimento

```bash
composer setup          # Instalação inicial
composer dev            # Servidor + queue + pail + Vite + Reverb em paralelo
composer lint           # Formatar código (Pint preset laravel)
composer lint:check     # Verificar lint sem alterar
php artisan test        # Rodar testes
./vendor/bin/pest       # Rodar testes via Pest
npm run build           # Build de assets para produção
npm run dev             # Dev server frontend
```

---

## Observações de Arquitetura

- **Controllers finos:** recebem request, validam, delegam para Services
- **Preços calculados no backend:** nunca confiar em valores enviados pelo cliente
- **Jobs idempotentes:** webhooks podem chegar duplicados
- **Transações atômicas:** operações multi-model (onboarding, pedido, retirada) usam `DB::transaction()`
- **DTOs para validação de entrada:** `OnboardingDTO`, `AsaasCustomerDTO`, `CreditCardDTO`
- **Contracts para gateways:** `PaymentGatewayInterface`, `OrderServiceInterface`, etc. — desacoplamento de implementação
