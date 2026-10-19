# NF-e para destinatários empresariais

A seleção é feita no servidor pelo documento fiscal do pedido: CNPJ seleciona modelo 55 (NF-e); CPF ou ausência de documento seleciona modelo 65 (NFC-e). Um pedido só pode ter uma nota de venda ativa entre os dois modelos. O CPF do pagamento continua separado do destinatário fiscal.

## Operação

1. Aplicar as migrations e publicar o build normalmente.
2. Nas configurações fiscais da filial, habilitar NF-e e conferir a série, o regime, a IE, o certificado e o ambiente. Salvar atualiza a habilitação do emitente na Focus. A numeração é controlada pela Focus; conferir a série e a sequência existente com o provedor antes do piloto.
3. Preencher os dados fiscais dos produtos com a classificação fornecida pela contabilidade. Para NF-e não são usados os valores de classificação padrão da NFC-e. Operações interestaduais exigem CFOP interestadual explícito.
4. No PDV, ao marcar “Emitir e imprimir nota fiscal”, aparece o campo “CPF/CNPJ na nota” (opcional, não depende de cliente cadastrado; com cliente selecionado vem preenchido para o caixa conferir ou apagar). CPF ou vazio emite NFC-e. CNPJ emite NF-e: razão social e endereço vêm do cadastro do cliente ou da consulta pública do CNPJ (`CnpjLookupInterface`, implementada com BrasilAPI e cache de um dia), e o caixa confere em “Conferir dados”. Situação da IE começa como “não contribuinte”. Se a consulta falhar, o formulário abre para preenchimento manual. Os dados fiscais só são validados quando a nota é emitida na hora; sem emissão, a venda nunca trava por pendência fiscal. “Salvar no cadastro” grava o destinatário no cliente. No checkout do chat, continua valendo “Alterar dados fiscais”.
5. Concluir o pagamento. A emissão **não é automática**: no PDV (balcão e comanda) ela só acontece quando o caixa marca “Emitir e imprimir nota fiscal”; a NFC-e imprime assim que autoriza e a NF-e fica com o DANFE no pedido. Se a emissão falhar, o PDV avisa na hora. A emissão usa a cópia do destinatário salva no pedido. Dados incompletos não são convertidos em NFC-e.
6. Pedidos sem nota (caixa não marcou, chat, iFood, agendados, pagos depois) ficam em **Fiscal › Controle de notas** (`/admin/fiscal/controle`). Lá dá para filtrar (período, situação fiscal, canal, pagamento, filial, busca), selecionar até 200 pedidos e emitir em lote. Antes de enfileirar, a pré-checagem (`FiscalNoteService::preflight`) separa os pedidos prontos dos que têm pendência. O lote roda via `Bus::batch` na fila `low` (1 worker em produção, então não segura a fila `default`) e gera uma única notificação no fim. Nota emitida depois sai com a data da emissão, não da venda.
7. Na listagem fiscal (Notas Fiscais), consultar, baixar documentos, cancelar ou enviar carta de correção. Inutilizações são feitas por emitente, série e faixa, com histórico e bloqueio de repetição de faixas autorizadas/pendentes.

Dados do cadastro não alteram pedidos já criados. No pedido administrativo é possível corrigir o destinatário antes de existir nota ativa. Pedidos antigos, sem cópia fiscal, usam os dados disponíveis do cliente na primeira tentativa. Notas existentes são migradas como NFC-e.

## Segurança e recuperação

- Referência UUID persistida antes da requisição à Focus.
- Trava no pedido protege emissões concorrentes e alterações concorrentes do destinatário.
- Timeout e HTTP 5xx na emissão mantêm a nota pendente; não liberam nova emissão.
- Consulta, cancelamento e webhook usam o modelo, o ambiente e a configuração associados à nota.
- Webhooks repetidos/atrasados não regridem autorização ou cancelamento. Uma autorização recebida antes da resposta HTTP não é sobrescrita pela resposta pendente.
- Falha no cancelamento preserva a autorização e registra a tentativa no histórico.
- XML e DANFE são baixados por endpoint autenticado, armazenados no disco privado `local`, separados por empresa e nota. Downloads externos só podem usar o host HTTPS do provedor no ambiente correto; redirecionamentos são desabilitados.
- `ArchiveFiscalDocuments` roda de hora em hora para arquivar XML e DANFE. É necessário manter scheduler e worker ativos e incluir o disco privado nos backups.
- O histórico dos eventos guarda usuário, solicitação e resposta/protocolo disponibilizados pela Focus.

## Escopo e homologação

O fluxo atende NF-e de saída normal, para consumo final por empresa brasileira, vinculada a pedido. Não oferece seleção de entrada, exportação, devolução, ajuste ou complemento. CFOP e classificação tributária são configurados explicitamente; o sistema não determina a tributação de uma mercadoria por conta própria. Campos tributários adicionais são enviados conforme a configuração do produto e devem ser homologados para cada regime/UF/operação, inclusive os grupos da reforma tributária aplicáveis. Alterar alíquotas não equivale a uma validação fiscal pela contabilidade.

Após um resultado desconhecido de carta de correção ou inutilização, o registro pendente bloqueia repetição local. A reconciliação periódica e a ação “Consultar evento” verificam o resultado na Focus. A carta só é confirmada quando a consulta completa devolve o mesmo texto solicitado e o XML do evento; a inutilização exige correspondência de CNPJ, série, modelo e faixa. Se o provedor não fornecer evidência suficiente, o evento permanece pendente para conferência dos protocolos.

Canais que não fornecem dados empresariais só podem usar os dados efetivamente recebidos/salvos antes da emissão. A consulta pública de CNPJ existe só no PDV, para preencher o formulário que o caixa confere; nada é emitido sem essa conferência ficar visível no checkout.

## Validação

```sh
RANDFILE=/tmp/veddi-openssl-rand php -d memory_limit=1024M vendor/bin/pest
php vendor/bin/pint --dirty --test
npm run build
```

A suíte usa SQLite em memória e `Http::fake`, sem emissão real ou credenciais externas. A conferência visual usa Playwright com banco SQLite separado em `/tmp`.

Antes de liberar produção, homologar com um CNPJ de teste habilitado: autorização, rejeição, cancelamento, carta de correção, inutilização, totais com descontos/frete e operações interestaduais do perfil atendido. A configuração de NF-e começa desabilitada para preservar a operação existente até essa habilitação.

Referências: [Emissão](https://doc.focusnfe.com.br/reference/emitir_nfe), [Consulta](https://doc.focusnfe.com.br/reference/consultar_nfe), [Cancelamento](https://doc.focusnfe.com.br/reference/cancelar_nfe), [Carta de correção](https://doc.focusnfe.com.br/reference/emitir_carta_correcao), [Inutilização](https://doc.focusnfe.com.br/reference/inutilizar_numeracao), [Campos NF-e](https://campos.focusnfe.com.br/nfe/NotaFiscalXML.html).
