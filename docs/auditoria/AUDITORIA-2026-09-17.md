# Auditoria — Mister Coxinha (2026-09-17)

Escopo: sistema inteiro (segurança + performance + qualidade). Metodologia: leitura de código com evidência `arquivo:linha`, sem alteração de arquivos. Execução paralela por 5 revisores especializados (tenancy/Livewire, auth/pagamentos, dados/config, performance, qualidade/testes) mais reconhecimento manual de rotas, middlewares e canais de broadcast.

## 1. Resumo executivo

| Severidade | Qtde |
|---|---|
| Crítica | 8 |
| Alta | 10 |
| Média | 10 |
| Baixa | 3 |
| **Total** | **31** |

**Os 5 mais urgentes:**

1. **IDOR não autenticado no chat público** — `resumePendingOrder()` expõe QR code PIX, copia-e-cola e dados de pedido de qualquer cliente/empresa a um visitante anônimo.
2. **Canal WebSocket público vaza pedidos de toda a plataforma** — eventos de pedido usam `Channel` (público) em vez de `PrivateChannel`; qualquer pessoa conectada ao Reverb recebe nome do cliente, total, forma de pagamento e chave de acesso fiscal de qualquer empresa.
3. **CRUD cross-tenant em Cupons/Produtos/Categorias/Filiais/Estoque** — Policies não verificam se o recurso pertence à empresa do ator; combinado com `withoutGlobalScope` incondicional em vários componentes Livewire.
4. **Exclusão global de usuário via IDOR** (`Users/Index::removeUser`) — admin de uma empresa apaga da plataforma inteira um usuário de outra empresa.
5. **CPF/nome/endereço do cliente logados em texto puro e replicados para o Nightwatch** (terceiro) em toda cobrança de cartão via Vindi.

O padrão correto de isolamento **existe e é usado em outras partes do código** (`OrderPolicy`, `findRecoverableOrder()`, `Orders/Index.php`), o que indica desvios pontuais e não uma limitação arquitetural — correção é mecânica, replicando o padrão já validado em módulos irmãos.

---

## 2. Achados

### 2.1 Multi-tenancy / IDOR (crítico)

### [SEV-CRÍTICA] IDOR não autenticado expõe pedidos e dados de pagamento (PIX) de qualquer cliente/empresa
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Chat/Concerns/HasOrderRecovery.php:21-45`; propriedade em `app/Livewire/Chat/OrderChat.php:92`
- **Evidência:**
```php
public function resumePendingOrder(): void
{
    $orderId = $this->pendingOrderSummary['id'] ?? null;

    $order = Order::withoutGlobalScopes()
        ->with(['items', 'payment', 'coupon'])
        ->find($orderId);
    // nenhum filtro por customer_id ou company_id
    ...
    $this->pixQrCode = $payment->pix_qr_code;
    $this->pixCopyPaste = $payment->pix_copy_paste;
    $this->paymentId = $payment->asaas_payment_id;
```
`pendingOrderSummary` é `public ?array` sem `#[Locked]`, em componente público não autenticado (`GET /{company}`). Diferente de `findRecoverableOrder()` e `goToOrderHistory()` no mesmo módulo, que corretamente filtram por `customer_id` E `company_id`.
- **Impacto:** qualquer visitante anônimo do chat de qualquer empresa adultera `pendingOrderSummary.id` para um ID de pedido de outro cliente/outra empresa e recebe itens, forma de pagamento, cupom, **QR code PIX, copia-e-cola e ID do pagamento Asaas** de terceiros.
- **Como reproduzir/confirmar:** via devtools/AJAX do Livewire, setar snapshot `pendingOrderSummary` como `{"id": <id_de_pedido_pending_de_outro_cliente>}` e chamar `resumePendingOrder`.
- **Correção proposta:** replicar filtro de `findRecoverableOrder()`: `Order::withoutGlobalScopes()->where('id', $orderId)->where('customer_id', $this->customerId)->where('company_id', $this->companyId)->first()`.
- **Esforço:** P
- **Teste a criar:** `ChatResumePendingOrderCrossTenantTest`

---

### [SEV-CRÍTICA] Eventos de pedido/fiscal broadcast em canal público — vazamento cross-tenant em tempo real
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Events/NewOrderPlaced.php:18-39`, `OrderStatusUpdated.php:22-38`, `OrderItemsUpdated.php:23-39`, `TabOrderSentToProduction.php:26-40`, `FiscalNoteAuthorized.php:22-37`
- **Evidência:**
```php
// NewOrderPlaced.php
public function broadcastOn(): array
{
    return [new Channel('orders.'.$this->order->company_id)]; // Channel público, sem auth
}
public function broadcastWith(): array
{
    return [
        'customer_name' => $this->order->customer?->name ?? 'Cliente',
        'total' => $this->order->total,
        'payment_method' => $this->order->payment_method,
        ...
    ];
}
```
`FiscalNoteAuthorized` inclusive expõe `access_key` (chave de acesso da nota fiscal) no canal público `order.{order_id}`. `routes/channels.php` só define autorização para `App.Models.User.{id}` e `wallet.{companyId}` — canais `Channel` públicos não passam por `/broadcasting/auth`, logo não há nenhuma barreira.
- **Impacto:** qualquer cliente WebSocket (Echo/Reverb) conectado, sem login, que se inscreva em `orders.1`, `orders.2`, ... (IDs sequenciais, enumeráveis) recebe em tempo real nome do cliente, total, forma de pagamento e status de **todos os pedidos de todas as empresas** da plataforma.
- **Como reproduzir/confirmar:** abrir console do navegador em qualquer página pública, instanciar Echo apontando pro Reverb do host, `Echo.channel('orders.1').listen('NewOrderPlaced', console.log)` sem autenticação — payload chega.
- **Correção proposta:** trocar `Channel` por `PrivateChannel` em todos os 5 eventos; adicionar autorização em `routes/channels.php` (`orders.{companyId}` e `order.{orderId}`) exigindo `$user->companies()->where('companies.id', $companyId)->exists()` (ou verificação mais estrita, ver achado de canal de carteira); ajustar frontend para `Echo.private(...)`.
- **Esforço:** M
- **Teste a criar:** `BroadcastChannelsTest::orders_channel_requires_authentication_and_company_membership`

---

### [SEV-CRÍTICA] Cupons — CRUD cross-tenant completo via Livewire sem autorização
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Admin/Coupons/Index.php:156-243` (`save`, `edit`, `toggleActive`, `delete`); `app/Policies/CouponPolicy.php:28-36`; rota em `routes/web.php:144`
- **Evidência:**
```php
public function delete(): void
{
    $coupon = Coupon::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
    $coupon->delete();   // nenhum $this->authorize() nesses 4 métodos
}
```
```php
// CouponPolicy — não compara $coupon->company_id
public function delete(User $user, Coupon $coupon): bool
{
    return $user->hasPermission('coupons.delete', $this->company());
}
```
A rota `GET /admin/coupons` está fora de qualquer grupo `company.role:...`, acessível por qualquer papel autenticado (inclusive `entrega`, `cozinha`, `caixa`).
- **Impacto:** qualquer usuário autenticado do painel (mesmo sem `coupons.*`, dependendo do papel) ou qualquer `company_admin` com a permissão na própria empresa consegue editar, ativar/desativar e **excluir cupons de qualquer outra empresa**, bastando informar o ID.
- **Como reproduzir/confirmar:** `Livewire::test(Index::class)->call('confirmDelete', <id_cupom_empresa_B>)->call('delete')` — cupom de B é excluído.
- **Correção proposta:** adicionar `$this->authorize('update'|'delete', $coupon)` em cada método mutável; corrigir `CouponPolicy` para checar `$coupon->company_id === $this->company()->id`; remover `withoutGlobalScope` das operações de escrita; colocar a rota dentro de `company.role:company_admin,branch_manager`.
- **Esforço:** M
- **Teste a criar:** `CouponCrossTenantIsolationTest`, `test_low_privilege_role_cannot_manage_coupons`

---

### [SEV-CRÍTICA] Produtos — `Products/Index::delete()` ignora escopo de empresa mesmo para admin comum
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Admin/Products/Index.php:161-164`; `app/Policies/ProductPolicy.php:26-34`
- **Evidência:**
```php
public function delete(): void
{
    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
    $this->authorize('delete', $product);   // ProductPolicy não checa company_id
```
Diferente de `updateOrder()`/`updateCategoryOrder()` no mesmo arquivo, que só usam `withoutGlobalScope` quando `$this->isSuperAdmin` — `delete()` ignora o scope sempre.
- **Impacto:** `company_admin` com `products.delete` na própria empresa apaga/desativa produtos de **qualquer outra empresa**. O teste existente (`ProductDeleteDeactivatesTest`) só cobre o caminho super admin, nunca esse cenário.
- **Como reproduzir/confirmar:** `Livewire::actingAs($adminA)->test(Index::class)->call('confirmDelete', $produtoEmpresaB->id)->call('delete')`.
- **Correção proposta:** condicionar `withoutGlobalScope` a `isSuperAdmin`; corrigir `ProductPolicy`.
- **Esforço:** P
- **Teste a criar:** `ProductCrossTenantDeleteTest`

---

### [SEV-CRÍTICA] Categorias — save/edit/delete sem checagem de empresa
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Admin/Categories/Index.php:84-90, 107-115, 135-141`; `app/Policies/ProductCategoryPolicy.php:26-34`
- **Evidência:**
```php
public function delete(): void
{
    $category = ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
    $this->authorize('delete', $category);   // Policy não checa company_id
    $category->delete();
}
public function edit(int $id): void
{
    $category = ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
    // sem authorize() nenhum
```
- **Impacto:** `company_admin` renomeia, muda estação e **exclui** categorias de outra empresa; `edit()` vaza dados de categoria de terceiros sem nenhuma checagem.
- **Como reproduzir/confirmar:** `Livewire::actingAs($adminA)->test(Index::class)->call('confirmDelete', $categoriaB->id)->call('delete')`.
- **Correção proposta:** condicionar `withoutGlobalScope` a `isSuperAdmin`; corrigir `ProductCategoryPolicy`; adicionar `authorize()` em `edit()`.
- **Esforço:** P
- **Teste a criar:** `ProductCategoryCrossTenantIsolationTest`

---

### [SEV-CRÍTICA] Filiais — `Branches/Index::delete()` permite excluir filial de outra empresa
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Admin/Branches/Index.php:61-75`; `app/Policies/BranchPolicy.php:28-36`
- **Evidência:**
```php
public function delete(): void
{
    $branch = Branch::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
    $this->authorize('delete', $branch);   // BranchPolicy não checa company_id
    $totalBranches = Branch::withoutGlobalScope(CompanyScope::class)
        ->where('company_id', $branch->company_id)->count();
    abort_if($totalBranches <= 1, 422, 'Não é possível excluir a única filial.');
    $branch->delete();
```
A única proteção é não deixar excluir a última filial **da empresa alvo** — filiais adicionais de outra empresa continuam excluíveis.
- **Impacto:** `company_admin` com `branches.delete` derruba operação de outra empresa (pedidos/PDV/estoque atrelados ficam órfãos).
- **Como reproduzir/confirmar:** `Livewire::actingAs($adminA)->test(Index::class)->call('confirmDelete', $filialB->id)->call('delete')` com empresa B tendo 2+ filiais.
- **Correção proposta:** mesmo tratamento condicional a `isSuperAdmin` + corrigir `BranchPolicy::delete()`.
- **Esforço:** P
- **Teste a criar:** `BranchCrossTenantDeleteTest`

---

### [SEV-CRÍTICA] Exclusão global de usuário via IDOR (`Users/Index::removeUser`)
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Admin/Users/Index.php:218-227`; `app/Services/Company/UserDeletionService.php:15-30`
- **Evidência:**
```php
public function removeUser(): void
{
    abort_unless($this->canManage, 403);
    abort_if($this->removingUserId === auth()->id(), 403);
    $user = User::findOrFail($this->removingUserId);   // sem checar vínculo com a empresa atual
    UserDeletionService::delete($user);
}
// UserDeletionService::delete() apaga o usuário DA PLATAFORMA INTEIRA:
$user->companies()->detach();
UserPermission::where('user_id', $user->id)->delete();
DB::table('sessions')->where('user_id', $user->id)->delete();
$user->delete();
```
- **Impacto:** `$removingUserId` é propriedade pública Livewire setável via payload AJAX. Qualquer `company_admin` com `users.manage` na própria empresa apaga globalmente um usuário de **qualquer outra empresa**: desvincula de tudo, remove overrides de permissão, mata todas as sessões ativas, soft-deleta a conta.
- **Como reproduzir/confirmar:** autenticado como admin da Empresa A, disparar `removeUser` com `removingUserId` de usuário só vinculado à Empresa B.
- **Correção proposta:** validar `$user->companies()->where('companies.id', $company->id)->exists()` antes de chamar o serviço; mudar semântica para "remover vínculo com esta empresa", não apagar globalmente, salvo se for a última empresa do usuário.
- **Esforço:** P
- **Teste a criar:** `test_company_admin_cannot_delete_user_from_another_company`

---

### [SEV-CRÍTICA] Estoque — ajuste/toggle sem checar propriedade de produto/filial
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Admin/Stock/Index.php:130-184` (`openAdjustModal`, `applyAdjustment`, `toggleTracking`)
- **Evidência:**
```php
public function applyAdjustment(): void
{
    $this->checkPermission('stock.adjust');   // só checa permissão nomeada na empresa do ator
    $branch = Branch::withoutGlobalScope(CompanyScope::class)->findOrFail($this->adjustingBranchId);
    $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($this->adjustingProductId);
    $service->adjust($branch, $product, $qty, $this->adjustNotes, auth()->user());
```
`checkPermission()` nunca valida se `$productId`/`$branchId` pertencem à empresa do ator.
- **Impacto:** usuário com `stock.adjust` altera diretamente quantidade de estoque e liga/desliga rastreamento de produtos/filiais de **outra empresa**, gerando `StockMovement` fraudulento que corrompe inventário real de terceiros.
- **Como reproduzir/confirmar:** `Livewire::actingAs($userA)->test(Index::class)->call('openAdjustModal', $produtoB->id, $filialB->id)->set('adjustQuantity','10')->call('applyAdjustment')`.
- **Correção proposta:** validar `$product->company_id === $company->id` e `$branch->company_id === $company->id` antes de prosseguir (exceto super admin).
- **Esforço:** M
- **Teste a criar:** `StockCrossTenantAdjustmentTest`

---

### 2.2 Multi-tenancy / autorização (alto)

### [SEV-ALTA] `RolePolicy` não valida empresa + `assignUser()` referencia Role sem escopo
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Policies/RolePolicy.php:26-35`; `app/Livewire/Admin/Roles/Index.php:136-148`
- **Evidência:**
```php
public function delete(User $user, Role $role): bool
{
    return $user->hasPermission('roles.manage', $this->company()) && ! $role->is_system;
}
```
```php
$role = Role::findOrFail($this->assignRoleId);  // Role não tem CompanyScope
```
- **Impacto:** `Role` não usa `BelongsToCompany`/`CompanyScope`. `assignRoleId` é propriedade pública Livewire; `company_admin` pode apontar para Role customizado de outra empresa. Impacto de permissões é mitigado porque `hasPermission()` reconsulta o Role pelo `slug` escopado, mas expõe existência/nome de roles de terceiros (IDOR de leitura) e é inconsistente com o resto da classe, que já filtra por `company_id` em `save/edit/delete`.
- **Correção proposta:** `Role::where('id', $id)->where(fn($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->firstOrFail()`; adicionar checagem de `company_id` em `RolePolicy`.
- **Esforço:** P
- **Teste a criar:** `test_cannot_assign_role_belonging_to_another_company`

---

### [SEV-ALTA] `Products/Form.php` — `selectedBranches` sem validação de propriedade
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Admin/Products/Form.php:105-106, 671-705, 798-807`
- **Evidência:**
```php
'selectedBranches.*' => ['exists:branches,id'],  // só verifica existência, não empresa
...
foreach ($this->selectedBranches as $branchId) {
    $branch = Branch::withoutGlobalScope(CompanyScope::class)->find($branchId);
    $service->setQuantity($branch, $product, (int) $this->initialQuantity, 'Estoque inicial', auth()->user());
}
```
- **Impacto:** `company_admin` adultera `selectedBranches` para incluir filial de outra empresa: cria vínculo cross-tenant em `branch_product` e dispara `StockService::setQuantity()` poluindo estoque de terceiros com "estoque inicial" fantasma.
- **Correção proposta:** trocar `exists:branches,id` por `Rule::exists('branches','id')->where('company_id', $this->company_id)`.
- **Esforço:** M
- **Teste a criar:** `ProductFormCrossTenantBranchAssignmentTest`

---

### [SEV-ALTA] Taxa de entrega confiada de propriedade pública Livewire sem revalidação servidor
- **Categoria:** Segurança
- **Local:** `app/Services/Order/OrderService.php:80-83`; `app/Livewire/Chat/Concerns/HasPaymentFlow.php:116-127`; `app/Livewire/Chat/OrderChat.php:84`
- **Evidência:**
```php
public float $deliveryFee = 0.0;   // OrderChat.php — sem #[Locked]
...
$order = $orderService->createOrder(..., $this->deliveryFee, $coupon, ...);   // HasPaymentFlow.php
...
$total = max(0, $subtotal + $deliveryFee + $safeServiceFee + $safeCouvertFee - $discount - $safeExtraDiscount);   // OrderService.php
```
`$subtotal` é recalculado do banco e o cupom é revalidado dentro da transação (comentário do próprio código: "nunca confiar no model resolvido antes da transação"), mas `$deliveryFee` não recebe o mesmo tratamento.
- **Impacto:** cliente no chat público adultera `deliveryFee` (ex.: zera) entre o cálculo original e `confirmOrder()`, resultando em pedido com frete abaixo do devido — perda financeira por pedido.
- **Correção proposta:** recalcular a taxa de entrega dentro de `OrderService::createOrder()` chamando `DeliveryService::validate()` novamente, mesmo padrão já aplicado ao cupom.
- **Esforço:** M
- **Teste a criar:** `OrderDeliveryFeeServerRecalculationTest`

---

### [SEV-ALTA] PDV — adição ao carrinho não valida que a filial pertence à empresa do operador
- **Categoria:** Tenancy / Segurança
- **Local:** `app/Livewire/Admin/Pdv/Concerns/HasCartManagement.php:10-50`; `app/Livewire/Admin/Pdv/Terminal.php:48`; `app/Livewire/Admin/Pdv/Concerns/HasPaymentFlow.php:139-149`
- **Evidência:**
```php
public ?int $selectedBranchId = null;   // sem #[Locked]
...
public function addProduct(int $productId): void
{
    $product = Product::withoutGlobalScopes()
        ->whereHas('branches', fn ($q) => $q->where('branches.id', $this->selectedBranchId)...)
        ->find($productId);
    // checagem só garante vínculo produto-filial, não que a filial é da empresa do operador
```
- **Impacto:** operador PDV da Empresa A adultera `selectedBranchId` para filial da Empresa B (e conhece/adivinha `productId` de B); pedido resultante fica com `company_id` de A mas `branch_id` de B — `StockService` decrementa estoque real de B para um pedido "de" A.
- **Correção proposta:** validar `Branch::where('company_id', app('current.company')->id)->find($selectedBranchId)` em todos os métodos que usam `selectedBranchId`; considerar `#[Locked]`.
- **Esforço:** M
- **Teste a criar:** `PdvCrossTenantBranchProductTest`

---

### 2.3 Dados pessoais e XSS

### [SEV-ALTA] XSS armazenado na descrição de produto — sanitizador caseiro com bypass trivial
- **Categoria:** Segurança / Dados
- **Local:** `app/Services/Html/DescriptionSanitizer.php:9-19`; sink em `resources/views/livewire/chat/order-chat.blade.php:1689`; entrada em `app/Livewire/Admin/Products/Form.php:95`
- **Evidência:**
```php
private const ALLOWED_TAGS = '<b><strong><i><em><u><br><p><ul><ol><li><span><small><a>';
$clean = strip_tags($html, self::ALLOWED_TAGS);
$clean = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $clean);   // só cobre atributo COM aspas duplas
$clean = preg_replace("/\s+on\w+\s*=\s*'[^']*'/i", '', $clean);   // só cobre COM aspas simples
```
`strip_tags` mantém `<a>`/`<span>` com **qualquer atributo**. Atributo de evento sem aspas (`<a onmouseover=alert(document.cookie)>`, válido em HTML5) escapa do filtro; `href=" javascript:..."` (espaço antes do scheme) também escapa do filtro de `javascript:`. Sink: `{!! $product->description_html !!}` na página pública de cardápio/checkout, sem autenticação.
- **Impacto:** usuário com permissão de editar produto injeta HTML/JS que executa no navegador de todos os clientes anônimos vendo o cardápio — inclusive durante o pagamento (PIX/cartão) no mesmo chat. Permite roubo de sessão, phishing de dados de pagamento.
- **Como reproduzir/confirmar:** salvar descrição `<a onmouseover=alert(document.domain)>oi</a>`; abrir `/{company}` e passar o mouse — `alert` dispara.
- **Correção proposta:** trocar sanitizador manual por biblioteca madura (ex. HTMLPurifier) com allowlist de tags **e** atributos, ou reescrever via `DOMDocument` removendo todo atributo fora de allowlist e validando `href`/`src` por scheme.
- **Esforço:** M
- **Teste a criar:** `DescriptionSanitizerTest` (atributo sem aspas, `javascript:` com espaço) + `ProductDescriptionXssTest` (feature)

---

### [SEV-ALTA] CPF, nome, e-mail, telefone e endereço do cliente logados em texto puro (cobranças Vindi), replicados para Nightwatch
- **Categoria:** Segurança / Dados
- **Local:** `app/Services/Payment/VindiService.php:149-151, 228-238, 399-445`
- **Evidência:**
```php
private function redactCardPayload(array $payload): array
{
    // só mascara card_number, card_cvv, card_token — 'customer' (cpf/cnpj, name, email, addresses) intacto
}
Log::channel('payments')->debug('Vindi payload cartão', ['payload' => $this->redactCardPayload($payload)]);
```
O comentário do próprio código confirma consciência do risco ("nunca persistir dado de cartão em log... já que 'payments' replica pro Nightwatch") mas só mitigaram o cartão, não o CPF/PII. `config/logging.php` inclui `nightwatch` no stack sempre e no canal `payments` especificamente.
- **Impacto:** toda compra com cartão no chat público vaza CPF/CNPJ, nome, e-mail, telefone e endereço completo para SaaS terceiro (Nightwatch) em texto puro — exposição de dado pessoal/sensível fora do controle direto da empresa (LGPD).
- **Correção proposta:** expandir `redactCardPayload` para também mascarar `customer.cpf`, `customer.cnpj`, `customer.email`, `contacts[].number_contact` e `addresses[]` antes de qualquer `Log::`.
- **Esforço:** P
- **Teste a criar:** `VindiServiceLogRedactionTest` (com `Log::spy()`)

---

### 2.4 Dependências

### [SEV-ALTA] CVEs encadeáveis: injeção de cabeçalho de e-mail (CRLF) via Laravel + symfony/mime, alcançável pelo checkout público
- **Categoria:** Segurança / Dados
- **Local:** `composer.json` (`laravel/framework` 12.53.0, `symfony/mime` 7.4.6); entrada em `app/Livewire/Chat/OrderChat.php:318-322` (regra `email`); sink em `app/Listeners/SendOrderConfirmationEmail.php:27`, `SendOrderDeliveredEmail.php:31` (`Mail::to($customer->email)->queue(...)`)
- **Evidência (via `composer audit`):**
  - `laravel/framework` — CVE-2026-48019/GHSA-5vg9-5847-vvmq, "CRLF injection in default email rule", afeta `<12.60.0` (instalado 12.53.0)
  - `symfony/mime` — CVE-2026-45067, "Email Header/SMTP Command Injection via CRLF in Address", afeta `<7.4.12` (instalado 7.4.6)
- **Impacto:** a regra `email` embutida do Laravel usada no campo de e-mail do checkout público (sem autenticação) pode não rejeitar CRLF; encadeado ao CVE do `symfony/mime` usado pelo Mailer, permitiria injetar cabeçalhos adicionais (ex. Bcc) nos e-mails de confirmação/entrega enviados pela plataforma.
- **Correção proposta:** `composer update laravel/framework symfony/mime symfony/mailer` para as versões corrigidas; rodar suíte + `composer lint`.
- **Esforço:** P
- **Teste a criar:** `OrderChatEmailValidationTest` — e-mails com CRLF são rejeitados

---

### 2.5 Performance

### [SEV-ALTA] Cliente HTTP do iFood sem timeout em nenhuma chamada; job de polling sem `$timeout`
- **Categoria:** Performance
- **Local:** `app/Services/Ifood/IfoodGatewayService.php:195-200` (usado por 10 métodos); `app/Jobs/PollIfoodEventsJob.php:19-49`; `app/Livewire/Admin/Orders/Index.php:280`
- **Evidência:**
```php
private function client(IfoodIntegration $integration): PendingRequest
{
    return Http::baseUrl(config('ifood.api_base_url'))->withToken(...)->acceptJson();
    // sem .timeout()
}
```
`PollIfoodEventsJob` (agendado a cada 30s) não declara `$timeout`/`$tries`/`$backoff` e itera todas as integrações ativas.
- **Impacto:** chamada lenta/travada ao iFood trava o ciclo de polling inteiro (bloqueando atualização de pedidos de **todas** as empresas naquele ciclo) e trava a requisição Livewire do admin que clica "aceitar"/"recusar" pedido iFood, prendendo um worker PHP-FPM indefinidamente.
- **Correção proposta:** adicionar `.timeout()`/`.connectTimeout()` no client; declarar `$timeout` nos jobs de polling/reconciliação/sincronização do iFood.
- **Esforço:** P
- **Teste a criar:** `IfoodGatewayServiceTest::it_times_out_polling_requests` (via `Http::fake` com delay)

---

### [SEV-ALTA] `payments.vindi_transaction_token` sem índice — full scan em todo webhook Vindi
- **Categoria:** Performance / Database
- **Local:** `database/migrations/2026_06_08_000001_add_vindi_transaction_token_to_payments_table.php:12`; usos em `app/Jobs/ProcessVindiWebhook.php:58,173,203,239`, `app/Jobs/ResolveExpiredVindiPaymentsJob.php:44-50`
- **Evidência:**
```php
$table->string('vindi_transaction_token')->nullable()->after('asaas_payment_id');  // sem ->index()
...
$payment = Payment::where('vindi_transaction_token', $this->transactionToken)->first();
```
Coluna irmã `asaas_payment_id` foi criada com `->index()`; `vindi_transaction_token`/`status`/`expires_at` não têm índice.
- **Impacto:** todo webhook Vindi (4 pontos de lookup só em `ProcessVindiWebhook`) faz full table scan em `payments`, que cresce a cada pedido pago de todas as empresas; job periódico (15 min) idem.
- **Correção proposta:** migration adicionando índice (idealmente `unique`) em `vindi_transaction_token`, e índice composto `(payment_gateway, status, expires_at)`.
- **Esforço:** P
- **Teste a criar:** assertion de schema (`Schema::getIndexes('payments')`)

---

### [SEV-ALTA] Relatórios de pedidos sem paginação/chunk; PDF de super admin sem filtro de data obrigatório
- **Categoria:** Performance
- **Local:** `app/Http/Controllers/Admin/Orders/ReportPdfController.php:33-41`; `app/Livewire/Admin/Orders/Report.php:94-98,140-148`; `app/Livewire/Admin/Pdv/Report.php:81-83`
- **Evidência:**
```php
$orders = $query->when($request->date_start, ...)->when($request->date_end, ...)->latest()->get();  // sem paginate/chunk
```
Para super admin a query usa `withoutGlobalScope(CompanyScope::class)` — sem parâmetros de data, carrega **todos os pedidos de todas as empresas** de uma vez para renderizar via DomPDF.
- **Impacto:** sob carga real, pode estourar memória/travar resposta por dezenas de segundos.
- **Correção proposta:** tornar `date_start`/`date_end` obrigatórios com intervalo máximo (ex. 92 dias); trocar agregações em PHP por `SUM()/COUNT()/groupBy` no banco; usar `cursor()`/`chunk()` no export.
- **Esforço:** M
- **Teste a criar:** `OrdersReportPdfTest::it_requires_a_bounded_date_range`

---

### 2.6 Segurança e performance — média

### [SEV-MÉDIA] Canal privado `wallet.{companyId}` autoriza qualquer papel, não só `company_admin`
- **Categoria:** Segurança / Pagamentos
- **Local:** `routes/channels.php:9-11`; comparar com `routes/web.php:81-87`
- **Evidência:**
```php
Broadcast::channel('wallet.{companyId}', function ($user, $companyId) {
    return $user->companies()->where('companies.id', (int) $companyId)->exists();   // qualquer papel
});
```
A tela HTTP `/admin/wallet` exige `company.role:company_admin`, mas o canal aceita qualquer vínculo com a empresa.
- **Impacto:** colaborador operacional (`cozinha`, `caixa`, `entrega`) inscrito no canal recebe saldo financeiro real da empresa em tempo real, dado que o próprio sistema restringe a administradores.
- **Correção proposta:** alinhar checagem do canal ao critério da rota HTTP (`isCompanyAdmin`/`hasPermission('wallet.view', ...)`).
- **Esforço:** P
- **Teste a criar:** `test_non_admin_role_cannot_authorize_wallet_broadcast_channel`

---

### [SEV-MÉDIA] Webhook Vindi loga corpo bruto antes da validação de assinatura
- **Categoria:** Segurança / Dados
- **Local:** `app/Http/Controllers/VindiWebhookController.php:15-16`
- **Evidência:**
```php
$data = $request->all();
Log::channel('webhook')->debug('Vindi webhook recebido', ['payload' => $data]);   // ANTES do hash_equals
$sellerToken = $data['transaction']['seller_token'] ?? ...;
if (! hash_equals(..., $sellerToken)) { ... }
```
Diferente de `AsaasWebhookController` (loga só depois de validar).
- **Impacto:** qualquer POST não autenticado ao endpoint público grava seu corpo integral em log (replicado ao Nightwatch) antes de qualquer verificação — poluição de log com dados forjados.
- **Correção proposta:** mover o log para depois de `hash_equals`; em falha de auth, logar só metadados.
- **Esforço:** P
- **Teste a criar:** `VindiWebhookLoggingTest`

---

### [SEV-MÉDIA] Webhook Asaas loga payload completo sem redação
- **Categoria:** Segurança / Dados
- **Local:** `app/Http/Controllers/AsaasWebhookController.php:28`
- **Evidência:**
```php
$data = $request->json()->all();
Log::channel('webhook')->info('data Asaas webhook recebido', ['data' => $data]);
```
Loga corpo inteiro (que pode incluir `customer`/`creditCardHolderInfo` com CPF/CNPJ), replicado ao Nightwatch; linha seguinte já loga só `event` de forma seletiva, sugerindo resquício de debug.
- **Correção proposta:** remover/reduzir o log de `$data` completo a campos não sensíveis.
- **Esforço:** P
- **Teste a criar:** mesmo padrão do achado de Vindi

---

### [SEV-MÉDIA] Dependências desatualizadas adicionais com CVEs (baixa/média alcançabilidade confirmada)
- **Categoria:** Segurança / Dados
- **Local:** `composer.json` — `guzzlehttp/guzzle` 7.10.0 (CVE-2026-69246, host bypass), `livewire/livewire` 4.2.1 (GHSA-g3hc-697w-wm82, DOM-XSS client-side), `mtdowling/jmespath.php` 2.8.0 (crítico por classificação, mas sem uso dinâmico de expressão JMESPath encontrado no código — reachability não confirmada), `barryvdh/laravel-dompdf` (múltiplos CVEs médios, mas templates de recibo/relatório não usam `{!! !!}`), `league/commonmark` (via Symfony Mailer, mas nenhum Mailable usa `Content::markdown()` — caminho dormente)
- **Impacto:** superfície de ataque acumulada; maioria exige condição específica não claramente presente no código do app.
- **Correção proposta:** `composer update` respeitando constraints para elevar todos; rodar suíte completa (atenção a Livewire 4.3.x, pode mudar comportamento client-side).
- **Esforço:** M

---

### [SEV-MÉDIA] `VindiService`/`FocusNfeService` — chamadas HTTP sem timeout
- **Categoria:** Performance
- **Local:** `app/Services/Payment/VindiService.php:172-174,222-223`; `app/Services/Fiscal/FocusNfeService.php:30,97,124,171,193,213,230`; chamado sincronamente em `app/Livewire/Chat/Concerns/HasPaymentFlow.php:441`
- **Evidência:** nenhuma chamada define `.timeout(`, diferente de `AsaasService.php` (`.timeout($timeout)` + circuit breaker) e `GeocodingService.php` (`.timeout(5)`), que já seguem o padrão correto.
- **Impacto:** Vindi/Focus NFe lenta ou fora do ar trava a requisição do cliente (checkout) ou do admin (config fiscal) sem limite de tempo.
- **Correção proposta:** adicionar `.timeout(10-15)`/`.connectTimeout(5)` seguindo o padrão de `AsaasService`. Autorização de cartão deve continuar síncrona (UX correta); o fix é o timeout, não mover para fila.
- **Esforço:** P
- **Teste a criar:** `VindiServiceTest::it_times_out_on_slow_gateway_response`

---

### [SEV-MÉDIA] `SuperAdmin/Companies/Show` — ~12 queries por render, sem cache, filtros não sargáveis
- **Categoria:** Performance
- **Local:** `app/Livewire/SuperAdmin/Companies/Show.php:20-108`
- **Evidência:** `monthlyHistory` carrega todos os pedidos pagos dos últimos 5 meses em objetos PHP para somar por mês em vez de `GROUP BY` no banco; `whereMonth()/whereYear()` não sargáveis; nenhum `Cache::remember` (diferente do `SuperAdmin/Dashboard.php`, que cacheia por 5 min).
- **Correção proposta:** `Cache::remember` como no Dashboard; substituir por `selectRaw("DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*), SUM(total)")->groupBy('month')`.
- **Esforço:** M
- **Teste a criar:** contagem de queries via `DB::enableQueryLog()`

---

### [SEV-MÉDIA] `Admin/Dashboard` — contagem mensal (plano Free) fora do cache, filtro não sargável
- **Categoria:** Performance
- **Local:** `app/Livewire/Admin/Dashboard.php:93-102`
- **Evidência:**
```php
$monthlyOrderCount = $company->orders()
    ->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)
    ->whereNotIn('status', ['cancelled'])->count();   // fora do Cache::remember usado para $todayOrders
```
- **Impacto:** roda a cada `render()` para empresas do plano Free (segmento com mais contas), sem se beneficiar do índice composto `(company_id, created_at)`.
- **Correção proposta:** mover para o mesmo `Cache::remember` já usado; trocar `whereMonth/whereYear` por `whereBetween`.
- **Esforço:** P
- **Teste a criar:** `DashboardTest::it_caches_monthly_order_count_for_free_plan_companies`

---

### [SEV-MÉDIA] Recálculo de total/fee duplicado em `Orders/Show.php` fora do `OrderService`
- **Categoria:** Qualidade
- **Local:** `app/Livewire/Admin/Orders/Show.php:989-1008` (`saveItems`); comparar com `app/Services/Order/OrderService.php:373-410` (`recalculateOrderTotals`)
- **Evidência:**
```php
$subtotal = $this->order->items()->sum('subtotal');
$total = max(0, $subtotal + $deliveryFee - $discount);
$fees = app(FeeCalculator::class)->calculate($currentCompany, $feeBase, $total);
$this->order->update(['subtotal' => $subtotal, 'total' => $total, 'fee' => $fees['fee'], 'net_value' => $fees['net_value']]);
```
Mesma regra já implementada no Service com nomes de campo ligeiramente divergentes.
- **Impacto:** qualquer mudança futura na fórmula de fee/total precisa ser lembrada em dois lugares; já há pequena divergência hoje.
- **Correção proposta:** extrair método público no `OrderService` (ex. `recalculateTotalsFromItems`) e fazer `Show::saveItems()` chamá-lo na mesma transação.
- **Esforço:** M
- **Teste a criar:** `OrderServiceTest::recalculateTotalsFromItems mantém total e fee consistentes com FeeCalculator`

---

### [SEV-MÉDIA] `AsaasRefundGateway` sem nenhum teste dedicado
- **Categoria:** Qualidade (cobertura de teste)
- **Local:** `app/Services/Refund/AsaasRefundGateway.php` (45 linhas)
- **Evidência:** `grep -rn "AsaasRefundGateway" tests/` sem resultado; único teste que toca o caminho Asaas no reembolso testa apenas o guard-clause de falha (`VindiRefundTest.php:442`). `VindiRefundGateway` tem 5 testes dedicados cobrindo sucesso, assíncrono, rejeição, parcial.
- **Impacto:** regressão no mapeamento de status da resposta Asaas só seria percebida em produção, num fluxo financeiro real.
- **Correção proposta:** teste espelhando `VindiRefundTest.php`, mockando `AsaasService::refundPayment` para os 3 desfechos.
- **Esforço:** P
- **Teste a criar:** `tests/Feature/Payment/AsaasRefundTest.php`

---

### [SEV-MÉDIA] `CartOptionPricing` (precificação server-side de opções de produto) sem cobertura de teste
- **Categoria:** Qualidade (cobertura de teste)
- **Local:** `app/Services/Order/CartOptionPricing.php` (184 linhas, `resolve()`)
- **Evidência:** nenhum teste referencia `CartOptionPricing`/`optionSelections`. A classe resolve preço de adicionais/variantes ignorando valor enviado pelo cliente e escopa `ProductOption` por `group_id` para (comentário do código) "prevent cross-company option injection".
- **Impacto:** peça que decide o preço real de produtos com adicionais; regressão (ex. variante não aplicada, opção de outra empresa aceita) não tem rede de segurança nenhuma.
- **Correção proposta:** teste unitário cobrindo opção simples, variante, grupo/opção inválido (deve lançar `RuntimeException`), tentativa de opção de outro produto/empresa.
- **Esforço:** P
- **Teste a criar:** `tests/Unit/CartOptionPricingTest.php`

---

### 2.7 Baixa severidade

### [SEV-BAIXA] `Orders/Index::render()` — `todayClosing()` + kanban até ~10 queries por render, sem cache
- **Categoria:** Performance
- **Local:** `app/Livewire/Admin/Orders/Index.php:418-444, 446-531`
- **Evidência:** `todayClosing()` usa `whereDate('created_at', today())`, não sargável contra o índice composto `(company_id, created_at)`; kanban executa até 1+9 queries (uma por status) a cada render.
- **Correção proposta:** memoizar com `#[Computed]` + `Cache::remember` de 30-60s; trocar `whereDate` por `whereBetween`.
- **Esforço:** M

---

### [SEV-BAIXA] Precificação de assinatura (addons) somada dentro do componente Livewire, repetida ~12 vezes
- **Categoria:** Qualidade
- **Local:** `app/Livewire/Admin/Settings/BillingSettings.php` (`combinedAddonExtra`, e ~12 pontos de uso, ex. linha 638)
- **Evidência:** `$combinedAmount = $plan->monthlyPrice() + $extra['amount'];` passado direto pro `AsaasService::createCreditCardCharge`, dentro de um componente de >1300 linhas.
- **Correção proposta:** extrair para Service (`app/Services/Company` ou `Payment`), reduzindo duplicação e centralizando a regra de precificação de assinatura.
- **Esforço:** M

---

### [SEV-BAIXA] Fórmula de taxa de plataforma sobre PIX Vindi recalculada no dashboard, duplicando Services de Finance
- **Categoria:** Qualidade
- **Local:** `app/Livewire/SuperAdmin/Dashboard.php:73-92`; `app/Livewire/SuperAdmin/Companies/Show.php:54`
- **Evidência:** `round($vindiPixAmountThisMonth * $vindiPixPlatformRate, 2)` repete fórmula já presente em `TransactionService.php:38`, `WalletService.php:51,188`, `PaymentOrchestrator.php:100`.
- **Impacto:** é só exibição (não move dinheiro), mas sem teste comparando com o valor persistido, uma mudança de regra nos Services pode deixar o dashboard desatualizado sem ninguém perceber.
- **Correção proposta:** mover cálculo para método de leitura em `TransactionService`/`WalletService`.
- **Esforço:** P

---

## 3. Plano de correção

Agrupado por lote (PR), ordenado por severidade × facilidade:

**Lote 1 — Isolamento multi-tenant em Livewire Admin (crítico, esforço P/M por item)**
Corrigir `ProductPolicy`, `BranchPolicy`, `ProductCategoryPolicy`, `CouponPolicy`, `RolePolicy` para checar `company_id` do recurso; condicionar `withoutGlobalScope` a `isSuperAdmin` em `Coupons/Index`, `Products/Index`, `Categories/Index`, `Branches/Index`, `Stock/Index`; adicionar `authorize()` faltante em `edit()`. Um teste cross-tenant por módulo.

**Lote 2 — IDOR no chat público (crítico)**
Corrigir `resumePendingOrder()` para filtrar por `customer_id`+`company_id`; recalcular `deliveryFee` server-side dentro de `OrderService::createOrder()`.

**Lote 3 — Broadcast de pedidos (crítico)**
Trocar `Channel` por `PrivateChannel` nos 5 eventos de pedido/fiscal; adicionar autorização em `routes/channels.php`; ajustar assinatura no frontend (Echo).

**Lote 4 — Exclusão de usuário cross-tenant (crítico)**
Corrigir `Users/Index::removeUser` + `UserDeletionService` para escopar por empresa.

**Lote 5 — PDV e formulário de produto (alto)**
Validar `selectedBranchId`/`selectedBranches` contra a empresa do ator em `Pdv/Concerns/HasCartManagement.php` e `Products/Form.php`.

**Lote 6 — Dados pessoais em log + XSS (alto)**
Redigir CPF/PII nos logs de `VindiService`; mover log do webhook Vindi para depois da validação; reduzir log do webhook Asaas; corrigir `DescriptionSanitizer` (trocar por HTMLPurifier ou reescrever via DOM).

**Lote 7 — Dependências (alto)**
`composer update laravel/framework symfony/mime symfony/mailer` (prioridade); depois `guzzlehttp/guzzle`, `livewire/livewire`, `mtdowling/jmespath.php`, `barryvdh/laravel-dompdf`, `league/commonmark`. Rodar suíte completa após cada leva.

**Lote 8 — Performance de gateways e DB (alto)**
Timeout em `IfoodGatewayService`, `VindiService`, `FocusNfeService`; `$timeout` nos jobs de polling/reconciliação do iFood; índice em `payments.vindi_transaction_token` (+ composto `payment_gateway,status,expires_at`); tornar `date_start`/`date_end` obrigatórios nos relatórios de pedidos.

**Lote 9 — Canal de carteira + performance de dashboards (médio)**
Alinhar `wallet.{companyId}` a `company_admin`; cachear `SuperAdmin/Companies/Show` e a contagem mensal do `Admin/Dashboard`; trocar `whereMonth/whereYear` por `whereBetween`.

**Lote 10 — Qualidade e testes (médio/baixo)**
Extrair recálculo de total para `OrderService`; testes para `AsaasRefundGateway` e `CartOptionPricing`; consolidar cálculo de addon pricing e taxa de plataforma em Services.

**Lote 11 — DX/CI**
`php artisan test` estoura memória com `memory_limit` padrão de 128M neste ambiente (suíte completa passa com `1024M`: 767 testes, 2225 assertions, 0 falhas). Ajustar `memory_limit` no CI/composer scripts.

---

## 4. Suspeitas não confirmadas

- **`app/Jobs/ProcessWithdrawal.php:29-53`**: `handle()` marca saque como `processing` (transação idempotente correta, com `lockForUpdate`), mas o corpo do `if ($canProcess)` está vazio — sem chamada real a gateway. Pode ser intencional (saque gerenciado manualmente no painel Yapay), mas não confirmado se saques ficam presos em `processing`.
- **`Users/Index::saveEditRole`**: usa `User::findOrFail($this->editUserId)` sem checar empresa, mesmo padrão dos achados críticos, mas não encontrado caminho de escalonamento efetivo (`hasPermission()` depende do pivot `company_user`).
- **Demais `Concerns` do PDV** (`HasProductLookup`, `HasCatalog`, `HasOpenTabs`, `HasCustomerManagement`, `TabTerminal`) usam `withoutGlobalScopes()` no mesmo padrão do achado de estoque/PDV, não revisados linha a linha — recomenda-se auditoria dedicada.
- **`Customer::findByPhoneGlobally()`**: ignora scope por design; não confirmados todos os chamadores.
- **`ProductObserver.php:22`**: usa `IfoodIntegration::withoutGlobalScopes()`; não confirmado se a query subsequente é restrita corretamente.
- **CVE crítico do `mtdowling/jmespath.php`**: reachability não confirmada (depende de uso interno do AWS SDK/flysystem-s3); recomendada atualização preventiva.
- **CVEs do `barryvdh/laravel-dompdf`**: sem caminho de exploração confirmado nos templates atuais (não usam `{!! !!}`), mas nem todos os dados que chegam aos templates de PDF foram revisados linha a linha.
- **`ReconcileIfoodSettlementsJob`/`RecordPlatformYieldSnapshot`/`UpdateCompanyBalancesJob`**: padrões de `->get()` sem chunk e sem `$timeout`, mas atualmente desativados/comentados em `routes/console.php` — revisar antes de reativar.
- **Índices em `orders.order_type`/`orders.payment_method`**: não medido via `EXPLAIN`; impacto real depende de volume por empresa.

---

## Verificações sem achados (checadas e consideradas OK)

- Mass assignment: todos os 42 models declaram `$fillable` explícito; nenhum `$guarded = []`.
- SQL injection: todo uso de `whereRaw`/`selectRaw`/`DB::raw` usa strings estáticas, sem interpolação de input do usuário.
- Segredos hardcoded: nenhum encontrado; `.env` não rastreado no git.
- Webhooks (Asaas/Vindi/Fiscal/iFood): todos validam assinatura/token com `hash_equals`; jobs de processamento usam `lockForUpdate()` + `idempotency_key` dentro de `DB::transaction()` — sem duplo crédito em reentrega.
- Colunas monetárias: nenhuma em `float`/`double`; todas `decimal`.
- Rate limiting: login (Fortify), `/cadastro` e confirmação de pedido no chat têm proteção equivalente.
- Nenhuma chamada HTTP a gateway dentro de `DB::transaction()` (verificado em `PaymentOrchestrator`, `RefundService`, `FiscalNoteService`, `WithdrawalService`).
- Catálogo do PDV e do chat público já usam `Cache::remember` + `#[Computed]` corretamente.
- `AsaasService` já implementa timeout + circuit breaker — padrão de referência para os demais gateways.
- Maioria dos Jobs declara `$tries`/`$backoff`/`ShouldBeUnique` corretamente.
- npm audit: vulnerabilidades restritas à cadeia de build (vite/postcss/nanoid) ou a caminho de código morto (socket.io via laravel-echo, não usado — Echo configurado para `broadcaster: 'reverb'`).
