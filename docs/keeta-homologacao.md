# Keeta — integração e homologação

Integração de pedidos da Keeta pela **Keeta Open Delivery API** (Open Delivery da Abrasel com ajustes da Keeta, v1.0.7+), no mesmo molde da 99Food: um app parceiro VEDDI para toda a plataforma (hub único) e uma `KeetaIntegration` por filial conectada.

Tudo fica atrás de `KEETA_ENABLED` (padrão `false`): com a flag desligada não aparece card, tela, filtro nem roda agendamento, e o webhook só aceita eventos de integrações já ativas (nenhuma, na prática).

Referência pública da API: `https://api-docs.mykeeta.com/apis/opendelivery` (OpenAPI em `https://api-docs.mykeeta.com/_bundle/apis/@opendelivery/opendelivery.json?download`). O que estiver marcado `[VALIDAR Keeta]` no código depende da SIT com a Keeta.

## Onde está cada coisa

| Parte | Onde |
|---|---|
| Configuração | `config/keeta.php`, variáveis `KEETA_*` no `.env.example`, `Company::canUseKeetaIntegration()` |
| Canal | `OrderChannel::Keeta` (`keeta`); marketplace genérico em `Order::marketplaceDetails()` / `MarketplaceOrderDetails` |
| Models | `KeetaIntegration` (uma por filial; `merchant_ref` = nosso merchantId, `menu_api_key` criptografada), `KeetaWebhookEvent` (eventos de pedido e de autorização, `event_id` único), `KeetaRequest` (cancelamento/reembolso pedido pelo cliente) |
| Transporte e auth | `Services/Keeta/KeetaSigner` (HMAC-SHA256 + JCS RFC 8785), `KeetaClient` (token do app em cache criptografado, assinatura em toda chamada, 401 renova e repete, log), `KeetaGatewayService` (atrás de `KeetaGatewayContract`), `KeetaAuthService` (link de autorização, retorno, escolha da loja, onboarding, desconexão) |
| Webhooks | `POST /webhooks/keeta/orders` → `KeetaWebhookController`; `POST /webhooks/keeta/authorization` (1301/1302) → `KeetaAuthorizationWebhookController`; ambos → `ProcessKeetaEventJob` (fila `critical`) → `KeetaOrderImporter`, `KeetaOrderEventProcessor`, `KeetaRequestService`, `KeetaAuthorizationHandler` |
| Polling | `KeetaOrderPollingService` + `PollKeetaEventsJob` (a cada 30 s, só lojas sem webhook saudável), `MonitorKeetaWebhookHealthJob` |
| Pedido | `DTOs/KeetaOrderDTO`, `KeetaOrderMapper`, `Support/Keeta/KeetaOrderDetails`, `KeetaOrderStatusSync`, `KeetaOrderActionService`, `Enums/KeetaCancelReason`, `PropagateKeetaOrderStatus` + `PropagateKeetaStatusJob` |
| Cardápio | `GET /integrations/keeta/merchant/{merchantRef}` (`KeetaMerchantController`, `X-API-KEY`), `KeetaCatalogBuilder` (Merchant do Open Delivery), `KeetaCatalogSyncService` + `SyncKeetaCatalogJob` (aviso `merchantUpdate` com debounce), `KeetaStoreHours` (horários em UTC) |
| Loja | `KeetaStoreStatusSync` (abrir/fechar com `merchantStatus`, pausas da filial com `keeta_sync`) |
| Monitoramento | `KeetaConnectionMonitor` + `keeta:check-connections` (sino e e-mail `KeetaConnectionAlert`) |
| Telas | `/admin/settings/keeta` (`KeetaIntegrationSettings`, só `company_admin`; abas Loja, Cardápio, Diagnóstico) e retorno `/admin/settings/keeta/callback`; card em Integrações; partials `keeta-*` em pedidos (`HandlesKeetaOrders`, `HandlesKeetaRequests`); "Disponível na Keeta" no produto; "Fechar também a loja na Keeta" na pausa da filial |
| Super admin | `/superadmin/integracoes`: lojas Keeta de todas as empresas e lojas que autorizaram (1301) sem filial |
| Testes | `tests/Feature/Keeta/*`, fixture em `tests/Fixtures/keeta/` |
| Log | canal `keeta` (operação, HTTP, duração, ids; nunca token, client_secret, corpo ou dados do cliente) |

## Variáveis de ambiente

```
KEETA_ENABLED=false
KEETA_COMPANY_IDS=                  # empresas que veem a tela e podem conectar; vazio = todas com o módulo de integrações
KEETA_CLIENT_ID=                    # app VEDDI no Dev Portal da Keeta
KEETA_CLIENT_SECRET=                # também é a chave das assinaturas X-App-Signature
KEETA_APP_ID=Keeta                  # X-App-Id dos webhooks de pedido (a Keeta manda o literal "Keeta"); vazio = não confere
KEETA_API_BASE_URL=https://open.mykeeta.com/api/open/opendelivery
KEETA_TIMEZONE=America/Sao_Paulo    # fuso dos horários enviados (convertidos para UTC)
KEETA_AUTO_ACCEPT=true              # aceite automático padrão das filiais novas
KEETA_TOKEN_REFRESH_MARGIN=300
KEETA_HOMOLOGATION_MODE=false       # mostra a URL do GET merchant no Diagnóstico
```

Workers: eventos, polling e propagação vão pra fila `critical`; os mesmos workers do iFood e da 99Food atendem.

## Configuração no Dev Portal da Keeta

1. NDA e cadastro no Dev Portal; criar o app (modo **App Level Token**: um token para todas as lojas autorizadas).
2. Copiar `client_id` e `client_secret` para o ambiente. `KEETA_APP_ID` fica `Keeta`: é o remetente no `X-App-Id` dos webhooks, não o Application ID do app (com o Application ID todo webhook de pedido volta 403).
3. Webhook de autorização (Formal Store): `https://<domínio>/webhooks/keeta/authorization` para os tipos **1301** e **1302**.
4. O webhook de pedidos e a URL do cardápio **não** são configurados no portal: o VEDDI envia por loja no `PUT /v1/merchantOnboarding` (`ordersWebhookURL` = `https://<domínio>/webhooks/keeta/orders`, `getMerchantURL` = `https://<domínio>/integrations/keeta/merchant/<merchantRef>` + `apiKey`).
5. Ligar `KEETA_ENABLED=true` só no ambiente de homologação; `KEETA_COMPANY_IDS` para abrir com uma empresa só.

O token só é emitido se houver ao menos uma loja autorizada no app: antes da primeira autorização, qualquer chamada além do link de autorização falha.

## Como a loja conecta

1. Admin da empresa abre Integrações › Keeta, escolhe a filial e clica em **Conectar Keeta**.
2. O VEDDI pede o link (`GET /oauth/authorization/url` com `redirectUri` = `/admin/settings/keeta/callback`), põe nele um `state` próprio da filial e manda o admin para o portal da Keeta.
3. O lojista entra com a conta da marca ou da loja, escolhe as lojas e confirma. A Keeta volta para o callback com `authId` e `state`.
4. O callback confere o `state` (amarra o retorno à filial e à empresa logada) e lê as lojas **daquela autorização** (`merchantInfo`). Nunca aparece a lista do app inteiro.
5. O admin escolhe a loja da filial; o VEDDI recusa loja já ligada em outra filial (de qualquer empresa) e faz o `merchantOnboarding`. Sem ele nenhum evento chega.

Desconectar fecha a loja na Keeta (`merchantStatus=UNAVAILABLE`) e troca o `merchant_ref`, para que eventos do mapeamento antigo caiam em "integração não encontrada". A revogação de verdade é do lojista, no portal. Revogação (1302) deixa a integração **Autorização revogada**; nova autorização (1301) da mesma loja reaplica o onboarding sozinha.

## Pedido

- Evento `CREATED`/`PLACED` → `GET /v1/orders/{id}` → pedido `pending` no painel (mesma transação do `Payment`). Item com `externalCode` `veddi-p{id}` (e opções `veddi-o{id}`) é reprecificado pelo cadastro; o resto entra como item avulso com nome e preço do pedido (nunca recusa).
- Totais locais seguem a regra dos outros marketplaces: itens − descontos de item/carrinho + frete e taxas **recebidos pela loja** (`receivedBy=MERCHANT`). O que o cliente pagou à Keeta fica em `keeta_order_amount` e `raw_totals`.
- Aceite: 5 minutos, senão a Keeta cancela e fecha a loja temporariamente. Com **aceite automático** (padrão das filiais novas), o VEDDI confirma logo depois de importar; se falhar, o pedido fica aguardando aceite e o alarme toca.
- Etapas (só avançam): `CONFIRMED` → `preparing`; `READY_FOR_PICKUP`/`PICKUP_ONGOING` → `ready`; `ORDER_COLLECTED`/`DISPATCHED` → `out_for_delivery`; `DELIVERED`/`CONCLUDED` → `delivered` (quita pagamento pendente).
- Ações da loja chamam a Keeta antes do status local: `confirm`, `readyForPickup`, e na entrega própria `dispatch`/`delivered`. **422** é ação repetida: o VEDDI relê o pedido e aplica a etapa real.
- Cancelamento pela loja: `requestCancellation` (202) marca "cancelamento pedido"; só o evento `CANCELLED` cancela e devolve o estoque. `UNAVAILABLE_ITEM` leva `outOfStockItems`; `OUTDATED_MENU` leva `invalidItems` (a Keeta desativa os itens).
- Cancelamento depois de entregue (a Keeta pode cancelar até 30 dias depois): o pedido segue entregue, o cancelamento fica no snapshot e a loja é avisada.
- Cliente: `USER_REFUND_REQUEST` abre `KeetaRequest` (aceitar/recusar; vale a primeira resposta, pela API ou portal); `REFUNDED`/`REFUND_FAILED` fecham e registram no pedido (valores em centavos na metadata). `CANCELLATION_REQUESTED` do cliente vira aviso; o da própria loja é ignorado.
- Dados pessoais vêm criptografados (`ENC_`). Só a entrega própria chama `batchDecrypt` (telefone e endereço); `ENC_` nunca é gravado em campo de cliente.
- Cliente local achado pelo `customer.id` da Keeta (`customers.keeta_customer_id`), com telefone marcador `keeta:<hash>`.

## Cardápio

A Keeta **busca** o cardápio no nosso `GET merchant` (um por filial, protegido por `X-API-KEY`); o VEDDI só avisa com `merchantUpdate` vazio. Publicação é opt-in (aba Cardápio): sem ela o endpoint responde 404. Com cardápio pela API, a edição manual no portal da Keeta fica bloqueada.

Entram produtos ativos, ligados à filial e marcados "Disponível na Keeta", com preço. Ids: Item `veddi-p{id}`, ItemOffer `veddi-po{id}`, opção `veddi-o{id}`/`veddi-oo{id}`, grupo `veddi-g{id}`, categoria `veddi-c{id}`. Horários vêm do cadastro da filial, em UTC (turno que vira o dia é quebrado; 24:00 vai como 23:59). Produto pode ter horário próprio na Keeta (cadastro do produto: dias e início/fim locais), publicado como `Availability` `veddi-a{id}`. Alteração de produto, complemento, estoque (inclusive ligar o rastreio com estoque zerado), telefone/horário da filial e e-mail da empresa agenda o aviso com debounce de 1 min; há aviso diário às 04:45. Categoria ou produto com nome de outro app de entrega (iFood, Rappi etc.) fica de fora com pendência (a Keeta descarta).

## Polling

Fallback do webhook: a cada 30 s, só lojas com `webhook_status` `unknown` (nunca recebeu) ou `degraded` (sem webhook há 30 min), até 100 por chamada e 2 chamadas por minuto por token. Evento gravado e só depois confirmado (`acknowledgment`). A Keeta dá `eventId` diferente ao mesmo evento no polling e no webhook (SIT 19/11), então os dois viram linhas separadas; quem evita pedido ou etapa duplicada é o importador (pelo id do pedido) e a regra de só avançar etapa. O evento do polling não traz a loja: com uma loja no lote ela é atribuída direto; com várias, sai do `merchant.id` do detalhe do pedido.

## Roteiro na loja de teste (SIT)

Antes: filial com cardápio no VEDDI, integração conectada, cardápio **publicado** e aba Diagnóstico aberta (eventos, origem, polling, última busca do cardápio) + log `keeta`.

1. Assinatura: primeira chamada real (token e `merchantInfo`). Se a Keeta recusar a assinatura, conferir com a ferramenta oficial (`https://toolset.nocode.meituan.com/`) a string montada por `KeetaSigner::signatureString`.
2. Onboarding e `GET merchant`: conferir no Dev Portal (Mapping Check) e a "Última busca do cardápio" na aba Diagnóstico.
3. Pedido de teste pelo Test order tool do Dev Portal (ou pelo app Keeta): chega como `CREATED`, aparece no painel, aceite manual e automático, pronto, coleta pelo simulador de entregador, conclusão.
4. Recusa e cancelamento pela loja (incluindo `UNAVAILABLE_ITEM` e `OUTDATED_MENU`), cancelamento pela Keeta, reembolso aceito e recusado.
5. Webhook fora do ar (bloquear o endpoint): o polling deve trazer os eventos e o ack deve limpar a fila.
6. Fechar/abrir loja e pausa da filial com "Fechar também na Keeta".

## Validado na API real (SIT, 18/11/2026)

Loja de teste `159655864` ligada à filial Centro da empresa 15 (ambiente local exposto por túnel só nas rotas Keeta). Evidências em `docs/keeta-evidencias/2026-11-18/`.

- Assinatura: `merchantUpdate` sem corpo é aceito; com `{}` a Keeta responde 401 Invalid signature (ela inclui o `{}` no cálculo). O `dispatch` sem rastreio também vai sem corpo.
- `GET oauth/authorization/url` funciona sem token (o token só existe depois da primeira loja autorizada).
- A Keeta busca o GET merchant em ~1 s depois do `merchantUpdate`; o portal da loja reflete em 1–2 min. A tela de cardápio do portal só atualiza com recarga completa da página.
- Nome que cita outro app de entrega é descartado sem erro: categoria com **iFood** (duas categorias testadas) e produto com **iFood** ou **Rappi** (o resto da categoria entra). O VEDDI deixa esses produtos de fora com pendência pedindo para renomear; 99Food, Uber Eats e aiqfome seguem a mesma regra por precaução (não testados).
- Preço promocional (`value` < `originalValue`), acentos e travessão nos nomes são aceitos.
- `ITEM_OFFER` parcial só com `id` e `status` é aceito (204), mas o VEDDI avisa por recarga completa (debounce de 1 min).
- Telefone no formato do exemplo (`55-11999999999`) aparece no portal como `+55 11 99999-9999`.
- Horário 00:00–23:59 vira 168 h no portal; `GET status` devolve os mesmos `serviceHours`. Fora do horário o status é `UNAVAILABLE` mesmo com a loja aberta.
- Disponibilidade (estoque zerado, opção pausada) e horário por produto (`availabilityId`, "Recurring schedule") aparecem no portal.

## Validado na API real (SIT, 19/11/2026): pedidos

Pedidos criados no Dev Portal (Tools › Test order tool) e entrega simulada em Tools › Courier simulator. O Dev Portal só aceita conta developer e divide a sessão com o portal do lojista (`mykeeta.com`): logar uma derruba a outra. Evidências em `docs/keeta-evidencias/2026-11-19/`.

- Webhook de pedido chega com `X-App-Id: Keeta` (o remetente), não com o Application ID do app: com `KEETA_APP_ID` = Application ID todo webhook voltava 403 e só o polling trazia o pedido. Agora `KEETA_APP_ID` padrão é `Keeta`.
- Fluxo completo (pedido 5880760721189147): CREATED → `confirm` 202 (aceite automático) → `readyForPickup` 202 → simulador → `CONCLUDED` → entregue. As etapas intermediárias do entregador (aceitou, chegou na loja, saiu) não geram evento no Open Delivery.
- Token renovado à força e pedido seguinte aceito só com o token novo (5880763055807564).
- Cancelamento pela loja depois do aceite: `requestCancellation` 202 e `CANCELLED` em ~1 s.
- Sem aceite: a Keeta cancela em ~5 min (`CANCELLED`) e **deixa a loja `UNAVAILABLE`**. O VEDDI não percebe esse fechamento (segue `AVAILABLE` no banco); reabrir com `merchantStatus`. Pendente: avisar a loja / ler o status.
- Aceite pelo Keeta Order Manager (aceite automático do VEDDI desligado): `CONFIRMED` chega em ~10 s e o pedido vai para preparando (5880762511407709). Login merchant numa janela anônima mantém o developer na janela normal.
- Aceite automático ligado no OM e no VEDDI: o OM aceita na hora, o `confirm` do VEDDI volta 422, o VEDDI relê o pedido e aplica `CONFIRMED` sem erro (5880760310791728). O mesmo pedido cancelado no OM: `CANCELLED` em ~1 s e pedido cancelado no VEDDI.
- `MIN_ORDER_FEE` chega em `otherFees` e entra no total da loja; `delivery.pickupCode` veio igual ao `displayId` na loja de teste.
- A observação digitada no carrinho do Test order tool não chega; a ferramenta manda `items[].specialInstructions` = "Enviar talheres descartáveis para este pedido". Promoção não vem (`promotions` vazio) sem configuração da equipe Keeta.
- A loja de teste é "Keeta delivery": casos de entrega própria (batchDecrypt) pedem outra loja de teste (Dev Portal › Test store › Create store, nome fixo, limite 5).

## Validado no Keeta Test App (SIT, 26/11/2026)

Keeta Test App (TestFlight, QR enviado pela Keeta no ticket 50003) espelhado no Mac, endereço `keetatestbr3` (igreja em Tsiroanomandidy; a loja aparece a 0,2 km). Pedido mínimo R$ 20. Os vouchers do app ("60% off" etc.) só valem em cidades selecionadas e não aplicam na área de teste.

- **Pedido real do app vem mascarado**: nome do cliente `Guilherme***`, telefone, rua, número, bairro e complemento = "Proteção de Privacidade", estado `Bongolava`. O import quebrava (`addresses.number` tem 20 caracteres, `state` 2). Corrigido: número longo vira `S/N`, estado vira UF (nome do estado → sigla; fora do Brasil fica vazio) e os demais campos são cortados no tamanho da coluna. O Test order tool não pegava isso (manda endereço normal).
- **Cancelamento imediato pelo usuário** (pedido 586 / Keeta 5950763345430018, aceite automático desligado no VEDDI e no Order Manager): o app mostra "Waiting for store to confirm"; o cliente cancela, o `CANCELLED` chega em ~2 s e o pedido fica cancelado no VEDDI. O metadata real usa `cancelType` (5010) e `cancelReason` ("Cancelado pelo cliente"), não `code`/`reason`: agora o VEDDI lê os dois formatos. O app mostra reembolso total automático.
- **Fluxo com aceite automático** (pedido 587 / #2811): `CREATED` → `confirm` → `CONFIRMED` em 3 s; "Pedido pronto" na tela → `READY_FOR_PICKUP`; Courier simulator → `CONCLUDED` → entregue.
- **Reembolso pedido pelo usuário** (mesmo pedido, depois de entregue): no app, "Missing item" → item → foto obrigatória → embalagem → "Submit request" (R$ 10, parcial). `USER_REFUND_REQUEST` chega em ~2 s e abre a solicitação na tela do pedido. **Recusar** (`rejectRefund`, motivo `OTHER`) volta **422 "Please wait for the CS to process, you don't need to take any action"**: em pedido com entrega Keeta o atendimento da Keeta decide, a loja não. Antes o VEDDI tratava todo 422 como "já respondido" e gravava "Recusada" sem a recusa valer; agora esse 422 deixa a solicitação **Em análise no atendimento da Keeta** (sem botões) e o `REFUNDED`/`REFUND_FAILED` fecha. O 422 de etapa ("order status is not in a valid state") segue como já respondido. Uns 20 min depois o app mostrou "Refund declined" (atendimento), **sem** `REFUND_FAILED` para nós.
- **Loja de entrega própria** `159670600` ("VEDDI SIT Entrega Propria - API Test store", criada em Dev Portal › Test store com "Don't authorise yet"; usuário e senha do lojista vão para o e-mail da conta developer). Autorizada pelo fluxo do VEDDI (filial 28 "Nova filial" › Conectar Keeta › login do lojista › Authorize › callback lista só essa loja › Conectar esta loja → `merchantOnboarding` 201, integração 2) e cardápio publicado (GET merchant buscado em 3 s; o app passou a mostrar o cardápio do VEDDI uns 5 min depois).
- **Entrega própria** (pedido 588 / #SD4617, `deliveredBy=MERCHANT`): `batchDecrypt` traz nome, telefone e endereço completos; aceite automático → "Pedido pronto" → "Saiu para entrega" → "Entregue" pela tela, com `READY_FOR_PICKUP`, `DISPATCHED`, `DELIVERED` e `CONCLUDED`. O `dispatch` com `datetime` em milissegundos (`.261Z`) voltava **400 "Invalid event datetime format, should comply with RFC3339"**: agora vai sem milissegundos (como o exemplo da doc). Nesta loja o frete (R$ 15) vem `receivedBy=LOGISTIC_SERVICES`, então não entra no total local (só `MERCHANT` entra): validar com a Keeta.
- **Loja recusa reembolso do usuário** (mesmo pedido 588, reembolso total pedido no app): o app mostra "Waiting for store review… by 12:17" (pedido às 12:07: **prazo de 10 min**, agora usado no VEDDI em vez de 24 h). Recusa pela tela (`OTHER` + texto) → `rejectRefund` **204**; o app mostra "Refund rejected" com o texto da loja. Nenhum `REFUND_FAILED` chegou.
- **Promoção** (pedido 589 / #SD9777): promoção da loja criada no portal do lojista (Promotion centre › Discounts, 25% acima de R$ 60, todos os clientes, 1 mês). 6× Coca colca: R$ 60 − R$ 15. Chegou `discounts` com `target=CART`, valor 15 e `sponsorshipValues` só da loja: no VEDDI `promotions` = "Desconto (21230085)", `shop_subsidy` 15, `manual_discount` 15, total 45, itens ligados ao cadastro. Pedido concluído pela API (pronto, despacho, entregue). Promoção paga pela Keeta não deu para testar: os vouchers do app são só de cidades selecionadas.
- A loja de entrega própria apareceu **fechada** ("Automatically reopens at 27 Nov 08:00") minutos depois do primeiro pedido, sem chamada do VEDDI; o portal do lojista não reabre (manda usar o Keeta Orders). Reaberta com `merchantStatus` `AVAILABLE` pelo VEDDI. É a mesma lacuna de "loja fechada pela Keeta sem o VEDDI saber".

## Status da homologação (19/11/2026)

SIT Platform (`api-integrate-guide.mynocode.host`, SIT V1.0.6): **30 de 30 aprovados + 1 N/A, enviada em 26/11/2026** (antes: 25/30 em 19/11), com ID do pedido, print e observação em cada caso. SOW V1.0.8 também enviado. O login usa um código de acesso que só o responsável tem; recarregar a página desloga e só fica o que foi salvo com "Salvar".

| Bloco | Situação |
|---|---|
| Cadastro de nova loja | 1/1 |
| Cardápio | 5/6 + 1 N/A (o caso "sem integração da API de cardápio" não se aplica) |
| Fluxo de pedido direto | 5/6 salvos; **promoção** executada em 26/11 (falta lançar) |
| Fluxo reverso | 2/4 salvos; **cancelamento imediato pelo usuário** e **loja recusa reembolso** executados em 26/11 (falta lançar) |
| Idempotência multi-terminal (Order Manager) | 2/2 |
| Entrega própria (não obrigatório) | batchDecrypt e Pronto → Saiu → Entregue executados em 26/11 (falta lançar) |
| Autenticação | 2/2 |
| Loja | 5/5 |
| Autorização do estabelecimento | 3/3 |

Pedidos de teste usados (empresa 15, filial 19): 577 / #9147 (fluxo completo, ID de exibição, notas, comprovante, pickup code, taxa mínima), 578 / #7564 (token novo, cancelamento depois do aceite), 579 / #1923 (timeout), 581 / #7709 (aceite pelo OM), 582 / #1728 (aceite duplicado e cancelamento pelo OM). O 580 / #4086 venceu sem aceite sem querer.

**Bloqueio atual:** a loja de teste 159655864 tem o texto de endereço "Avenida Faria Lima, 2000, São Paulo", mas o **pino fica em Tsiroanomandidy, Madagascar**. O app Keeta abre a loja pelo link de compartilhamento (portal do lojista › Settings › Profile › Share store link), mas diz "fora da área de entrega" para endereço em São Paulo. A localização não muda pelo portal (Profile › Edit só troca nome, categoria e fotos) nem pela API (a Keeta ignora `basicInfo.address`). Aberto o **ticket 50003** no Dev Portal (Tickets, tipo "Developer portal") pedindo mover o pino para São Paulo e configurar uma promoção com subsídio da Keeta e da loja.

Resposta da Keeta (23/11): o pino **não muda**. As lojas de teste ficam em Bongolava (Madagascar) de propósito, e o endereço de entrega tem que estar perto delas. No celular, o pedido sai pelo **Keeta Test App**, não pelo app público. O Test App é instalado por um link enviado pela Keeta. Nele: escolher Brasil e, em "Digite um endereço", digitar `keetatestbr3` (uma igreja em Madagascar). No app público esse endereço não resolve (testado em 24/11). Segundo a doc (`api-docs.mykeeta.com/apis/opendelivery/keeta-test-app`), **reembolso não existe** nem no Test App nem no Developers Portal. Para a promoção, a Keeta pediu loja e item: em 24/11 respondemos loja 159655864 e item "Coxinha de frango" (`veddi-p66`, R$ 6,00). No mesmo dia pedimos o link do Test App e perguntamos como fazer o caso de reembolso recusado. Promoção só com subsídio da loja dá para criar no portal do lojista. A com subsídio da plataforma fica com o time de API da Keeta.

### O que falta para finalizar (atualizado 26/11)

Todos os casos de pedido já rodaram na API real (seção "Validado no Keeta Test App"). Falta só a parte do responsável na SIT Platform (código de acesso):

| Caso da SIT | Pedido VEDDI / Keeta | Observação para a SIT |
|---|---|---|
| Cancelamento imediato pelo usuário | 586 / 5950763345430018 (#0018) | aceite automático desligado; `CANCELLED` (cancelType 5010) em 2 s |
| Loja recusa reembolso do usuário | 588 / 5950760032954617 (#SD4617) | loja de entrega própria; `rejectRefund` 204, app "Refund rejected". Na entrega Keeta (587 / #2811) a Keeta responde 422 "wait for the CS" e o atendimento decide |
| Promoção | 589 / 5950763939129777 (#SD9777) | promoção da loja (25% acima de R$ 60); `discounts` CART 15, subsídio da loja 15. A paga pela Keeta não existe na área de teste |
| Entrega própria: batchDecrypt | 588 | nome, telefone e endereço descriptografados |
| Entrega própria: Pronto → Saiu → Entregue | 588 e 589 | `dispatch` 202 depois do ajuste do datetime, `delivered` 202 |

**Feito em 26/11 (tarde):** casos lançados com ID, print e observação; SIT Platform **30/30 + 1 N/A, enviada** ("Enviado / Submitted"); **SOW V1.0.8 preenchido e enviado** online (App_level_token, polling + webhook, ativação B self-service, cardápio pela API, aceite manual com aceite automático opcional, cancelamento antes e depois do aceite, todos os eventos assinados; "Não" só em `merchantUpdate` com entityType, multi-cardápio, produto de sede, validação de imagem e "Send Delivery Updates"); modelos Word **SOW-Template-V1.0.7** e **SIT Test Cases-V1.0.7** preenchidos com as mesmas respostas e subidos no Dev Portal (Information management › Integration document management, "Latest upload: 26 Nov 2026"); **ticket 50003 marcado como resolvido**. Na SIT, o caso de promoção foi aprovado com a promoção da loja e a observação de que a paga pela Keeta não existe na área de teste.

Próximo passo é da Keeta: SIT Review Meeting; depois cadastro da marca e UAT por marca em produção.

### Antes de produção

- **Loja fechada pela Keeta sem o VEDDI saber**: depois de um pedido sem aceite, a Keeta deixa a loja `UNAVAILABLE` e o VEDDI segue mostrando aberta. Falta ler o `GET status` (no monitor ou ao receber o cancelamento por timeout) e avisar a loja no sino e na aba Loja.
- Deploy: `php artisan migrate` (`2026_11_18_000001_add_keeta_availability_to_products`), variáveis `KEETA_*` no Cloud com `KEETA_APP_ID=Keeta`, webhook 1301/1302 do Dev Portal apontando para o domínio de produção. Merge em `main` só com ok.

### Ambiente local da SIT (para retomar)

- `.env` local: `APP_URL` = túnel público, `KEETA_ENABLED=true`, `KEETA_HOMOLOGATION_MODE=true`, `KEETA_APP_ID=Keeta`. O túnel aponta para um proxy local que só repassa as rotas Keeta (`/webhooks/keeta/*` e `/integrations/keeta/*`) ao Docker na porta 8000. Ao trocar o túnel, reaplicar o onboarding da loja (a URL do webhook vai no `merchantOnboarding`).
- Empresa 15, filial 19 (Centro), integração 1, loja 159655864 (entrega Keeta), aceite automático ligado. Filial 28 (Nova filial), integração 2, loja 159670600 (entrega própria).
- Keeta Test App (TestFlight) no iPhone, espelhado no Mac; endereço salvo "Eglise Adventiste (SDA Church)…" (busca `keetatestbr3`). O app às vezes volta para o endereço real ao reabrir: trocar no topo antes de pedir.
- Sessões: SIT Platform (código de acesso), Dev Portal (conta developer) e portal do lojista / Order Manager dividem a sessão `mykeeta.com`. Usar o lojista numa janela anônima e o developer na janela normal.
- Ferramentas do Dev Portal: Test order tool (cria o pedido, a observação do carrinho não chega), Courier simulator (só a conclusão gera evento), Log search.

## Itens a validar com a Keeta

- Forma exata da assinatura dos webhooks (corpo só ou URL + corpo; corpo cru ou canônico): o VEDDI aceita as quatro.
- Formato do header `x-polling-merchants` com várias lojas (vírgula).
- Quais códigos de cancelamento valem na entrega Keeta. (Prazo do reembolso: 10 min, visto no app em 26/11.)
- Frete da entrega própria vem `receivedBy=LOGISTIC_SERVICES` (loja 159670600): se é da loja, tem que entrar no total local.
- `REFUND_FAILED` não chegou nem na recusa da loja (204) nem na do atendimento (26/11): confirmar se a Keeta manda esse evento.
- Por que a loja de teste fica fechada ("reabre amanhã 08:00") logo depois de um pedido, sem chamada do VEDDI.
- Se pedido agendado (`SCHEDULED`) também exige confirmação em 5 min.
- Fórmula dos totais em pedido real (descontos patrocinados pela loja × Keeta, taxas `receivedBy`).
- Se a Keeta também exige `basicInfo` completo (endereço, documento) apesar de marcar como ignorado.

## Operação

- `php artisan keeta:check-connections`: alertas (revogada, sem vínculo, cardápio recusado) e loja fechada na Keeta para empresa que não pode operar. Agendado a cada 10 min.
- Reprocessar evento com falha: aba Diagnóstico.
- Agendamentos em `routes/console.php`, todos atrás de `$keetaEnabled`.
