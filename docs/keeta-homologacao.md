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
KEETA_APP_ID=                       # X-App-Id dos webhooks de pedido; vazio = não confere
KEETA_API_BASE_URL=https://open.mykeeta.com/api/open/opendelivery
KEETA_TIMEZONE=America/Sao_Paulo    # fuso dos horários enviados (convertidos para UTC)
KEETA_AUTO_ACCEPT=true              # aceite automático padrão das filiais novas
KEETA_TOKEN_REFRESH_MARGIN=300
KEETA_HOMOLOGATION_MODE=false       # mostra a URL do GET merchant no Diagnóstico
```

Workers: eventos, polling e propagação vão pra fila `critical`; os mesmos workers do iFood e da 99Food atendem.

## Configuração no Dev Portal da Keeta

1. NDA e cadastro no Dev Portal; criar o app (modo **App Level Token**: um token para todas as lojas autorizadas).
2. Copiar `client_id` e `client_secret` para o ambiente; `KEETA_APP_ID` com o AppId informado pela Keeta.
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

Entram produtos ativos, ligados à filial e marcados "Disponível na Keeta", com preço. Ids: Item `veddi-p{id}`, ItemOffer `veddi-po{id}`, opção `veddi-o{id}`/`veddi-oo{id}`, grupo `veddi-g{id}`, categoria `veddi-c{id}`. Horários vêm do cadastro da filial, em UTC (turno que vira o dia é quebrado; 24:00 vai como 23:59). Alteração de produto agenda o aviso com debounce de 1 min; há aviso diário às 04:45.

## Polling

Fallback do webhook: a cada 30 s, só lojas com `webhook_status` `unknown` (nunca recebeu) ou `degraded` (sem webhook há 30 min), até 100 por chamada e 2 chamadas por minuto por token. Evento gravado com o mesmo `event_id` do webhook (sem duplicar) e só depois confirmado (`acknowledgment`). O evento do polling não traz a loja: com uma loja no lote ela é atribuída direto; com várias, sai do `merchant.id` do detalhe do pedido.

## Roteiro na loja de teste (SIT)

Antes: filial com cardápio no VEDDI, integração conectada, cardápio **publicado** e aba Diagnóstico aberta (eventos, origem, polling, última busca do cardápio) + log `keeta`.

1. Assinatura: primeira chamada real (token e `merchantInfo`). Se a Keeta recusar a assinatura, conferir com a ferramenta oficial (`https://toolset.nocode.meituan.com/`) a string montada por `KeetaSigner::signatureString`.
2. Onboarding e `GET merchant`: conferir no Dev Portal (Mapping Check) e a "Última busca do cardápio" na aba Diagnóstico.
3. Pedido de teste pelo app Keeta Test: chega como `CREATED`, aparece no painel, aceite manual e automático, pronto, coleta pelo simulador de entregador, conclusão.
4. Recusa e cancelamento pela loja (incluindo `UNAVAILABLE_ITEM` e `OUTDATED_MENU`), cancelamento pela Keeta, reembolso aceito e recusado.
5. Webhook fora do ar (bloquear o endpoint): o polling deve trazer os eventos e o ack deve limpar a fila.
6. Fechar/abrir loja e pausa da filial com "Fechar também na Keeta".

## Itens a validar com a Keeta

- Forma exata da assinatura dos webhooks (corpo só ou URL + corpo; corpo cru ou canônico): o VEDDI aceita as quatro.
- Formato do header `x-polling-merchants` com várias lojas (vírgula).
- Prazo de resposta do reembolso (exibido como 24 h) e quais códigos de cancelamento valem na entrega Keeta.
- Se pedido agendado (`SCHEDULED`) também exige confirmação em 5 min.
- Fórmula dos totais em pedido real (descontos patrocinados pela loja × Keeta, taxas `receivedBy`).
- Se a Keeta também exige `basicInfo` completo (endereço, documento) apesar de marcar como ignorado.

## Operação

- `php artisan keeta:check-connections`: alertas (revogada, sem vínculo, cardápio recusado) e loja fechada na Keeta para empresa que não pode operar. Agendado a cada 10 min.
- Reprocessar evento com falha: aba Diagnóstico.
- Agendamentos em `routes/console.php`, todos atrás de `$keetaEnabled`.
