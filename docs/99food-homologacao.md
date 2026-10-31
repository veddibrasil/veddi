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
| Cardápio | `Food99CatalogSyncService`, `SyncFood99CatalogJob` (upload completo com debounce), `CheckFood99CatalogTaskJob` (resultado do upload), `SetFood99ItemStatusJob` (disponibilidade) |
| Loja | `Food99StoreStatusSync` (abrir/pausar/fechar/modo ocupado e pausas da filial) |
| Horários | `Food99StoreHours` (lê `biz_day_time` de `shop/detail` e grava por `shop/update`) |
| Monitoramento | `Food99ConnectionMonitor` + `food99:check-connections`, `RefreshFood99TokensJob`, `ReconcileFood99OrdersJob` (dispara um `ReconcileFood99IntegrationJob` por loja), `ExpireFood99RequestsJob` (agendados em `routes/console.php`) |
| Telas | `/admin/settings/99food` (`Food99IntegrationSettings` + `Concerns/ManagesFood99Store`, só `company_admin`; abas Loja, Pausas, Horários, Cardápio, Pedidos e Diagnóstico), card em Integrações, partials `food99-*` em pedidos, opção na pausa da filial e no cadastro do produto |
| Super admin | `/superadmin/integracoes` (`SuperAdmin/Integrations/Connections`): lojas iFood e 99Food de todas as empresas, e lojas que autorizaram o app sem filial |
| Testes | `tests/Feature/Food99/*`, fixtures em `tests/Fixtures/food99/` |
| Log | canal `food99` (operação, errno, `requestId`, duração; nunca token, app_secret ou dados do cliente) |

## Variáveis de ambiente

```
FOOD99_ENABLED=false
FOOD99_COMPANY_IDS=                 # empresas que veem a tela e podem conectar; vazio = todas com o módulo de integrações
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

Portal: `https://developer-food.99app.com`.

1. Aguardar a aprovação das credenciais da empresa em Gerenciamento de qualificações (até 3 dias úteis). Sem ela o portal não deixa criar o app, e sem app não existem `app_id` e `app_secret`.
2. Criar o aplicativo em Gerenciamento de aplicativo e cadastrar a URL de callback: `https://<domínio>/webhooks/99food`.
3. Copiar `app_id` e `app_secret` para o ambiente e ligar `FOOD99_ENABLED=true` só no ambiente de homologação. Para abrir com uma empresa só, preencher `FOOD99_COMPANY_IDS`: a lista decide quem vê a tela e conecta; loja já ligada continua operando se a empresa sair dela.
4. Criar a loja de teste e a conta de cliente de teste (C-App) no portal.

## Como a loja conecta

Cada filial conecta a própria loja, em qualquer quantidade de empresas: o app parceiro é um só e cada `Food99Integration` guarda a loja e o token dela.

1. Admin da empresa abre Integrações › 99Food, escolhe a filial e clica em **Conectar 99Food**.
2. O VEDDI gera o link de autorização self-service (vale 7 dias e é o mesmo pra todas as lojas). O superadmin da loja na 99 abre, escolhe a loja e autoriza o app VEDDI. Nesse vínculo quem gera o `app_shop_id` é a 99.
3. O admin informa o **ID da loja** (aparece no app 99Food Loja/portal). O VEDDI confere que ela está entre as lojas autorizadas e que não está ligada a outra filial/empresa, e mostra o nome da loja para o admin confirmar.
4. Confirmado, o VEDDI vincula (se ainda não estiver) e ativa: pega o token, define quem confirma pedidos, liga as solicitações de cancelamento/reembolso e lê nome, status e horários da loja.

Se a 99 limitar a ativação, a loja fica em **Ativação pendente** e o admin conclui pelo botão. Reconectar depois de a 99 desfazer o vínculo segue os mesmos passos, com a mesma loja.

A lista de lojas autorizadas é do app inteiro (todas as empresas da plataforma). Por isso ela não aparece pro restaurante: só em `FOOD99_HOMOLOGATION_MODE` ou pro super admin. O link é único do app, então nada liga uma autorização a uma filial: o ID informado e a confirmação do nome são a defesa contra conectar a loja errada, e toda ligação fica no log `food99` com o usuário.

A busca da loja para na página em que ela aparece e guarda cada página por 1 minuto: quem acabou de autorizar pode precisar aguardar esse minuto. Sem acesso ao `getAuthorizedShops`, a busca usa `shop/list` (1 req/20 s) e continua de onde parou a cada nova tentativa.

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
| 8 | Cancelamento parcial | Pelo app 99: aviso no sino e linha no resumo. Pela API: `Food99OrderActionService::partialCancel` (mdu_id), ainda sem botão no painel |
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

## Validado na API real (30/10/2026, loja de teste)

Autorização e assinatura, conexão e ativação, `setStatus` (fechar e modo ocupado), `shop/update` (semana inteira de horários), upload e leitura do cardápio V3 com complementos, `updateItemStatus`, pedido entrega própria completo (aceite, pronto, despacho, `verifyDeliveryCode`, `orderFinish`), recusa com motivo, pedido entrega 99 até pronto. `getAuthorizedShops` está liberado, `getUrl` aceita a assinatura e o total local bate com `real_price` na entrega própria.

Em 31/10/2026 (pedidos de sandbox): renovação com token vencido, resultado do upload por `CheckFood99CatalogTaskJob`, entrega própria em dinheiro de ponta a ponta com callbacks reais (total local igual a `real_price`, pagamento quitado no `orderFinish`), importação da entrega 99 em dinheiro com total igual a `order_price + service_price`, aceite, `partialCancel` com item esgotado e cancelamento pela loja (921). Nesse pedido de entrega 99 os callbacks foram reenviados localmente com a assinatura do app. `payConfirm` respondeu `12016` porque a loja de teste não recebe dinheiro do entregador: o formato da chamada foi aceito, o fluxo não foi concluído.

O que a 99 faz e a documentação não conta:

- O token dura cerca de 15 min, e o emitido ao criar a loja pode já vir vencido: o `get` responde `10102` até alguém pedir o `refresh`. O VEDDI sempre pede `refresh` antes do `get`.
- Editar a loja no portal dispara `shopBindStatus` e gira o token: chamada com o token antigo volta `10100`. `Food99GatewayService::call()` renova e repete.
- `auto_switch` vem como 3 na opção "abrir e fechar automaticamente", fora do enum da doc.
- `shopBusyMode` e `orderDispatch` chegam sem estar listados. O primeiro atualiza o modo ocupado; o segundo não tem efeito.
- Em `order_info`, `locator`, `virtual_phone_number` e `handover_page_url` vêm dentro de `receive_address`.
- Em `partialCancel`, `items` é string JSON e `app_item_ids` é string separada por vírgula; array volta `10002`.
- `refund_price` e `in_sale_refund_to_c_fee` vêm dentro de `price`. No parcial em dinheiro, o valor do item cai em `in_sale_refund_to_c_fee` e `refund_price` fica 0.
- `setStatus` tem limite real de 1 chamada por minuto, igual ao limitador local.

## Itens a validar com a 99Food

Cada um está marcado no código com `[VALIDAR 99Food]`.

1. Assinatura do `uploadMenuTaskStatus`: não bate com `md5(corpo + app_secret)`, então o callback é recusado com 401. O resultado do upload vem de `CheckFood99CatalogTaskJob`, agendado a cada minuto; sem resultado em 30 min o envio vira falha com aviso à loja. Abrir chamado com a 99 antes de decidir aceitar esse callback sem assinatura.
2. Reabrir loja depois de fechar: `setStatus` responde `11040` ("is menu upload?") com `store_status` 1 e `10001` ("sem itens disponíveis para venda") com 4, com ou sem `auto_switch`, mesmo com upload recente com sucesso, cardápio lido na API e itens com `status` 1. `sold_info_intl` e foto não mudam o resultado. `shop/deliveryArea/*` responde `10050` (sem permissão) para o app. Em entrega da loja o `11040` passa a citar também "is delivery data set?", então a reabertura depende da área de entrega e da liberação desse endpoint. Enquanto não houver resposta, não fechar loja real pelo painel.
3. Fórmula do total na entrega 99: sem `real_price` e com `store_charged_delivery_price`, que hoje não entra no valor líquido. `Food99OrderDTO`; divergências vão pro log e pro `reported_total`.
4. "Entregue" sem código na entrega própria: `selfdelivery/delivered` e `order/delivered` levam a 99 só até o status 500 (callback `orderDelivered`, sem efeito no VEDDI). O VEDDI chama `order/finish` em seguida, que levou de 500 a 600 na loja de teste. Falta a 99 confirmar que isso vale em produção; com código, a conclusão vem de `verifyDeliveryCode`.
5. `order_confirm_method = 2` (só API) como padrão.
6. Resposta para callback de loja desconhecida (hoje `errno 0`, pra não gerar reenvio sem fim).
7. Pausas longas/futuras com fechar (2) / revogar fechamento (4) e o `auto_switch`.
8. Tratamento de `orderPartialCancel` nos itens do pedido local (hoje os itens não são reescritos; o reembolso fica no resumo).
9. Itens vendidos por peso (`sales_type`): hoje entram como item avulso com o peso no nome.
10. Se as fotos do storage do VEDDI são baixáveis pelos servidores da 99 (sem 403).
11. Padrão de `available_in_99food` (hoje `true`, como o iFood; a publicação do cardápio é opt-in).
12. Formato `agree` (booleano) nas respostas de solicitação de cancelamento e reembolso.
13. Se os limites de token (1 req/30 s) e de loja valem por loja, como o VEDDI assume, ou pelo app inteiro, como a documentação sugere. Pelo app inteiro, muitas lojas renovando juntas recebem `10005`: vira nova tentativa, não erro, mas pode pedir um espaçamento global em `Food99Client::LIMITS`.
14. Se `biz_day_time` parcial mantém os dias omitidos. A tela sempre envia a semana inteira e trata dia sem turno como fechado. `Food99StoreHours::save()`.
15. Se a resposta de `shopBind` vem no envelope `errno`/`data` como os demais endpoints. `Food99Client::unwrap()` exige o envelope.

Sem teste real ainda: entregador da 99 (o sandbox não atribui), dinheiro com `shop_paid_money`/`payConfirm` até o fim, retirada, pagamento online e solicitações do cliente. Esses cenários dependem do app do cliente (C-App).

## Pendências conhecidas

- Conciliação financeira (`getShopBillDetail`/`getShopBillWeek`) não implementada: fica pra depois da homologação, como no iFood.
- Venda por peso e complemento aninhado sempre entram como item avulso (sem baixa de estoque).
- `sold_info_intl` (janela de venda por item) não é enviado: o cadastro do VEDDI não tem horário por produto.

## Operação

- `php artisan food99:check-connections` — monitor (agendado a cada 10 min).
- Agendamentos em `routes/console.php`: tokens (10 min), reconciliação de pedidos (3 min, também busca `shop_paid_money` atrasado), solicitações vencidas e pausas da filial (1 min), resultado do upload de cardápio (1 min, um job por loja), cardápio completo (04:30).
- Evento com falha: aba Diagnóstico › Reprocessar.
- Lojas de todas as empresas: `/superadmin/integracoes`.
