# 99Food — integração e homologação

Integração de pedidos da 99Food pelo **99Food Protocol**, no mesmo modelo do iFood: um app parceiro VEDDI para toda a plataforma (hub único) e uma `Food99Integration` por filial conectada.

Tudo fica atrás de `FOOD99_ENABLED` (padrão `false`): com a flag desligada não aparece card, tela, filtro nem roda agendamento, e o webhook só aceita callbacks de integrações já ativas (nenhuma, na prática).

> A documentação técnica da 99Food (portal do parceiro) é coberta por NDA. O plano de execução (`docs/plano-integracao99.md`) e o YAML oficial (`docs/99food/`) ficam fora do versionamento (`.gitignore`) porque o repositório é público. Este arquivo descreve só o que o VEDDI implementa.

## Onde está cada coisa

| Parte | Onde |
|---|---|
| Configuração | `config/food99.php`, variáveis `FOOD99_*` no `.env.example` |
| Canal | `OrderChannel::Food99` (`99food`); marketplace genérico em `Order::marketplaceDetails()` / `App\Support\Marketplace\MarketplaceOrderDetails` |
| Models | `Food99Integration` (uma por filial, token criptografado), `Food99WebhookEvent` (callbacks, `dedupe_key` única), `Food99Request` (pedidos de cancelamento/reembolso do cliente) |
| Transporte e auth | `Services/Food99/Food99Client` (envelope `errno`, limites por endpoint, log com `requestId`), `Food99Signer` (sign MD5 e `didi-header-sign`), `Food99AuthService` (token, autorização, escolha da loja, ativação, desconexão), `Food99GatewayService` (atrás de `Food99GatewayContract`) |
| Webhook | `POST /webhooks/99food` → `Food99WebhookController` → `ProcessFood99EventJob` (fila `critical`) → tratadores (`Food99OrderImporter`, `Food99OrderEventProcessor`, `Food99RequestService`, `Food99ShopEventHandler`, `Food99CatalogSyncService`) |
| Pedido | `DTOs/Food99OrderDTO`, `Food99OrderMapper`, `Support/Food99/Food99OrderDetails`, `Food99OrderStatusSync`, `Food99OrderActionService`, `PropagateFood99OrderStatus` + `PropagateFood99StatusJob`, `RefreshFood99OrderDetailsJob` |
| Cardápio | `Food99CatalogSyncService`, `SyncFood99CatalogJob` (upload completo com debounce), `SetFood99ItemStatusJob` (disponibilidade) |
| Loja | `Food99StoreStatusSync` (abrir/pausar/fechar/modo ocupado e pausas da filial) |
| Monitoramento | `Food99ConnectionMonitor` + `food99:check-connections`, `RefreshFood99TokensJob`, `ReconcileFood99OrdersJob`, `ExpireFood99RequestsJob` (agendados em `routes/console.php`) |
| Telas | `/admin/settings/99food` (`Food99IntegrationSettings`, só `company_admin`), card em Integrações, partials `food99-*` em pedidos, opção na pausa da filial e no cadastro do produto |
| Testes | `tests/Feature/Food99/*`, fixtures em `tests/Fixtures/food99/` |
| Log | canal `food99` (operação, errno, `requestId`, duração; nunca token, app_secret ou dados do cliente) |

## Variáveis de ambiente

```
FOOD99_ENABLED=false
FOOD99_APP_ID=                      # app parceiro VEDDI no portal da 99Food
FOOD99_APP_SECRET=
FOOD99_API_BASE_URL=https://openapi.99food.com
FOOD99_TIMEZONE=America/Sao_Paulo
FOOD99_ORDER_CONFIRM_METHOD=2       # 1 = app 99Food Loja e API; 2 = só API
FOOD99_TOKEN_REFRESH_MARGIN=300
FOOD99_HOMOLOGATION_MODE=false      # mostra a lista de lojas autorizadas na tela da integração
```

Workers: os callbacks vão pra fila `critical`; atualizações de detalhe do pedido pra `default`. Os mesmos workers do iFood atendem.

## Configuração no portal da 99Food

1. No app parceiro (Application Management), cadastrar a URL de callback: `https://<domínio>/webhooks/99food`.
2. Copiar `app_id` e `app_secret` para o ambiente e ligar `FOOD99_ENABLED=true` só no ambiente de homologação.
3. Criar a loja de teste e a conta de cliente de teste (C-App) no portal.

## Como a loja conecta

1. Admin da empresa abre Integrações › 99Food, escolhe a filial e clica em **Conectar 99Food**.
2. O VEDDI gera o link de autorização self-service (vale 7 dias). O superadmin da loja na 99 abre, escolhe a loja e autoriza o app VEDDI.
3. O admin informa o **ID da loja** (aparece no app 99Food Loja/portal). O VEDDI confere que ela está entre as lojas autorizadas e que não está ligada a outra filial/empresa, vincula (se ainda não estiver) e ativa: pega o token, define quem confirma pedidos, liga as solicitações de cancelamento/reembolso e lê nome/status da loja.

A lista de lojas autorizadas é do app inteiro (todas as empresas da plataforma). Por isso ela não aparece pro restaurante: só em `FOOD99_HOMOLOGATION_MODE` ou pro super admin.

## Roteiro na loja de teste

Antes: filial com cardápio no VEDDI (produtos marcados "Disponível na 99Food"), integração conectada e cardápio **publicado** (aba Cardápio). Acompanhar a aba Diagnóstico (eventos e `requestId`) e o log `food99`.

| # | Cenário | Esperado no VEDDI |
|---|---|---|
| 1 | Pedido online, entrega 99: aceitar → pronto → acompanhar entregador → concluir | Kanban: pendente (contagem de 5 min) → preparando → pronto → a caminho (coleta, `deliveryStatus 140`) → entregue (`orderFinish`). Cupom com `99Food #<order_index>` e `ID 99: <order_id>` |
| 2 | Pedido em dinheiro, entrega 99 | Pagamento pendente; após atribuir entregador o valor vira `shop_paid_money`; botão "Recebi o dinheiro do entregador" chama `payConfirm` e quita |
| 3 | Pedido entrega própria: despachar → entregar com código | "Saiu para entrega" (`selfdelivery/dispatch`), "Entregue" com o código do cliente (`verifyDeliveryCode`) |
| 4 | Pedido para retirada | "Cliente retirou" (pronto + `order/finish`), conferindo o código de retirada se informado |
| 5 | Recusa com cada grupo de motivo | Modal com os motivos da 99 (de entrega só na entrega própria; "Outro" exige texto); pedido cancelado na hora |
| 6 | Cancelamento pelo cliente antes/depois do aceite | `orderCancel` → pedido cancelado com o motivo 9xx do detalhe e estoque devolvido |
| 7 | Pedido com promoção da loja | Promoção e parte bancada pela loja no resumo do pedido e no cupom |
| 8 | Cancelamento parcial | Pelo app 99: aviso no sino e linha no resumo. Pela API: `partialCancel` (mdu_id) |
| 9 | Solicitação de cancelamento e de reembolso (aceitar e recusar) | Bloco no pedido com prazo; reembolso só recusa com motivo da lista da 99 |
| 10 | Upload de cardápio com item inválido proposital | Envio "aceito com pendências"; erro no item (aba Cardápio e cadastro do produto) |
| 11 | Pausa/fechamento/reabertura da loja | Aba Loja e pausas da filial; status confirmado pelo `shopStatus` |
| 12 | Aceite fora do prazo | Pedido sai do alerta de aceite; reconciliação aplica o cancelamento 922 |

## Checklist de go-live da 99Food

| Exigência | Implementação |
|---|---|
| Obter e renovar o token automaticamente ao expirar | `Food99AuthService` (margem + `10102` renova e repete) e `RefreshFood99TokensJob` |
| Receber `orderNew` e responder `errno 0` a todos os callbacks | `Food99WebhookController` (inclusive reentrega e tipo desconhecido) |
| Ids de 64 bits sem perda | `JSON_BIGINT_AS_STRING`, colunas string, ids enviados como inteiro JSON |
| `order_id` e `order_index` na tela e no cupom | `food99-summary`, `Food99OrderDetails::receiptLines()` |
| `shop_paid_money` e `payConfirm` | `RefreshFood99OrderDetailsJob`, `Food99OrderActionService::confirmCashPayment()` |
| Duas estruturas de preço/endereço | `Food99OrderDTO` (entrega 99 × entrega própria, endereço mascarado) |
| Promoções e estrutura completa de preços | `promotions`, `raw_prices` e `reported_*` no snapshot |
| Confirmar pela API em até 5 min | Kanban, alerta no topo dos pedidos e tela do pedido |
| Recusar/cancelar pela API | Modal de motivos |
| Guardar `requestId` | Log `food99`, `food99_webhook_events.request_id`, `food99_integrations.last_request_id` |

## Itens a validar com a 99Food

Cada um está marcado no código com `[VALIDAR 99Food]`.

1. Como o `app_shop_id` é definido no vínculo self-service (o nosso `veddi-{empresa}-{filial}` × id gerado pela 99) e se `getAuthorizedShops` está liberado para o app VEDDI (sem ele, cai em `shop/list`). `Food99AuthService::selectShop()`.
2. Fórmula do total local × campos de preço nos dois modelos (comparar com o valor que a loja vê no app). `Food99OrderDTO`; divergências vão pro log e pro `reported_total`.
3. Rota de "entregue" na entrega própria (`selfdelivery/delivered`, com fallback `order/delivered`) e campos obrigatórios de `courier_info`.
4. `order_confirm_method = 2` (só API) como padrão.
5. Resposta para callback de loja desconhecida (hoje `errno 0`, pra não gerar reenvio sem fim).
6. Pausas longas/futuras com fechar (2) / revogar fechamento (4) e o `auto_switch`.
7. Tratamento de `orderPartialCancel` nos itens do pedido local (hoje os itens não são reescritos; o reembolso fica no resumo).
8. Itens vendidos por peso (`sales_type`): hoje entram como item avulso com o peso no nome.
9. Se as fotos do storage do VEDDI são baixáveis pelos servidores da 99 (sem 403).
10. Padrão de `available_in_99food` (hoje `true`, como o iFood; a publicação do cardápio é opt-in).
11. Formatos não confirmados: `agree` (booleano) nas respostas de solicitação, `receive_*_apply` (1/2), parâmetro da task (`taskID`), `busy_mode`, significado dos `pause_reason_code` 1001–1006 e se `getUrl` aceita a assinatura.

## Pendências conhecidas

- Conciliação financeira (`getShopBillDetail`/`getShopBillWeek`) não implementada: fica pra depois da homologação, como no iFood.
- Venda por peso e complemento aninhado sempre entram como item avulso (sem baixa de estoque).
- `sold_info_intl` (janela de venda por item) não é enviado: o cadastro do VEDDI não tem horário por produto.

## Operação

- `php artisan food99:check-connections` — monitor (agendado a cada 10 min).
- Agendamentos em `routes/console.php`: tokens (10 min), reconciliação de pedidos (3 min), solicitações vencidas e pausas da filial (1 min), task de cardápio sem callback (15 min), cardápio completo (04:30).
- Evento com falha: aba Diagnóstico › Reprocessar.
