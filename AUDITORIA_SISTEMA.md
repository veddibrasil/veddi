# Auditoria do sistema — Veddi / Mister Coxinha

**Período:** 15 a 19/10/2026 · **Branch:** `dev` (a partir de `94691511`) · **Estado:** correções aplicadas no working tree, **não commitadas**.

Esta auditoria não declara o sistema livre de bugs nem seguro. Ela cobre o que foi executado e provado nas condições descritas em "Escopo" e "Limitações".

---

## 1. Resumo

| | Qtde |
|---|---|
| Itens registrados na seção 3 (alguns agrupam várias telas com a mesma causa) | **65** |
| — P0 crítico | 3 |
| — P1 alto | 14 |
| — P2 médio | 31 |
| — P3 baixo | 17 |
| Corrigidos (com teste e/ou reprodução no navegador) | 53 |
| Parcial (paliativo aplicado) | 1 |
| Identificados, sem correção (decisão de negócio ou UX; 9 de interface com evidência só no código) | 11 |
| Hipóteses não confirmadas (fora da contagem) | 16 |

**Os mais graves (todos corrigidos):**

1. **Escrita entre empresas por props Livewire adulteradas (P0).** O navegador pode reescrever qualquer propriedade pública de um componente Livewire. Várias telas guardavam `isSuperAdmin`, `can*`, `company_id` e ids de registro em props abertas e confiavam nelas depois. Provado: um usuário **cozinha** da empresa A ajustava o estoque da empresa B; um **garçom** de A lia e removia itens de comandas abertas de B; um admin de A alterava preço e apagava adicionais de produtos de B.
2. **Middlewares de rota não rodavam em `/livewire/update` (P1).** Um super admin rebaixado se re-promovia pela aba aberta; um admin rebaixado a cozinha criava um novo admin; empresa bloqueada seguia operando pelas abas abertas.
3. **XSS armazenado na descrição de produto (P1, regressão do achado de 17/09).** O sanitizador não tratava os filhos de tags desembrulhadas (`<font><img onerror=…>`). O HTML aparece no chat público, no PDV e na mesma origem do `/admin` e `/superadmin`.
4. **Onboarding (P1):** um visitante anônimo reativava empresa **bloqueada** e trocava a assinatura dela, forjando `pendingCompanyId`.
5. **Dinheiro e estoque no ciclo do pedido (P1):** PIX expirado/cobrança recusada não devolvia estoque; webhook de recusa de uma tentativa anterior cancelava pedido já pago; "Trocar forma de pagamento" na tela do PIX deixava o pedido anterior vivo (pedido e estoque em dobro); pagamento aprovado para pedido cancelado o reabria em silêncio.
6. **Isolamento por filial e por empresa em telas de usuários e pedidos (P1):** busca de usuários listava usuários de **todas** as empresas; qualquer admin abria/gravava permissões de usuários de outras empresas; papéis presos a uma filial abriam e alteravam pedidos de outras filiais pela URL; "Vincular existente" puxava o usuário de outra empresa e trocava o painel dele.

**O que ainda precisa de ação (detalhe na seção 8):**

- **Chat por telefone (P1, decisão de negócio):** quem digita o telefone de um cliente vê nome, histórico e pode editar o cadastro dele. Foi aplicado só um paliativo (limite de tentativas, cópia entre empresas restrita ao nome). A correção definitiva é verificar a posse do número (OTP).
- **Antes do deploy:** confirmar que `request()->ip()` devolve o IP real do cliente atrás do proxy do Laravel Cloud (senão os novos limites por IP viram globais) e que a conta do Horizon tem `is_super_admin`.
- Revisar e commitar as mudanças (88 arquivos alterados, ~1.900 linhas).

---

## 2. Escopo e método

### Ambiente
- Servidor local `php -S 127.0.0.1:8010` com banco **SQLite isolado** (fora do repositório), migrations + dados **100% fictícios**: empresas "Alfa Lanches" (plano pro, 2 filiais) e "Beta Pizzas" (free), usuários por papel (`company_admin`, `branch_manager`, `caixa`, `cozinha`, `garcom`) e super admin, 34 pedidos, cupons, estoque.
- Sem saídas externas: e-mail em log, fila em banco sem worker, broadcast em log, Discord/Nightwatch/WhatsApp/iFood/Vindi/Asaas/Focus com credenciais vazias, S3 apontando para endereço inválido. O `.env` e o MySQL local não foram lidos nem usados.
- Testes sempre com `DB_CONNECTION=sqlite DB_DATABASE=:memory:`.

### Organização (agentes)
| Agente | Responsabilidade |
|---|---|
| Líder | Reconhecimento, ambiente, navegação real (Chrome), matriz de acesso por papel, triagem, correções de pedidos/estoque/relatórios/usuários/super admin, integração |
| Segurança — tenant/autorização | Varredura `#[Locked]`, policies, escopo de empresa/filial, PDV, super admin |
| Segurança — superfície | Auth, sessão, webhooks, uploads, XSS, SSRF, dependências, configuração |
| Fluxos e regras de negócio | Pedido, pagamento, estoque, cupom, PDV, WhatsApp, iFood, onboarding |
| Interface | Revisão estática de layout, responsividade e acessibilidade |
| Correção (4 agentes) | PDV · cardápio/filiais · ciclo de vida do pedido · superfície — cada um com lista fechada de arquivos |
| Qualidade | Revisão do diff integrado em busca de regressões |

### Fluxos efetivamente executados
- **Navegador (desktop e iframe de 360/375/390/768/1280 px):** login inválido e válido; chat público completo no celular (filial → cardápio → carrinho → telefone → cupom de outra empresa e cupom válido → retirada → observação com HTML → dinheiro → confirmação → cancelamento pelo cliente); adulteração de preço, de `selectedBranchId` e de props via `Livewire.$wire`; duplo clique em adicionar e em confirmar; detalhe do pedido e escape de HTML no admin; ajuste de estoque; edição de produto, cupom e filial; mudança de status de pedido; varredura de overflow horizontal em 26 telas × 3 larguras; telas de cupons, estoque e login a 375 px antes/depois.
- **HTTP (curl com sessão real):** matriz de acesso de 32 rotas para 7 usuários (5 papéis + 2 empresas + super admin), antes e depois das correções; esqueci a senha com e-mail existente/inexistente e `X-Forwarded-Host` forjado; `/admin/register`; simuladores de pagamento.
- **Banco:** conferência de pedido, itens, `stock_movements`, `coupon_usages`, histórico de status e cadastro de cliente após cada fluxo.
- **Testes automatizados:** 104 testes novos de prova/regressão em `tests/Feature/Audit/{Tenant,Flows,Surface,Qa}` + outros nos arquivos de teste dos módulos (seção 5).

### Não validado em execução real (ver Limitações)
Uploads para S3, pagamentos reais (PIX/cartão Vindi, Asaas), webhooks reais, WhatsApp/iFood ao vivo, impressão QZ Tray, NFC-e, tempo real (Reverb), filas com worker, e-mail real, PDV completo pelo navegador (coberto por testes).

---

## 3. Achados por prioridade

Legenda de status: **corrigido** = código alterado + teste/reprodução passando · **identificado** = sem correção nesta rodada · **parcial** = paliativo aplicado.
"Prova" indica o teste que falhava antes e passa depois (caminho relativo a `tests/Feature/Audit/` quando não indicado) ou a reprodução no navegador.

### P0 — Crítico

#### T-01 · Estoque: qualquer papel do painel ajusta estoque de outra empresa — segurança — corrigido
- **Fluxo/Local:** `/admin/stock` — `app/Livewire/Admin/Stock/Index.php`.
- **Reproduzir:** logado como `cozinha` da empresa A, no console: `$wire.set('isSuperAdmin', true)`, depois `openAdjustModal(produtoB, filialB)` e `applyAdjustment` com quantidade 999.
- **Esperado × observado:** recusar; o estoque de B virava 999 e gravava `StockMovement` falso.
- **Impacto:** papel mais baixo altera inventário de qualquer empresa; reabre o achado crítico de 17/09.
- **Correção:** `#[Locked]` em `isSuperAdmin`/`canAdjust`/`canToggle`; checagens usam `auth()->user()->isSuperAdmin()`; ação valida também a filial do papel. **Esforço:** P. **Prova:** `Tenant/LockedScopePropsCrossTenantTest`, `Tenant/BranchScopeGapsTest`.

#### T-02 · Produto: adicionais de outra empresa alterados e apagados — segurança — corrigido
- **Local:** `app/Livewire/Admin/Products/Form.php` (`ProductOptionGroup`/`ProductOption` sem escopo de empresa; ids vindos do navegador).
- **Reproduzir:** admin de A envia `optionGroups=[{group_id: grupoB, options:[{id: opçãoB, additional_price: '0.01'}]}]` e salva.
- **Esperado × observado:** recusar; o adicional de B caía para R$ 0,01 e as demais opções do grupo de B eram apagadas.
- **Correção:** grupo/opção sempre filtrados pela empresa e pelo grupo pai; `attachExistingGroup`/lista de grupos filtradas; flags `#[Locked]`. **Esforço:** P/M. **Prova:** `Tenant/ProductFormOptionGroupCrossTenantTest`.

#### T-04 · PDV Comandas: garçom lê e altera comanda aberta de outra empresa — segurança — corrigido
- **Local:** `app/Livewire/Admin/Pdv/TabTerminal.php`, `Concerns/HasOpenTabs.php` (`withoutGlobalScopes()->find($openTabOrderId)` sem empresa/filial).
- **Reproduzir:** garçom de A chama `selectOpenTab(idComandaB)` e depois `removeTabItem`/`updateTabItemQuantity`.
- **Esperado × observado:** recusar; o item de B era removido e o HTML de A mostrava os itens de B.
- **Correção:** ids de comanda/mesa `#[Locked]`; consulta única `openTabsQuery()` (empresa + filial + comanda aberta) usada em todas as ações e computeds. **Esforço:** P. **Prova:** `Tenant/TabTerminalCrossTenantTest`.

### P1 — Alto

| ID | Título | Tipo | Local / fluxo | Reproduzir | Esperado × observado | Correção | Esf. | Status / prova |
|---|---|---|---|---|---|---|---|---|
| F-01 | Permissões de usuário de outra empresa | seg. | `Admin/Users/Permissions` | admin de A abre `/admin/users/{id de B}/permissions` | 404 × 200 com nome; `save()` gravava overrides do usuário de B | checar vínculo com a empresa + `users.manage` no mount e no save | P | corrigido · `Admin/BranchAndUserScopeTest`, navegador |
| F-02 | Papel de filial abre/altera pedido de outra filial | seg. | `Orders/Show`, `ReceiptPdfController`, `OrderPolicy` | gerente/caixa/cozinha da filial Centro abre `/admin/orders/{pedido do Bairro}` | 404 × 200 e podia mudar status | `User::canAccessBranch()` no detalhe, cupom e policy | P | corrigido · matriz curl, `BranchAndUserScopeTest` |
| F-04 | Busca de usuários lista usuários de todas as empresas | seg. | `Admin/Users/Index` (`orWhere` solto escapava do `whereHas`) | buscar "beta.test" como admin de A | só usuários de A × e-mails de B | agrupar o OR | P | corrigido · `BranchAndUserScopeTest` |
| F-06 / T-10 | "Vincular existente"/"Atribuir tipo" sequestra usuário de outra empresa | seg. | `Users/Index::linkUser`, `Roles/Index::assignUser` + `IdentifyCompany` (empresa de menor id) | admin de A vincula o e-mail do dono de B | recusar × dono de B passa a cair no painel de A; `exists:` enumerava e-mails | só vincula usuário sem outra empresa e não super admin; mensagem única | P | corrigido · `BranchAndUserScopeTest`, `Tenant/LinkForeignUserTest` |
| T-03 | Categoria criada em outra empresa | seg. | `Products/Form::saveCategory` | `set company_id=B` + `saveCategory` | recusar × categoria no cardápio de B | usar empresa atual para quem não é super admin | P | corrigido · `ProductFormOptionGroupCrossTenantTest` |
| T-05 | Props abertas dão leitura/escrita entre empresas | seg. | `Orders/Report`, `Coupons`, `Categories`, `Branches/Form`, `Products/Index` | `set isSuperAdmin=true` (ou `company_id`) | recusar × relatório/CSV de todas as empresas, códigos de cupom de todas, filial criada em B, cardápio de B reordenado | `#[Locked]` + super admin recalculado pelo usuário | P | corrigido · `LockedScopePropsCrossTenantTest` |
| T-06 | Middlewares não rodam em `/livewire/update`; super admin sem gate nos métodos | seg. | `AppServiceProvider`, `app/Livewire/SuperAdmin/*` | super admin rebaixado usa a aba aberta para `toggleSuperAdmin(self)` | 403 × volta a ser super admin; admin rebaixado cria novo admin | `Livewire::addPersistentMiddleware([CheckCompanyRole, RequireSuperAdmin, EnsureCompanyIsActive])` + trait `RequiresSuperAdmin` | P | corrigido · `Tenant/SuperAdminActionGateTest` (POST real em `/livewire/update`) |
| T-07 | Cozinha/bar/entrega cancelam pedido pago e mudam qualquer status | seg./fin. | `Orders/Show` (`canUpdate`/`userStation` abertos; restrição só na view) | cozinha `confirmCancel` em pedido pago | 403 × cancela e dispara estorno | flags `#[Locked]`; cancelar, estornar, confirmar pagamento e editar itens exigem `! userStation` (editar endereço continua liberado às estações, como antes) | P | corrigido · `Tenant/OrderShowStationRoleBypassTest`, `Qa/QaRegressionTest` |
| C1 | Cancelamento automático não devolve estoque | bug | `ResolveExpiredVindiPaymentsJob`, `ProcessOrder::failed`, `ProcessVindiWebhook::handlePaymentFailed` | PIX expira com 3 unidades | estoque volta a 10 × fica 7 | `OrderService::cancelBySystem()` central (lock, status, estoque, histórico) | P/M | corrigido · `Flows/StockNotRestoredOnAutomaticCancellationTest` |
| C2 | Webhook de recusa de tentativa anterior cancela pedido pago | bug/fin. | `ProcessVindiWebhook` | cartão 1 recusado, cartão 2 aprovado, chega "Não Aprovada" do 1 | continua pago × cancelado sem estorno | só cancela pedido não pago e sem outra tentativa pendente | P | corrigido · `Flows/DeclinedCardWebhookCancelsPaidOrderTest` |
| C3 | "Trocar forma de pagamento" no PIX duplica o pedido | bug/fin. | `Chat/Concerns/HasPaymentFlow::changePaymentMethod` | gerar QR, voltar e pagar em dinheiro | 1º pedido cancelado × 2 pedidos vivos, estoque e cupom em dobro | cancelar também `awaiting_payment` via `cancelBySystem` | P | corrigido · `Flows/ChatChangePaymentMethodAfterPixTest` |
| S2 | XSS armazenado na descrição de produto | seg. | `Services/Html/DescriptionSanitizer` | descrição `<font><img src=x onerror=…></font>` | removido × executa no chat, PDV e na origem do admin | laço sobre a lista viva, filhos promovidos re-sanitizados, `script/style/iframe…` descartados com conteúdo | P | corrigido · `Surface/ProductDescriptionXssBypassTest`, `tests/Unit/DescriptionSanitizerTest` (+10 casos) |
| S3 | Anônimo reativa empresa bloqueada no onboarding | seg./fin. | `Onboarding/RegisterForm::$pendingCompanyId` | `set pendingCompanyId=<bloqueada>` + `submitCard` | recusar × `BLOCKED`→`ACTIVE`, assinatura e cartão trocados | `#[Locked]` + id na sessão + exige `PENDING_PAYMENT` | P | corrigido · `Surface/OnboardingRegisterFormTest` |
| S1 / F-09 | Chat identifica o cliente só pelo telefone | seg./LGPD | `Chat/Concerns/HasCustomerProfile`, `CustomerService` | digitar o telefone de um cliente | exigir posse × mostra nome, histórico, cartões salvos (rótulo), PIX pendente; permite editar cadastro, cancelar pedido, forjar opt-in; telefone de outra empresa era copiado com CPF/e-mail/endereço | **paliativo:** 30 consultas/10 min por IP+loja (folga para Wi-Fi da loja); cópia entre empresas só do nome; telefone fora dos logs. **Falta:** OTP | M/G | **parcial** · 2 testes `->skip` em `Surface/ChatPhoneLookupPiiTest` aguardando OTP |

### P2 — Médio

| ID | Título | Tipo | Local | Reproduzir → esperado × observado | Correção | Esf. | Status |
|---|---|---|---|---|---|---|---|
| F-03 | Suíte de testes posta erros no Discord real | ops | `DiscordWebhookHandler` (curl cru) + `phpunit.xml` | rodar a suíte com o `.env` local → nada sai × logs de teste enviados ao webhook | zerar `DISCORD_*_WEBHOOK_URL` no `phpunit.xml` | P | corrigido · `tests/Unit/LoggingTestIsolationTest` |
| F-05 | Busca de pedidos ignora filial/estação/status/canal | seg. | `Orders/Index` (lista e kanban) | gerente busca nome de cliente → só sua filial × pedidos de outras | agrupar `orWhereHas` | P | corrigido · `BranchAndUserScopeTest` |
| F-08 | Simuladores de pagamento sem autenticação | seg. | `dev/simulate/*` | com `APP_DEBUG=true` (o `.env` local usa ngrok público) POST anônimo ativa empresa/paga pedido | exigir também `! isProduction()` | P | corrigido · `Payment/SimulatePaymentGuardTest` |
| T-08 | Escopo de filial em relatório, PDF e estoque | seg. | `Orders/Report`, `ReportPdfController`, `Stock` | gerente vê receita de outras filiais; cozinha baixa PDF da empresa | filial travada; PDF exige `orders.report` | M | corrigido · `BranchScopeGapsTest` |
| T-09 | Flags de permissão abertas (escalada interna) | seg. | `Fiscal/Config`, `Fiscal/Notes`, `Branches/{Pauses,DeliverySettings,PrinterSettings,ServiceCharges,RestaurantTables,Form}`, `Pdv/Report`, `Users/Index`, `Roles/Index` | gerente grava config fiscal; pausa loja; fura limite de filiais; caixa vê vendas de todos | `#[Locked]` + recheck de permissão no método; notas fiscais filtradas por filial | P | corrigido · `PermissionFlagEscalationTest` |
| T-11 | Venda do PDV vinculada a cliente de outra empresa | integridade | `Pdv/Terminal`, `TabTerminal`, `OrderService::createOrder` | `set customerId=<cliente de B>` | recusar × pedido de A com cliente de B | `#[Locked]` + `createOrder` valida empresa do cliente | P | corrigido · `PdvCustomerCrossTenantTest`, `OrderServiceCustomerCompanyTest` |
| T-12 | Caixa vê fechamentos de outros operadores | seg. | `Pdv/Concerns/HasClosingReports` | caixa forja `viewingClosedSessionId` | recusar × vê esperado/contado de outro | `#[Locked]` + filtro por filial/operador | P | corrigido · `PdvClosingReportCaixaTest` |
| T-13 | Gerente vincula/desvincula produto de outras filiais; categoria/brinde de outra empresa; `isEditing`/`branchScoped` forjados | seg. | `Products/Form`, `Coupons`, `Branches/Form`, `Branches/Index` | — | só a própria filial; `Rule::exists` por empresa; flags travadas | P | corrigido · `CatalogBranchScopeHypothesesTest` (9 casos) |
| C4 | Pagamento aprovado para pedido cancelado reabre o pedido | bug/fin. | `ProcessVindiWebhook`, `ResolveExpired…`, `PaymentOrchestrator`, **`ProcessAsaasWebhook`** | cliente cancela e paga o PIX copiado → continua cancelado e estorna × vira `paid` | não reabre; registra pagamento, estorna e alerta; recusa cartão antes de cobrar | M | corrigido · `PaymentApprovedForCancelledOrderTest`, `Webhook/AsaasOrderPaymentWebhookTest` (+2) |
| C5 | Estoque devolvido em dobro ao cancelar comanda | bug | `StockService::restoreForOrder` | tirar item e cancelar → 10 × 12 | devolver só o saldo líquido (idempotente) | P | corrigido · `TabCancelDoubleStockRestoreTest` |
| C6 | Uso de cupom não liberado no cancelamento | bug | `CouponService`, `Coupon` | cupom 1 uso/cliente, trocar pagamento → aceita × "já utilizou" | `Coupon::countedUsages()` ignora pedidos cancelados/estornados (inclui a contagem do admin) | P | corrigido · `CouponBusinessRulesTest` |
| C7 | Cupom "Produto Grátis" desconta em dobro | bug/fin. | `OrderService`, `CouponService` | R$ 20 + brinde de R$ 15 → total 20 × 5 | brinde entra a zero sem desconto extra; preço do brinde filtrado pela empresa | P | corrigido · `CouponBusinessRulesTest` |
| C8 | Pedido cancelado volta para "Preparando"/"Pago" | bug | `Orders/Index` (kanban), `Orders/Show` | mudar status de pedido cancelado → recusa × muda | `Order::isClosed()` bloqueia | P | corrigido · `OrderStatusTransitionGuardTest` |
| C9 | Pedido mínimo e frete grátis com preço do navegador | bug | `HasPaymentFlow` | `cart.price=999` → frete 12 × 0 | subtotal recalculado no servidor | P/M | corrigido · `ChatDeliveryRulesUseClientCartPricesTest` |
| C10 | Pedido aceito com filial fechada/em pausa | bug | `HasPaymentFlow::placeOrder` | → recusa × cria pedido | revalida filial no `placeOrder`; `selectedBranchId`/`scheduledAt` `#[Locked]` | P | corrigido · `ChatPlaceOrderBranchRevalidationTest` |
| C11 | Fechamento do dia, PDF e relatório somam pedidos não pagos | bug/fin. | `Orders/Index::todayClosing`, `OrderClosingReportService`, `Orders/Report` | entregue 50 + pendente 80 + aguardando 30 → 50 × 160; split fora do detalhamento | `Order::CONFIRMED_STATUSES`; linhas "Aguardando pagamento", "Dividido", "Outros" | M | corrigido · `DailyClosingRevenueTest` |
| C12 | Venda em dinheiro cancelada no "esperado em caixa" | bug/fin. | `CashClosingReportService::breakdown` | → 0 × 8 | excluir cancelados | P | corrigido · `CashSessionExpectedAfterCancelTest` |
| C13 | Trocar item no pedido não ajusta estoque | bug | `Orders/Show::saveItems` | coxinha→kibe → coxinha 10/kibe 8 × 8/10 | delta por produto via `StockService` na transação | M | corrigido · `AdminItemSwapStockTest` |
| S4 | Onboarding sem limite e sem revalidar passos | seg. | `RegisterForm` | 8 empresas seguidas; e-mail inválido e senha "1" aceitos | valida passos 1–4; 3/h por IP; cartão 5/10 min; recusa genérica; PAN/CVV fora do estado Livewire | P | corrigido · `OnboardingRegisterFormTest`, `RegisterFormSubscriptionTest` |
| S5 | Link de redefinição de senha com host injetado | seg. | `bootstrap/app.php`, `AppServiceProvider` | `X-Forwarded-Host: atacante` → link no APP_URL × link no host do atacante | não confiar em `X-Forwarded-Host`; link montado com `APP_URL` | P | corrigido · `AuthSurfaceTest` + curl |
| S6 | Exclusão de qualquer arquivo do S3 | seg. | `Products/Form` (`image_path` do navegador) | trocar imagem com caminho da logo de outra empresa → nada × apagava | usar só o caminho gravado no banco | P | corrigido · `ProductFormArbitraryS3DeleteTest` |
| S7 | Empresa bloqueada continua vendendo pelo chat | bug | `OrderChat` | loja `BLOCKED` → indisponível × aceita pedidos | checar status no mount e no `placeOrder` | P | corrigido · `BlockedCompanyPublicChatTest` |
| S8 | Auto-cadastro Fortify aberto + assinatura QZ para qualquer logado | seg. | `config/fortify.php`, `QzTraySignatureController` | `/admin/register` cria conta sem empresa que assina com a chave da plataforma | registro desligado; assinatura exige empresa + permissão + throttle | P | corrigido · `AuthSurfaceTest`, `Auth/RegistrationTest` |
| UI-04 | Cupons: ações somem no celular | layout | `coupons/index.blade.php` | 375 px → Editar/Excluir cortados | rolagem horizontal da tabela | P | corrigido · navegador |
| UI-05 | Modal "Trocar item" sem rolagem | layout | `orders/show.blade.php` | produto com muitas opções a 375×667 | `max-h-full overflow-y-auto` | P | corrigido (não revalidado no navegador) |
| UI-06 | Notas fiscais: ações cortadas no celular | layout | `fiscal/notes.blade.php` | chave de 44 dígitos estica a tabela | rolagem + truncate | P | corrigido (não revalidado: módulo fiscal desligado nas empresas de teste) |
| UI-07 | Estoque: cabeçalho sobreposto no celular; "Filial #id" | layout | `stock/index.blade.php` | 375 px | rolagem horizontal + nome da filial | P | corrigido · navegador |
| UI-01 | Chat: botões finais (confirmar/copiar PIX) abaixo da dobra | UX | `order-chat.blade.php:214` (painel 60vh) | iPhone SE, PIX | rodapé de ações fixo; copia-e-cola antes do QR | M | identificado |
| UI-02 | Chat: campos < 16 px dão zoom no iOS | UX | `app.css:504` e inputs `text-sm` | tocar no telefone no iPhone | `font-size:16px` no mobile | P | identificado |
| UI-03 | "Recomeçar" apaga o pedido sem confirmar (até no PIX) | UX | `order-chat.blade.php:95-106` | tocar no ↻ na tela do PIX | usar o diálogo já existente (`confirmEnd`, hoje código morto) | P | identificado |
| F-10 | Dados de cartão do chat em props Livewire | melhoria/PCI | `OrderChat` (`cardNumber`, `cardCvv`) | PAN/CVV no snapshot e em cada request | tokenização no navegador (como já feito no onboarding) | M | identificado |

### P3 — Baixo

| ID | Título | Local | Correção | Status |
|---|---|---|---|---|
| F-07 | Filial de outra empresa aceita em usuários (só desconfigura o próprio usuário) | `Users/Index` | validar filial da empresa | corrigido |
| C14 | Venda em dinheiro cancelada gera "estorno falhou" | `OrderCancellationPolicy` (`'CASH'` × `'cash'`) | comparar sem caixa | corrigido · `PdvCashOrderCancelRefundNoiseTest` |
| C15 | Editar cupom não limpa campos opcionais | `Coupons/Index` | não filtrar nulos na edição (na criação mantém os padrões do banco, como antes) | corrigido · `CouponBusinessRulesTest` |
| C16 | `ProcessOrder` reabre pedido cancelado durante a cobrança | `ProcessOrder` | update condicional com lock | corrigido · `ProcessOrderReopensCancelledOrderTest` |
| C17 | Slugs que colidem com rotas fixas (`health`, `up`, `termos-de-uso`…) | `Rules/ReservedSlug` | ampliar a lista | corrigido · `ReservedSlugCoversFixedRoutesTest` |
| C18 | Aviso do limite Free a cada pedido | `OrderService` | 1 por mês, mesma regra da taxa | corrigido · `FreePlanLimitNotificationTest` |
| S9 | Esqueci a senha revela se o e-mail existe; sem limite | `FortifyServiceProvider` | resposta única + 6/min por IP | corrigido · `AuthSurfaceTest` + curl |
| S10 | Logo gravado com extensão do navegador (`.html`) | `CompanySettings` | `guessExtension()` | corrigido · `CompanyLogoExtensionTest` |
| H3/H8/H10 | Horizon por e-mail; telefone em log; sem HSTS | `HorizonServiceProvider`, chat, `SecurityHeaders` | exige super admin; só ids no log; HSTS em produção | corrigido |
| UI-09 | Rolagem horizontal no login (375 px) | `layouts/auth/simple.blade.php` | `w-100` → `w-full` | corrigido · navegador |
| UI-x1 | Histórico do chat mostra status cru `pending_payment` | `order-chat` | usar `status_label` | identificado |
| UI-08 | Mensagem de erro duplicada em ~120 campos (`flux:input` + `@error`) | várias views | remover `@error` manual | identificado |
| UI-10 | Tema do admin se perde ao visitar login/chat (`@fluxAppearance` ausente) | layouts | incluir a diretiva | identificado |
| UI-11/12/13/19 | Acessibilidade: labels sem `for`, botões só ícone sem nome, switches sem `role`, modais sem `role=dialog`/ESC | chat, pedidos, cupons, estoque | — | identificado (confirmado indiretamente: a ferramenta do navegador confundiu o switch de status do cupom com "Editar") |
| UI-14 | Ações destrutivas sem confirmação (desativar empresa no super admin com 1 clique; remover item de comanda) | super admin, PDV | confirmação | identificado |
| UI-15/16/17/18/20/21/23–28 | Miniatura que apaga imagem ao tocar; kanban só por arrastar; trava de toque duplo; feedback de cópia; toast cobre barra do entregador; contraste da cor da empresa; tabelas do super admin; autocomplete; foco no `/cadastro`; PDV entrega | várias | ver `findings` da interface | identificado |
| UI-x2 | Botão flutuante "Suporte" cobre conteúdo da lista no celular | layout admin | ocultar/encolher em listas | identificado |

### Hipóteses (sem prova; não contam nos números acima)
1. Dois terminais fechando a mesma comanda ao mesmo tempo (`closeTab` sem `lockForUpdate`) — não reproduzível em SQLite.
2. Raio de entrega aceita endereço fora quando a geocodificação falha (sem lat/lng).
3. Webhook Vindi com valor divergente mantém o split antigo.
4. "Taxa de plano" do super admin inclui pedidos sem split (dinheiro, PDV, iFood).
5. Desconto percentual do PDV vira fixo ao mudar o carrinho.
6. Canal `order.{id}` enumerável por id sequencial; `orders.{companyId}` entrega eventos de todas as filiais a papéis de filial (filtro só no componente).
7. `UserPolicy` não checa empresa (sem uso atual).
8. `IdentifyCompany` resolve por subdomínio antes da empresa do usuário.
9. Sessões não são invalidadas após troca/reset de senha.
10. Força bruta de código de cupom (sem limite em `applyCoupon`).
11. Segundo pagamento aprovado em pedido já pago credita de novo sem estorno.
12. Pedido de cartão `pending` abandonado (sem webhook) nunca é cancelado e segura estoque.
13. `Branch::isOpen()` não trata janela noturna (contornado só no chat).
14. Nota fiscal automática não sai para pedido que a loja já avançou (ex.: em preparo) e é pago depois pelo gateway, porque o status não volta para `paid` e `IssueFiscalNoteOnPaid` não dispara.
15. Webhook Asaas de pedido fechado ainda dispara `OrderStatusUpdated` (inofensivo; WhatsApp é idempotente).
16. Sem `X-Forwarded-Host` confiável, `url()` e a empresa por subdomínio dependem de o proxy repassar o `Host` original — conferir em produção.

Verificado e descartado: estorno dentro de `DB::transaction` — `refundPaymentForClosedOrder` só cria o registro e despacha `ProcessRefund` com `afterCommit` (sem HTTP na transação).

---

## 4. Correções realizadas e arquivos afetados

88 arquivos alterados + 5 novos/pastas (≈ +1.900/−460 linhas). Principais:

- **Infra de autorização:** `AppServiceProvider` (middlewares persistentes do Livewire; link de reset pelo `APP_URL`), `app/Livewire/SuperAdmin/Concerns/RequiresSuperAdmin.php` (novo) em todos os componentes de super admin, `User::canAccessBranch()`/`lockedBranchId()`, `OrderPolicy`, `Order::CONFIRMED_STATUSES`/`isClosed()`.
- **Admin (Livewire):** `Users/{Index,Permissions}`, `Roles/Index`, `Orders/{Index,Show,Report}`, `Stock/Index`, `Fiscal/{Config,Notes}`, `Products/{Form,Index}`, `Categories/Index`, `Coupons/Index`, `Branches/*` (7), `Pdv/{Terminal,TabTerminal,Report}` + `Pdv/Concerns/*`.
- **Pedido/pagamento:** `OrderService` (`cancelBySystem`, `refundPaymentForClosedOrder`, cliente da empresa, brinde, aviso Free), `StockService`, `CouponService`, `Coupon`, `OrderCancellationPolicy`, `OrderClosingReportService`, `CashClosingReportService`, `PaymentOrchestrator`, jobs `ProcessOrder`, `ProcessVindiWebhook`, `ProcessAsaasWebhook`, `ResolveExpiredVindiPaymentsJob`, `EscPosPrinterService`.
- **Chat/onboarding/auth:** `Chat/OrderChat` + concerns, `CustomerService`, `Onboarding/RegisterForm` (+ view), `DescriptionSanitizer`, `FortifyServiceProvider`, `config/fortify.php`, `bootstrap/app.php`, `QzTraySignatureController`, `SecurityHeaders`, `HorizonServiceProvider`, `CompanySettings`, `ReservedSlug`, simuladores de pagamento.
- **Views:** cupons, estoque, notas fiscais, detalhe do pedido, recibo de fechamento, login, cadastro.
- **Revisão de qualidade do diff integrado (agente de qualidade):** nenhuma prop nova com `#[Locked]` é escrita pela view/JS; `php -l` e boot ok. Encontrou 4 regressões introduzidas pelas correções, todas corrigidas e cobertas por `tests/Feature/Audit/Qa/QaRegressionTest.php`:
  1. botão "Editar endereço" visível para estações mas bloqueado no servidor → endereço voltou a ser editável pelas estações;
  2. troca **parcial** de item (1 de 2) movia o estoque da quantidade inteira (bug antigo de `applySwapItem` exposto pelo C13) → linha original mantém o produto; o teste do C13 passou a declarar a troca total;
  3. chat salvo como "Indisponível" ficava preso depois de a loja voltar a ativa → reavaliado ao reabrir;
  4. opção importada de produto e desvinculada perdia a foto → aceita foto de produto da própria empresa.
  Também aplicados: lock do pedido nos caminhos "pago" do webhook Vindi e da expiração (corrida com `cancelBySystem`), limite de telefone ampliado e padrão do banco preservado na criação de cupom.
- **Testes:** `phpunit.xml` (Discord); testes existentes ajustados só onde a prop virou `#[Locked]` (`->set()` → `->call()` do método) ou o comportamento mudou de propósito (`Auth/RegistrationTest`, `OrdersReportPdfTest`, `RegisterFormSubscriptionTest`).

---

## 5. Testes executados e resultados

| Execução | Resultado |
|---|---|
| Baseline (antes) | 1.823 testes; 9 falhas ambientais (views compiladas do container Sail em `storage/framework/views`), 0 após `view:clear` |
| Testes de auditoria `tests/Feature/Audit` (Tenant, Flows, Surface, Qa) | antes das correções: **77 falhando** (24 + 32 + 21); depois: **104 passam, 2 pulados** (OTP) |
| Suíte completa final (`php -d memory_limit=2G vendor/bin/pest`, SQLite) | **1.956 passaram, 2 pulados, 0 falhas** (5.853 asserções) |
| `pint --test` (repositório inteiro) | pass |
| `npm run build` | ok |
| Matriz de acesso (curl, 7 usuários × 32 rotas) | todas as rotas do admin 200 para o admin; 403/404 esperados para os demais papéis e entre empresas; `/admin/register` 404 |
| Navegador após correções | chat completo + cancelamento (estoque devolvido), adulteração de `selectedBranchId` recusada, ajuste de estoque, edição de produto/cupom/filial, mudança de status com histórico, super admin, esqueci a senha |
| Varredura responsiva após correções (360 px, 20 telas do admin, login, cadastro e chat) | sem overflow horizontal e sem erro de Livewire |

Observação: o runner paralelo (`--parallel`) estoura o `memory_limit` de 128 MB nos workers; use a execução sequencial com `-d memory_limit=2G` ou ajuste o `memory_limit` do PHP CLI.

---

## 6. Limitações e bloqueios

- **Sem integrações reais:** PIX/cartão (Vindi), Asaas, WhatsApp, iFood, Focus NFe e e-mail foram exercitados apenas por testes com `Http::fake`/mocks. Webhooks não foram recebidos de verdade.
- **Uploads:** o código grava direto no S3; sem S3 local, upload de logo/imagens não foi testado no navegador (coberto por testes e revisão).
- **Tempo real e filas:** Reverb e workers não rodaram; eventos ficaram em log/fila parada.
- **PDV e impressão:** cobertos por testes (782 nas suítes relacionadas), não percorridos no navegador; QZ Tray não testado.
- **Dispositivos reais:** responsividade validada em iframe no Chrome desktop (não em iOS/Android; o zoom do iOS do UI-02 não foi reproduzido).
- **Produção não foi acessada.** Itens que dependem dela estão em "Próximas ações".
- **Concorrência real** (dois terminais, webhooks simultâneos) não reproduzível em SQLite.
- Execuções da suíte antes da correção F-03 (inclusive a baseline desta auditoria) podem ter enviado logs de erro de teste ao Discord configurado no `.env`.

---

## 7. Melhorias propostas

| Melhoria | Benefício | Esforço |
|---|---|---|
| OTP por WhatsApp/SMS no chat antes de carregar perfil, histórico, cartões e permitir editar/cancelar | fecha o S1 (LGPD) e o opt-in forjado | M/G |
| Tokenização de cartão no navegador no chat (como no onboarding) | tira PAN/CVV do servidor e reduz escopo PCI | M |
| Regra de lint/CI: toda prop pública com nome `is*`, `can*`, `*Id`, `company_id` em `app/Livewire` exige `#[Locked]` ou comentário justificando | evita a classe de falha mais recorrente desta auditoria | P |
| Seletor de empresa para usuários com mais de uma empresa (hoje cai na de menor id) | permite multiempresa legítima sem o risco do F-06 | M |
| Job de limpeza de pedidos de cartão `pending` abandonados (usando `cancelBySystem`) | devolve estoque e tira lixo dos relatórios | P |
| `MustVerifyEmail` para usuários do painel | o middleware `verified` passa a ter efeito | P/M |
| `trustHosts` + `TRUSTED_PROXIES` com as faixas do Laravel Cloud | defesa contra host header e IP correto para os limites | P |
| Enfileirar o e-mail de reset de senha | remove diferença de tempo de resposta (enumeração) | P |
| Acessibilidade do chat e do admin (UI-08, 11–13, 19) e UX do checkout no celular (UI-01–03) | conversão no celular e uso por teclado/leitor de tela | M |
| Atualizar `league/commonmark` (2 alertas, não alcançáveis hoje) e mover ferramentas de build para `devDependencies` | higiene de dependências | P |
| Incluir `cancelBySystem`/`refundPaymentForClosedOrder` em `OrderServiceInterface` | contrato coerente com o resto do projeto | P |

---

## 8. Próximas ações (em ordem)

1. **Revisar e commitar** as correções (sugestão: separar por lote — autorização/Locked; ciclo do pedido; superfície/auth; layout). Os testes de `tests/Feature/Audit` podem ficar como regressão.
2. **Antes do deploy:** conferir nos logs de produção se `request()->ip()` varia por cliente (senão configurar `TRUSTED_PROXIES`); confirmar que a conta usada no Horizon tem `is_super_admin`; conferir após o deploy que URLs absolutas e redirecionamentos mantêm o host certo.
3. **Decidir e implementar OTP no chat** (S1) — é o maior risco aberto.
4. Comunicar o desligamento de `/admin/register` (o cadastro oficial segue em `/cadastro`).
5. Aplicar as propostas de UX do checkout no celular (UI-01, UI-02, UI-03).
6. Implementar a limpeza de pedidos de cartão abandonados e investigar as hipóteses 1, 3, 11 e 14 (concorrência, dinheiro e nota fiscal). Cobrir com teste a proteção de `updateTabItemQuantity` para comanda fechada depois de selecionada (o ajuste dos testes do PDV deixou esse caso sem cobertura direta).
7. Tratar acessibilidade (UI-08, 11–13, 19) e os demais P3.
8. Avaliar a regra de lint para `#[Locked]` e o seletor de empresa.
