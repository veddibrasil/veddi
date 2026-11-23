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

## Status da homologação (19/11/2026)

SIT Platform (`api-integrate-guide.mynocode.host`, SIT V1.0.6): **25 de 30 aprovados + 1 N/A**, com ID do pedido, print e observação em cada caso. Progresso **salvo e ainda não enviado** (botão "Enviar / Submit"). O login usa um código de acesso que só o responsável tem; recarregar a página desloga e só fica o que foi salvo com "Salvar".

| Bloco | Situação |
|---|---|
| Cadastro de nova loja | 1/1 |
| Cardápio | 5/6 + 1 N/A (o caso "sem integração da API de cardápio" não se aplica) |
| Fluxo de pedido direto | 5/6: falta **promoção Keeta** |
| Fluxo reverso | 2/4: faltam **cancelamento imediato pelo usuário** e **loja recusa reembolso do usuário** |
| Idempotência multi-terminal (Order Manager) | 2/2 |
| Entrega própria (não obrigatório) | 0/2: batchDecrypt e fluxo Pronto → Saiu → Entregue |
| Autenticação | 2/2 |
| Loja | 5/5 |
| Autorização do estabelecimento | 3/3 |

Pedidos de teste usados (empresa 15, filial 19): 577 / #9147 (fluxo completo, ID de exibição, notas, comprovante, pickup code, taxa mínima), 578 / #7564 (token novo, cancelamento depois do aceite), 579 / #1923 (timeout), 581 / #7709 (aceite pelo OM), 582 / #1728 (aceite duplicado e cancelamento pelo OM). O 580 / #4086 venceu sem aceite sem querer.

**Bloqueio atual:** a loja de teste 159655864 tem o texto de endereço "Avenida Faria Lima, 2000, São Paulo", mas o **pino fica em Tsiroanomandidy, Madagascar**. O app Keeta abre a loja pelo link de compartilhamento (portal do lojista › Settings › Profile › Share store link), mas diz "fora da área de entrega" para endereço em São Paulo. A localização não muda pelo portal (Profile › Edit só troca nome, categoria e fotos) nem pela API (a Keeta ignora `basicInfo.address`). Aberto o **ticket 50003** no Dev Portal (Tickets, tipo "Developer portal") pedindo mover o pino para São Paulo e configurar uma promoção com subsídio da Keeta e da loja.

### O que falta para finalizar

1. **Acompanhar o ticket 50003** (Dev Portal › Tickets › View; a resposta também vai para o e-mail de contato da conta developer).
2. Com a loja em São Paulo, fazer pelo **app Keeta** com a conta do "Link user" da loja de teste (Dev Portal › Test store › View details):
   - *Cancelamento imediato pelo usuário*: aceite automático **desligado** no VEDDI (integração) e no Keeta Order Manager; pedir no app e cancelar antes de aceitar; conferir o `CANCELLED` e o pedido cancelado no VEDDI.
   - *Loja recusa reembolso do usuário*: pedir no app, aceitar e marcar pronto no VEDDI, entregar pelo Courier simulator, pedir reembolso parcial no app e **recusar pela tela do pedido no VEDDI** (`rejectRefund`); conferir o fechamento do pedido de reembolso.
   - *Promoção Keeta*: pedir um item com a promoção configurada pela Keeta, aceitar e conferir `promotions`, descontos patrocinados (Keeta × loja) e totais.
3. **Entrega própria** (opcional): criar uma loja de teste "self-delivery" (Dev Portal › Test store › Create store; nome não muda, limite de 5), autorizar no VEDDI em outra filial e testar `batchDecrypt` (nome, telefone, endereço) e `dispatch`/`delivered`. Se não for fazer, marcar N/A e explicar no SOW.
4. Preencher o **SOW** (SIT Platform, V1.0.8, ainda "Em andamento") e subir o SOW e o SIT Checklist em Dev Portal › Information management › Integration document management.
5. **Enviar a SIT** ("Enviar / Submit"). A Keeta marca a SIT Review Meeting; depois vêm o cadastro da marca (onboarding) e a UAT por marca em produção.

### Antes de produção

- **Loja fechada pela Keeta sem o VEDDI saber**: depois de um pedido sem aceite, a Keeta deixa a loja `UNAVAILABLE` e o VEDDI segue mostrando aberta. Falta ler o `GET status` (no monitor ou ao receber o cancelamento por timeout) e avisar a loja no sino e na aba Loja.
- Deploy: `php artisan migrate` (`2026_11_18_000001_add_keeta_availability_to_products`), variáveis `KEETA_*` no Cloud com `KEETA_APP_ID=Keeta`, webhook 1301/1302 do Dev Portal apontando para o domínio de produção. Merge em `main` só com ok.

### Ambiente local da SIT (para retomar)

- `.env` local: `APP_URL` = túnel público, `KEETA_ENABLED=true`, `KEETA_HOMOLOGATION_MODE=true`, `KEETA_APP_ID=Keeta`. O túnel aponta para um proxy local que só repassa as rotas Keeta (`/webhooks/keeta/*` e `/integrations/keeta/*`) ao Docker na porta 8000. Ao trocar o túnel, reaplicar o onboarding da loja (a URL do webhook vai no `merchantOnboarding`).
- Empresa 15, filial 19 (Centro), integração 1, loja 159655864, aceite automático ligado.
- Sessões: SIT Platform (código de acesso), Dev Portal (conta developer) e portal do lojista / Order Manager dividem a sessão `mykeeta.com`. Usar o lojista numa janela anônima e o developer na janela normal.
- Ferramentas do Dev Portal: Test order tool (cria o pedido, a observação do carrinho não chega), Courier simulator (só a conclusão gera evento), Log search.

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
