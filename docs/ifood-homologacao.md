# Homologação iFood — Merchant, Catalog e Order

Preparação dos seis cenários enviados pelo suporte em 25/09/2026. Os testes automatizados usam respostas simuladas da API; não substituem a execução na loja de teste nem a comprovação no Portal do Parceiro.

## Antes de gravar

- Ligar `IFOOD_HOMOLOGATION_MODE=true` no ambiente da gravação. Sem a flag, a tela mostra só o resumo para o restaurante: somem o Client ID, as respostas completas da API, **Listar todas as lojas**, **Consultar lojas, detalhes e disponibilidade** e **Preencher sábado e domingo da homologação** (as ações também respondem 403).
- Conectar a filial da loja de teste em **Integrações → iFood** (`/admin/settings/ifood`). O Merchant ID fica na aba **Diagnóstico**.
- Conferir o `client_id` exibido no card **Homologação — operação da loja no iFood**, na aba **Loja**. Ele vem de `IFOOD_PARTNER_CLIENT_ID`; não mostrar o client secret, tokens ou arquivo `.env`.
- A tela é dividida em abas (**Loja**, **Pausas**, **Horários**, **Cardápio**, **Diagnóstico**). Cada aba consulta o iFood sozinha ao abrir; os botões do roteiro continuam com os mesmos nomes e fazem a mesma chamada.
- Usar o fuso da loja. A configuração `IFOOD_TIMEZONE` tem padrão `America/Sao_Paulo`; os campos de pausa usam esse fuso. O iFood interpreta início e fim da pausa no fuso da loja e ignora offset, então o sistema envia o horário como digitado (sem `-03:00`). O horário mostrado após cada operação inclui o deslocamento UTC.
- Manter o Portal do Parceiro aberto na mesma loja de teste e o relógio/data do computador visíveis.
- Manter o worker da fila em execução para a sincronização ao salvar produtos. **Sincronizar cardápio agora** (aba **Cardápio**) roda na fila: a aba mostra o andamento e, ao final, quantos produtos ficaram de fora e por quê; um produto com problema não impede os demais. Na loja de teste ela falha com 409 enquanto a Coxinha e a Coca (produtos 65 e 66 da filial Centro) tiverem ids locais que não existem no iFood; nas gravações de 28/09 o item foi publicado pelo worker ao salvar o produto.
- Usar fotos JPG ou PNG de até 2 MB no formulário VEDDI. A sincronização também verifica o formato e o limite da API antes do upload.

## Operação do restaurante (fora do roteiro)

- **Publicação do cardápio é opt-in.** Ao conectar uma loja nova, nada é enviado: a aba **Cardápio** mostra quantos produtos e categorias vão, e quais precisam de ajuste (sem categoria, nome acima de 100 caracteres, foto fora de JPG/PNG, erro do último envio). O restaurante clica **Publicar cardápio no iFood**; a partir daí valem o sync diário das 05:00, o envio ao salvar o produto e a disponibilidade em tempo real. Reconectar a mesma loja mantém a publicação; trocar de loja zera o mapeamento (catálogo, categorias e itens) e volta a pedir a publicação.
- **Disponibilidade segue o estoque.** Estoque zerado ou reposto, produto desmarcado da filial e exclusão definitiva tiram/voltam o item de venda no iFood.
- **Pausas.** Em Filiais › Pausas e feriados, "Fechar também a loja no iFood" cria a interrupção no iFood junto (até 7 dias, sem repetição anual) e a remove ao excluir a pausa. Gerente de filial consegue usar.
- **Avisos.** `ifood:check-connections` (a cada 10 minutos) avisa no sino e por e-mail aos administradores quando a autorização cai (também na hora, ao detectar) e no sino quando o iFood não é consultado há mais de 10 minutos.
- **Empresa bloqueada** (ou sem o módulo) para de consultar o iFood e sai da presença do KEEPALIVE: a loja fica fora do ar em vez de receber pedidos que ninguém vê. Empresa nessa situação também não recebe o e-mail diário de reconexão.
- **Integração suspensa** sai do polling e da presença, mas pelo webhook continua recebendo os eventos de pedidos já em andamento (cancelamento do cliente, negociação, conclusão); pedido novo (PLC) é recusado. Sem webhook, esses eventos só chegam ao retomar. Retomar reenvia o cardápio completo (a disponibilidade em tempo real não sai enquanto está suspensa).
- **Envio do cardápio não trava.** Se a integração for suspensa antes de o envio rodar, ou se nenhum item for aceito, o status vira "Envio falhou" na hora (sem repetir por ~17 min na fila) e o botão volta a funcionar.
- **Uma loja, uma integração.** A mesma loja do iFood não pode estar ligada a duas integrações, em nenhum status (índice único em `merchant_id`; retomar uma integração suspensa confere de novo).

## Vídeo 1 — Merchant: informações da loja

1. Selecionar a filial conectada e abrir a aba **Loja**.
2. Clicar **Listar todas as lojas** e mostrar a lista retornada por `GET /merchant/v1.0/merchants` (todas as páginas de lojas autorizadas para o aplicativo). Depois clicar **Consultar lojas, detalhes e disponibilidade**.
3. Mostrar todas as lojas vinculadas, os detalhes completos da loja da filial e as validações de disponibilidade.
4. Mostrar a loja correspondente no Portal do Parceiro.

## Vídeo 2 — Merchant: interrupção

1. Abrir a aba **Pausas**. Informar motivo, início e fim da pausa.
2. Clicar **Criar pausa no iFood** (a lista é atualizada logo em seguida).
3. Clicar **Consultar pausas** e mostrar a pausa criada, incluindo datas.
4. Conferir a pausa no Portal do Parceiro; aguardar a propagação e atualizar a página se necessário.
5. Clicar **Remover pausa**, confirmar no aviso e consultar novamente.
6. Conferir a remoção no Portal do Parceiro.

**Suspender integração** (menu **Mais opções**) para o polling e deixa a loja fora do ar; para este cenário usar a aba **Pausas**, que mantém a integração ativa e continua recebendo eventos.

## Vídeo 3 — Merchant: horários

1. Abrir a aba **Horários** (ela consulta sozinha) ou clicar **Consultar horários** para carregar a semana existente.
2. Clicar **Preencher sábado e domingo da homologação**. Isso preenche o formulário e mantém segunda a sexta; ainda é necessário salvar.
3. Conferir (o editor mostra abertura e fechamento; o envio converte em duração):
   - Sábado: 10:00 às 19:00 (540 minutos).
   - Domingo: 09:00 às 12:00 (180 minutos).
   - Domingo: 13:00 às 16:00 (180 minutos).
   - Domingo: 17:00 às 23:00 (360 minutos).
4. Clicar **Salvar horários no iFood**.
5. Clicar **Consultar horários** novamente e mostrar o retorno.
6. Conferir os turnos no Portal do Parceiro.

Salvar substitui a semana inteira; dias sem turnos ficam fechados. O sistema rejeita turnos sobrepostos, inclusive entre domingo e segunda.

## Vídeo 4 — Catalog: categoria e item

1. Criar a categoria **Teste Homologação** pelo cadastro de categorias ou pelo formulário de produto.
2. Criar **Produto Teste**, selecionar a categoria, informar preço e adicionar foto.
3. Marcar **Ativo**, **Disponível no iFood** e a filial conectada.
4. Salvar; o worker publica o item. **Sincronizar cardápio agora** (aba **Cardápio**) também publica; item que o iFood recusar aparece na lista de pendências da aba, sem impedir os demais. Numa loja recém-conectada, o primeiro envio é o botão **Publicar cardápio no iFood**.
5. Mostrar a categoria, o produto, a foto e o preço no Portal do Parceiro.

A categoria é publicada com o item. Não é necessário executar chamadas fora da interface.

## Vídeo 5 — Catalog: grupo e dois complementos

1. Editar **Produto Teste** e adicionar um grupo de complementos.
2. Cadastrar dois complementos com nome, preço, status ativo e uma foto para cada um.
3. Salvar e sincronizar o cardápio.
4. Mostrar o grupo e ambos os complementos no Portal do Parceiro.

Complementos ficam no próprio item `DEFAULT`, com grupo `OFFER_UNIT` (o Portal mostra o selo "Cross-sell"). Até 28/09 o sistema publicava `COMBO_V2` com o grupo como `MAIN`, e o Portal classificava o item como "Combo vazio", fora do app. Itens publicados assim ganham um novo item `DEFAULT` na próxima sincronização; o ID anterior é pausado antes, evitando dois itens à venda.

## Vídeo 6 — Catalog: alterações

1. Editar o nome, preço e foto do item.
2. Alterar o nome, preço e foto dos dois complementos e pausá-los.
3. Salvar e sincronizar o cardápio.
4. Mostrar todas as alterações no Portal do Parceiro.
5. Se for solicitado demonstrar alteração de status do item, desmarcar **Ativo**, salvar e sincronizar; mostrar a pausa no Portal.

Itens existentes têm preços e status atualizados por `PATCH /items/price` e `PATCH /items/status`; complementos usam `PATCH /options/price` e `PATCH /options/status`. A estrutura (nomes, fotos e grupos) usa `PUT /items` após os PATCH. Alterações somente de preço/status dispensam PUT quando a estrutura retornada pela API é igual à estrutura gerenciada pela VEDDI.

A API aceita um item por chamada em `PATCH /items/price` (`{itemId, price: {value}}`) e `PATCH /items/status` (`{itemId, status}`); com vários itens alterados, a sincronização faz uma chamada por item.

## Verificação do contrato no sandbox

Confirmado na loja de teste em 28/09/2026: o corpo em lote do guia de operações (`{prices: [...]}`, `{items: [...]}`) é recusado com 400 (`PatchItemPriceDto.itemId must be a UUID`). A implementação usa o corpo individual da referência de endpoints: `{itemId, price}` / `{itemId, status}` para itens e `{optionId, price}` / `{optionId, status}` para complementos. Não há fallback silencioso para PUT em caso de rejeição de PATCH.

- [Fluxo de Catalog](https://developer.ifood.com.br/en-US/docs/food/guides/modules/catalog/workflow)
- [Operações comuns de Catalog](https://developer.ifood.com.br/en-US/docs/food/guides/modules/catalog/guides/common-patterns)
- [Referência de Catalog](https://developer.ifood.com.br/en-US/docs/food/guides/modules/catalog/endpoints)
- [Referência de Merchant](https://developer.ifood.com.br/en-US/docs/food/guides/modules/merchant/endpoints)

A sincronização consulta o item remoto por `GET /items/{id}/flat`, publica fotos por `POST /image/upload` e usa o `imagePath` devolvido. Respostas incompletas interrompem a operação. Lotes com `batchId` são consultados antes de indicar sucesso; falhas parciais ou processamento ainda pendente geram erro e exigem nova consulta. Os logs do canal `ifood` registram método, caminho, integração e status HTTP das novas operações, sem registrar o token ou o conteúdo das fotos.

## Módulo Order (pedidos)

Critérios de referência: [homologação do módulo Order](https://developer.ifood.com.br/pt-BR/docs/food/guides/modules/order/homologation). A validação automática do iFood executa conectividade → confirmar → cancelar → despachar → concluir.

### Como o pedido anda

| Etapa no iFood | Evento | Status no VEDDI | Quem faz |
| --- | --- | --- | --- |
| PLACED | `PLC` | Aguardando aceite (`pending`) ou Agendado (`scheduled`) | iFood cria o pedido |
| CONFIRMED | `CFM` | Preparando (agendado continua em Agendado) | botão **Aceitar pedido** ou Gestor de Pedidos |
| PREPARATION_STARTED | `PRS` | Preparando | **Iniciar preparo** (só agendado) |
| READY_TO_PICKUP | `RTP` | Pronto | **Pedido pronto** |
| DISPATCHED | `DSP` | A caminho | **Despachar** (só entrega própria, depois do pronto) |
| CONCLUDED | `CON` | Concluído | sempre o iFood |
| CANCELLED | `CAN` | Cancelado, com origem e motivo | iFood, cliente ou loja |

- Toda ação da loja chama a API primeiro; o pedido local só muda se o iFood aceitar. Eventos de etapa (inclusive de ações feitas fora do VEDDI) só avançam o pedido, nunca voltam.
- Entrega feita pelo iFood (`deliveredBy=IFOOD`) não tem despacho pela loja; retirada e consumo no local também não. A tela mostra o que falta acontecer do lado do iFood.
- Prazo de aceite: 8 minutos a partir do `createdAt` do iFood, com contagem na tela do pedido, no kanban e num alerta no topo da lista de pedidos. Passados mais 7 minutos sem confirmação, o botão de aceitar some e a tela pede para conferir no Gestor de Pedidos.
- Cancelamento pedido pela loja fica "solicitado" até chegar `CAN` (cancelado) ou `CARF` (recusado pelo iFood). `CARF` aparece em destaque no pedido e gera aviso no sino do painel.
- Plataforma de Negociação: `HSD` abre uma negociação no pedido (mensagem do cliente, itens, evidências, prazo e o que acontece sem resposta). A loja aceita, recusa com justificativa ou responde com uma das alternativas oferecidas (reembolso/benefício até o valor máximo, ou tempo adicional). `HSS` registra o desfecho.
- Evento que falha por motivo transitório volta para `pending` e é reprocessado (retry da fila ou próximo polling), até 5 tentativas. O lote do polling é processado na ordem do `createdAt`.
- Pedido que não vira pedido no VEDDI (item sem vínculo com produto da filial, ou falha nas 5 tentativas) gera aviso no sino e um alerta no topo da lista de pedidos ("Resolva pelo Gestor de Pedidos do iFood"), com o motivo. Negociação de pedido que não está no painel também. Item pausado, sem estoque ou com o canal iFood desligado aqui **não** bloqueia a entrada do pedido: ele já foi feito e pago no iFood.

### Informações exibidas

Na tela do pedido e nas comandas (PDF e impressora térmica): número do iFood, tipo (entrega própria, entrega iFood, retirada, consumo no local), horário agendado, código de coleta, telefone 0800 com localizador e validade, CPF/CNPJ na nota, forma de pagamento com bandeira/carteira, o que foi pago online e o que cobrar na entrega, troco, cupons com quem paga cada parte, taxa adicional, observação do pedido (`extraInfo`), observação de cada item e observação/referência de entrega. A comanda de cozinha/bar leva só número, tipo e observações.

Clientes iFood são identificados pelo `customer.id` do iFood, não pelo telefone (o 0800 é o mesmo para todos). O telefone do cadastro é um marcador interno; o contato do pedido fica no próprio pedido.

### Teste ao vivo (Portal do Desenvolvedor)

Gerar pedidos de teste na loja de teste e conferir, com o worker da fila `critical` rodando:

1. Pedido imediato: aceitar pelo VEDDI → pronto → despachar → aguardar a conclusão automática.
2. Pedido imediato: confirmar pelo Gestor de Pedidos e ver o VEDDI mudar para Preparando.
3. Pedido: recusar com motivo antes de aceitar; outro: aceitar e solicitar cancelamento com motivo.
4. Pedido agendado: aceitar, iniciar preparo, pronto.
5. Pedido com entrega pelo iFood e pedido de retirada: conferir que o despacho não é oferecido.
6. Pedido pago em dinheiro com troco e pedido com cupom: conferir tela e comanda.
7. Negociação: abrir disputa pelo portal (se disponível) e responder por aceite, recusa e alternativa.

Pontos ainda não confirmados contra payload real (os pedidos de teste de 21/09 não trazem): chave do motivo no `CARF`, formato completo do `HSD`/`HSS` e dos corpos de resposta da negociação, `customer.documentNumber`, `extraInfo`, `benefits` e `cash.changeFor`. Conferir os logs do canal `ifood` durante o teste.

## Modelo de envio ao suporte

Não anexar os arquivos ao chamado. Subir cada vídeo separadamente, liberar acesso de visualização e preencher a data/hora real da execução (não usar a data de desenvolvimento do sistema).

| Cenário | Data e hora da execução, com fuso | client_id | Link do vídeo |
| --- | --- | --- | --- |
| Merchant 1 — Informações | Preencher | Preencher | Preencher |
| Merchant 2 — Interrupção | Preencher | Preencher | Preencher |
| Merchant 3 — Horários | Preencher | Preencher | Preencher |
| Catalog 1 — Categoria e item | Preencher | Preencher | Preencher |
| Catalog 2 — Complementos | Preencher | Preencher | Preencher |
| Catalog 3 — Alterações | Preencher | Preencher | Preencher |

## Nova tentativa — Merchant (05/10/2026)

Catalog aprovado conforme retorno do suporte. Reavaliar somente Merchant. A listagem agora possui a ação independente **Listar todas as lojas**, além da consulta conjunta já existente. Os testes locais usam respostas simuladas; registrar uma execução real e o vídeo antes de enviar o chamado.

Chamado atual: **34416700**, aberto em 05/10/2026. Chamado anterior: **33860676**. O suporte aguarda os links dos três vídeos de Merchant para avançar ao agendamento.

Texto da solicitação:

**Assunto:** Nova tentativa de homologação — módulo Merchant — VEDDI

Olá! Conforme o retorno da avaliação anterior, disponibilizamos uma ação independente para a chamada `GET /merchant/v1.0/merchants`, que lista todas as lojas autorizadas para o aplicativo, com paginação. Solicitamos uma nova tentativa de homologação do módulo Merchant. O módulo Catalog já foi aprovado, conforme retorno do suporte.

As gravações e os dados reais de execução ficam em [evidencias/merchant/videos/ENVIO_HOMOLOGACAO.md](evidencias/merchant/videos/ENVIO_HOMOLOGACAO.md). Publicar os três MP4 separadamente e enviar os links no chamado, com data/hora, fuso e client_id; os vídeos não devem ser anexados diretamente.
