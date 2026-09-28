# Homologação iFood — Merchant, Catalog e Order

Preparação dos seis cenários enviados pelo suporte em 25/09/2026. Os testes automatizados usam respostas simuladas da API; não substituem a execução na loja de teste nem a comprovação no Portal do Parceiro.

## Antes de gravar

- Conectar a filial da loja de teste em **Configurações → Integração iFood** (`/admin/settings/ifood`). Conferir o Merchant ID.
- Conferir o `client_id` exibido na seção **Operação da loja no iFood**. Ele vem de `IFOOD_PARTNER_CLIENT_ID`; não mostrar o client secret, tokens ou arquivo `.env`.
- Usar o fuso da loja. A configuração `IFOOD_TIMEZONE` tem padrão `America/Sao_Paulo`; os campos de pausa usam esse fuso. O iFood interpreta início e fim da pausa no fuso da loja e ignora offset, então o sistema envia o horário como digitado (sem `-03:00`). O horário mostrado após cada operação inclui o deslocamento UTC.
- Manter o Portal do Parceiro aberto na mesma loja de teste e o relógio/data do computador visíveis.
- Manter o worker da fila em execução para a sincronização ao salvar produtos. A ação **Sincronizar cardápio agora** executa a sincronização diretamente e só mostra sucesso ao terminar. Na loja de teste ela falha com 409 enquanto a Coxinha e a Coca (produtos 65 e 66 da filial Centro) tiverem ids locais que não existem no iFood; nas gravações de 28/09 o item foi publicado pelo worker ao salvar o produto.
- Usar fotos JPG ou PNG de até 2 MB no formulário VEDDI. A sincronização também verifica o formato e o limite da API antes do upload.

## Vídeo 1 — Merchant: informações da loja

1. Selecionar a filial conectada.
2. Clicar **Consultar lojas, detalhes e disponibilidade**.
3. Mostrar todas as lojas vinculadas, os detalhes completos da loja da filial e as validações de disponibilidade.
4. Mostrar a loja correspondente no Portal do Parceiro.

## Vídeo 2 — Merchant: interrupção

1. Informar motivo, início e fim da pausa.
2. Clicar **Criar pausa no iFood**.
3. Clicar **Consultar pausas** e mostrar a pausa criada, incluindo datas.
4. Conferir a pausa no Portal do Parceiro; aguardar a propagação e atualizar a página se necessário.
5. Clicar **Remover pausa** e consultar novamente.
6. Conferir a remoção no Portal do Parceiro.

**Pausar integração** apenas suspende a conexão local; para este cenário usar os controles de **Pausas da loja**. Eles mantêm a integração ativa para continuar recebendo eventos.

## Vídeo 3 — Merchant: horários

1. Clicar **Consultar horários** para carregar a semana existente.
2. Clicar **Preencher sábado e domingo da homologação**. Isso preenche o formulário e mantém segunda a sexta; ainda é necessário salvar.
3. Conferir:
   - Sábado: 10:00, duração 540 minutos → 19:00.
   - Domingo: 09:00, duração 180 minutos → 12:00.
   - Domingo: 13:00, duração 180 minutos → 16:00.
   - Domingo: 17:00, duração 360 minutos → 23:00.
4. Clicar **Salvar horários no iFood**.
5. Clicar **Consultar horários** novamente e mostrar o retorno.
6. Conferir os turnos no Portal do Parceiro.

Salvar substitui a semana inteira; dias sem turnos ficam fechados. O sistema rejeita turnos sobrepostos, inclusive entre domingo e segunda.

## Vídeo 4 — Catalog: categoria e item

1. Criar a categoria **Teste Homologação** pelo cadastro de categorias ou pelo formulário de produto.
2. Criar **Produto Teste**, selecionar a categoria, informar preço e adicionar foto.
3. Marcar **Ativo**, **Disponível no iFood** e a filial conectada.
4. Salvar; o worker publica o item. **Sincronizar cardápio agora** também publica, desde que os demais itens da filial estejam consistentes com o iFood.
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
