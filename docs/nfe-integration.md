# Emissão fiscal para CPF e CNPJ

O modelo é decidido no servidor pela `FiscalEmissionPolicy` (versão gravada em `fiscal_notes.data.policy`), a mesma regra no pedido, no PDV, no Controle de notas (individual e lote), na pré-checagem e no job:

Revisão de 19/11/2026: NF-e também admite destinatário pessoa física, conforme a [SEF/MG](https://notafiscalmineira.fazenda.mg.gov.br/o-programa/duvidas-frequentes/sobre-os-documentos-fiscais/). O payload usa exclusivamente `cpf_destinatario` ou `cnpj_destinatario`, como documentado pela [Focus NFe](https://doc.focusnfe.com.br/reference/emitir_nfe). Identificação do comprador e modelo fiscal são decisões separadas.

| Situação | Documento |
| --- | --- |
| CPF em venda interna de varejo abaixo do teto | NFC-e por padrão, com opção de NF-e. NF-e exige nome completo e endereço fiscal. |
| CPF em operação interestadual, contribuinte/isento de IE ou a partir do teto da NFC-e | NF-e. Pedir NFC-e explicitamente bloqueia; não troca o modelo confirmado em silêncio. |
| Sem documento | NFC-e abaixo do limite de identificação. A partir de R$ 10 mil (Ajuste SINIEF 19/16, cl. 4ª, VII, "a"), bloqueado até informar CPF/CNPJ. Operação que exige NF-e também exige CPF/CNPJ. |
| CNPJ, filial sem **Permitir NFC-e com CNPJ** | NF-e (comportamento anterior). |
| CNPJ, filial liberada, venda presencial (balcão/comanda), operação interna, abaixo de R$ 200 mil, comprador não contribuinte com IE conferida | NFC-e com CNPJ; o operador pode escolher NF-e. |
| CNPJ com IE só sugerida pela consulta | NF-e, com aviso "Conferir IE para NFC-e". Pedir NFC-e sem conferir bloqueia. |
| CNPJ em entrega/retirada (não presencial), interestadual, contribuinte/isento de IE | NF-e. |

Limites em `config/fiscal.php` (`FISCAL_NFCE_IDENTIFICATION_THRESHOLD`, `FISCAL_NFCE_MAX_TOTAL`); o § 5º permite à UF reduzi-los. A escolha explícita do operador fica em `orders.fiscal_document_preference` e o job recebe o modelo conferido (`IssueFiscalNote::$confirmedModel`): se a política decidir outro modelo na execução, a emissão falha pedindo nova conferência. Um pedido só pode ter uma nota de venda ativa entre os dois modelos. O CPF do pagamento continua separado do destinatário fiscal.

CPF/CNPJ são normalizados por `FiscalRecipient::normalizeDocument()`, que preserva letras e zeros do CNPJ alfanumérico (12 posições alfanuméricas + 2 DV numéricos, módulo 11 com valor ASCII − 48). Chaves de acesso usam `App\Support\Fiscal\AccessKey`. Emitente com CNPJ alfanumérico é recusado na configuração até a homologação com o provedor.

## Operação

1. Aplicar as migrations e publicar o build normalmente.
2. Nas configurações fiscais de **cada filial**, conferir CNPJ, razão social do emitente, séries NFC-e e NF-e, regime, IE, CSC e certificado. Não existe substituição pela filial padrão: sem configuração própria, a emissão é bloqueada. O seletor **Ambiente fiscal da filial** permite homologar uma filial enquanto outras operam em produção. **Automático** preserva o comportamento anterior (`APP_ENV=production` emite em produção); fora do servidor de produção, toda seleção continua limitada à homologação. NF-e fica sempre habilitada. **Permitir NFC-e com CNPJ nesta filial** (desligado por padrão) libera a NFC-e com CNPJ conforme a política acima; ligar só depois da aprovação da contabilidade e da homologação. Salvar atualiza o emitente na Focus, incluindo `habilita_nfe` e as séries (`serie_nfce_*`/`serie_nfe_*`). Conferir a sequência existente com o provedor antes do piloto.
3. Preencher os dados fiscais dos produtos com a classificação fornecida pela contabilidade. O **cadastro geral** do produto vale para todas as filiais. Em **Dados fiscais do produto › Exceções por filial**, ligar a exceção só quando uma unidade tributar o produto de forma diferente e preencher apenas o que muda nela: campo vazio na exceção usa o cadastro geral (`App\Support\Fiscal\ProductFiscalProfile`). Ao salvar, valores iguais ao cadastro geral saem da exceção, e exceção sem diferença deixa de existir. Campos adicionais (alíquotas) acompanham o CST: alíquota de ICMS/PIS/COFINS do cadastro geral só é herdada se a filial não troca o CST daquele imposto. Exceção de outro regime (CST de 2 dígitos x CSOSN de 3 dígitos em relação ao cadastro geral) vale sozinha, sem herdar nada, e precisa de todos os campos. CST/CSOSN incompatível com o regime bloqueia NF-e e NFC-e antes do envio. Para NF-e não são usados os valores de classificação padrão da NFC-e. Operações interestaduais exigem CFOP interestadual explícito.
4. No PDV, ao marcar “Emitir e imprimir nota fiscal”, aparece o campo “CPF/CNPJ na nota” (opcional nas operações permitidas, sem exigir cliente cadastrado; com cliente selecionado vem preenchido para o caixa conferir ou apagar). CPF permite escolher entre NFC-e e NF-e quando os dois modelos são elegíveis; operações que exigem NF-e já indicam esse modelo. Para NF-e com CPF, “Completar ou conferir dados para NF-e” abre nome completo, endereço e situação da IE. CNPJ emite NF-e (ou NFC-e com CNPJ, quando a filial está liberada e o caixa confere a IE): razão social e endereço vêm do cadastro do cliente ou da consulta pública do CNPJ (`CnpjLookupInterface`, implementada com BrasilAPI e cache de um dia). Não há consulta pública de CPF. Dados de outro documento são descartados ao trocar CPF/CNPJ. Sem emissão, a venda não trava por pendência fiscal. “Salvar no cadastro” grava o destinatário no cliente. No checkout do chat, “Alterar dados fiscais” permite cadastrar dados tanto de CPF quanto de CNPJ, sem prometer um modelo antes de avaliar a operação.
5. Concluir o pagamento. No PDV (balcão e comanda) a nota sai na hora quando o caixa marca “Emitir e imprimir nota fiscal”; a NFC-e imprime assim que autoriza e a NF-e fica com o DANFE no pedido. Se a emissão falhar, o PDV avisa na hora. A emissão usa a cópia do destinatário salva no pedido. Dados incompletos não são convertidos em NFC-e. Com a **emissão automática** ligada na filial (seção abaixo), a marcação já vem ligada e os demais pedidos do chat/PDV recebem a NFC-e sozinhos.
6. Pedidos sem nota (caixa não marcou, chat sem emissão automática, iFood, agendados, pagos depois, NF-e) ficam em **Fiscal › Controle de notas** (`/admin/fiscal/controle`). Lá dá para filtrar (período, situação fiscal, canal, pagamento, filial, busca), selecionar até 200 pedidos e emitir em lote. Antes de enfileirar, a pré-checagem (`FiscalNoteService::preflight`) separa os pedidos prontos dos que têm pendência. O lote roda via `Bus::batch` na fila `low` (1 worker em produção, então não segura a fila `default`) e gera uma única notificação no fim. Nota emitida depois sai com a data da emissão, não da venda.
7. Na listagem fiscal (Notas Fiscais), consultar, baixar documentos, cancelar ou enviar carta de correção. Inutilizações são feitas por emitente, série e faixa, com histórico e bloqueio de repetição de faixas autorizadas/pendentes.

Alterações no cadastro fiscal do cliente não alteram o destinatário de pedidos já criados. No pedido administrativo é possível corrigir o destinatário antes de existir nota ativa. Pedidos antigos, sem cópia fiscal, usam os dados disponíveis do cliente na primeira tentativa. A classificação fiscal dos produtos é consultada novamente a cada tentativa de emissão, inclusive em pedidos antigos. Campo que falta no perfil efetivo (exceção + cadastro geral) é pendência do cadastro geral, que corrige todas as filiais de uma vez; só exceção de outro regime, ou valor próprio inválido, é corrigida na filial. A mensagem de pendência identifica o produto pelo número e diz onde corrigir. O cartão do pedido mostra as pendências de agora (a mensagem da última tentativa, `orders.fiscal_error`, só aparece quando não há pendência atual), e o Controle de notas mostra como "Não enviada" a tentativa que parou antes do envio. Notas existentes são migradas como NFC-e.

## Preenchimento e emissão rápida

O pedido tem prioridade sobre os cadastros. Ao informar um CPF/CNPJ no painel ou no PDV, `FiscalRecipientAutofill` completa os campos vazios com os dados fiscais do mesmo documento na mesma empresa, inclusive os de notas anteriores autorizadas. CPF com cadastro fiscal parcial pode aproveitar nome e endereço do próprio cliente quando o documento coincide. O contato de uma empresa não substitui a razão social: CNPJ novo usa a consulta pública quando necessário. Dados já completos dispensam essa chamada externa. O chat público só reutiliza os dados do próprio cliente, sem pesquisar cadastros ou notas de outros compradores.

CEP com oito dígitos completa logradouro, bairro, município e UF pela integração ViaCEP já utilizada pelo sistema. A consulta tem cache de um dia e limite de três segundos; se falhar, o endereço continua editável. Número, complemento e situação da IE não são inventados. “Sem número” é uma escolha explícita do operador.

No pedido, escolher NF-e abre os dados do comprador quando necessário. O formulário destaca nome, CEP e número; endereço preenchido e campos opcionais ficam disponíveis para conferência em uma seção recolhível. “Salvar e emitir” confirma os dados e enfileira o modelo exibido numa ação. A prévia usa o rascunho, e o servidor confere novamente o modelo antes do envio. No PDV, dados conhecidos dispensam redigitar o formulário.

O PDV consulta a autorização imediatamente, mesmo sem conexão em tempo real. Enquanto a nota estiver pendente, consulta novamente após 0,5, 1, 2, 5 e 10 segundos e depois a cada 15 segundos, até o limite de 15 minutos. Consulta e evento usam a mesma trava para evitar impressão duplicada. NF-e avisa que o DANFE está disponível; NFC-e autorizada solicita a impressão configurada.

A tela do pedido também acompanha a emissão enfileirada, por eventos e consulta a cada dois segundos enquanto houver espera, até o limite de 15 minutos. Autorização libera a impressão sem recarregar a página, e falhas aparecem no próprio pedido. O bloco independente de sugestões de vendas mantém seu estado durante essas atualizações para não interromper a digitação ou o acompanhamento fiscal.

Testes JavaScript do acompanhamento: `npm run test:js`. Testes de preenchimento e emissão: `php vendor/bin/pest tests/Feature/Fiscal/FiscalRecipientAutofillTest.php`.

## Emissão automática de NFC-e

Desligada por padrão. Liga por filial em **Fiscal › Configurações** (“Emitir NFC-e automaticamente nesta filial”, `company_fiscal_configs.auto_issue_enabled`). Ligar grava `auto_issue_enabled_at`: pedidos criados antes nunca saem sozinhos.

Regra única em `App\Services\Fiscal\FiscalAutoIssuePolicy`. A NFC-e sai quando tudo vale:

| Condição | Por quê |
| --- | --- |
| Configuração da filial habilitada, chave ligada e disjuntor fechado (`CompanyFiscalConfig::autoIssueActive()`) | Filial sem emitente não emite. |
| Pedido do chat ou do PDV | iFood, 99Food e Keeta ficam no Controle até a contabilidade confirmar forma de pagamento e intermediador (`indicador_intermediario`, `cnpj_intermediario`, `id_intermediario`). |
| Status em `Order::FISCAL_ISSUABLE_STATUSES`, sem esperar aceite (`waitsForAcceptance()`) e com pagamento recebido (`needsPdvPaymentConfirmation()`) | Chat e iFood ficam `paid` antes do aceite; "a receber" e "pagar na entrega" avançam de etapa antes de receber; agendado fica `scheduled` até a hora. Comanda aberta espera o fechamento. |
| Nenhuma nota tentada antes e `orders.fiscal_auto_attempted_at` vazio | Uma tentativa por pedido; falha nunca reemite sozinha. |
| `FiscalEmissionPolicy` decide NFC-e (modelo 65) | CNPJ/NF-e e documento a conferir ficam no Controle. |
| Total e itens com valor | Brinde sem valor precisa da contabilidade (CFOP de bonificação). |

Gatilhos: `OrderFiscalObserver` (pedido criado ou com status/aceite alterado) e `PaymentFiscalObserver` (pagamento recebido depois), depois do commit, enfileiram `AutoIssueFiscalNote` na fila `low` com `fiscal.auto_issue.delay_seconds` de espera (padrão 15 s). O job é único por pedido (`ShouldBeUnique`), reavalia a política, reserva o pedido com UPDATE condicional em `fiscal_auto_attempted_at` e emite com `automatic = true` em `fiscal_notes.data`. A varredura `php artisan fiscal:auto-issue` (a cada 5 min no scheduler; `--dry-run` só conta) pega o que escapou dos observers nos últimos `fiscal.auto_issue.lookback_days` (padrão 3).

PDV: com a chave ligada, “Emitir e imprimir nota fiscal” vem marcado (a chave é relida a cada venda, então a pausa do disjuntor vale no turno aberto). Numa venda paga na hora o PDV decide a nota e grava `orders.fiscal_auto_skip`: marcado, emite e imprime como antes; desmarcado, o pedido fica sem nota. A automática não repete nem contraria o caixa. Venda a receber ou agendada recebe a NFC-e automática quando for paga.

Falhas (`FiscalAutoIssueMonitor`): pendência antes do envio grava `orders.fiscal_error`; falhas e rejeições automáticas (só notas com `automatic`, nunca as do caixa) geram um aviso só por hora no sino (`fiscal_auto_failed`), com link para "Com erro" no Controle. `fiscal.auto_issue.breaker_threshold` notas automáticas rejeitadas seguidas na mesma filial (padrão 3) pausam a emissão automática dela (`auto_issue_paused_at`/`auto_issue_pause_reason`) e avisam (`fiscal_auto_paused`); retomar em Configurações com "Já corrigi, retomar" grava `auto_issue_resumed_at`, e rejeições anteriores deixam de contar. Pedidos que ficaram sem nota durante a pausa saem pela varredura depois da retomada, dentro do `lookback_days`.

Pedido cancelado entre a avaliação e o envio não recebe nota: `FiscalNoteService` confere o pedido travado antes de criar a nota (vale também para a emissão manual). Timeout/5xx deixa a nota `pending` para a `ReconcilePendingFiscalNotesJob`.

Cancelamento: pedido que vira `cancelled`/`refunded` com nota ativa (automática ou manual) enfileira `CancelFiscalNotesOfCancelledOrder` (fila `default`), que cancela a nota com justificativa padrão. Nota ainda `pending` é consultada e o job volta em 2 min. Se o cancelamento não sair (prazo da UF vencido, rejeição), avisa no sino e o pedido aparece no filtro **Pedido cancelado com nota ativa** do Controle, para a contabilidade decidir (devolução).

Envio à Focus (vale também para a emissão manual): pagamento dividido sai com uma forma por pagamento na NFC-e (antes só na NF-e) quando a soma bate com o total; forma 99 leva `descricao_pagamento` (ex.: "Pago pelo iFood").

Antes de ligar numa filial: homologar NFC-e na Focus real, conferir CSC/ID Token de produção e credenciamento NFC-e na SEFAZ (rejeição 781 abre o disjuntor), e conferir que a numeração da série já usada está correta. Migration: `2026_11_19_000001_add_fiscal_auto_issue_fields`.

## Emissão pelo pedido e pendências

O botão do pedido abre um modal único: **comprador** (CPF/CNPJ; CNPJ novo consulta a empresa e abre a conferência dentro do modal; salvar marca a IE como conferida e não emite), **documento** (escolha NFC-e/NF-e quando a política permite) e **conferência** (pendências agrupadas por emitente, documento, destinatário e produtos). Cada produto pendente mostra nome, número, campos faltando e o cadastro usado (geral ou perfil da filial). Quem tem `products.update` e papel do cardápio vê **Corrigir dados**, que abre o produto no cadastro certo e volta ao pedido depois de salvar; quem só emite vê a pendência sem link. O Controle de notas mostra as mesmas pendências agrupadas.

NF-e e NFC-e com CNPJ exigem o cadastro fiscal completo do produto; só a NFC-e de consumidor (CPF ou sem documento) mantém os padrões históricos (NCM 21069090, CFOP 5102, CSOSN 400).

## Tributação em lote

Em **Fiscal › Tributação em lote** (`/admin/fiscal/tributacao`, papel do cardápio + `products.update`): modelos fiscais da empresa (regime, UF, operação, vigência) e aplicação com prévia em produtos selecionados, no cadastro geral (todas as filiais) ou nas exceções de uma filial. Padrão "só preencher vazios"; substituir exige confirmação. Na filial, "vazio" e pendências olham o perfil efetivo, valor igual ao do cadastro geral não vira exceção e só produtos que já têm exceção nela são alterados. No cadastro geral, a prévia avisa onde uma exceção de filial continua valendo por cima. A prévia mostra atual → proposto, pendências NF-e resolvidas/restantes e avisos condicionais (CEST em ST, CFOP 5405, alíquotas ICMS/PIS/COFINS); a gravação confere que produtos e modelo não mudaram desde a prévia. Cada aplicação fica no histórico (usuário, data, valores anteriores) e pode ser desfeita onde o cadastro não foi alterado depois. Gerente de filial aplica só no perfil da própria filial e não cria modelos. Nada emite nota; notas já emitidas não mudam.

Migrations: `2026_11_16_000003_add_fiscal_emission_policy_fields` e `2026_11_16_000004_create_fiscal_templates_tables`.

## Lote mensal de XML para o escritório

Em **Fiscal › Notas Fiscais › Lote mensal para o escritório**, selecione competência, filial e ambiente e clique em **Gerar lote mensal**. O mês anterior e o ambiente de produção são os padrões. O ZIP reúne os XMLs de NF-e e NFC-e autorizadas ou canceladas, organizados por CNPJ, modelo e situação, com `relatorio.csv` (UTF-8, separado por ponto e vírgula) e `LEIA-ME.txt`. Não há envio automático de e-mail.

A competência usa `data_emissao` do payload enviado ao provedor; para registros antigos, usa a resposta do provedor ou a criação da nota. A data do XML é conferida antes de incluí-lo. A venda pode pertencer a outro mês. O relatório traz número, série, chave, emitente, situação, data, valor do XML e eventuais pendências. O valor de uma nota cancelada continua no relatório identificado como cancelado; a coluna não representa faturamento líquido.

A exportação exige `fiscal.view` e o módulo habilitado. Gerentes só geram e baixam lotes da própria filial. Homologação e produção ficam separadas. Notas antigas sem ambiente registrado aparecem como pendência, sem presumir que sejam produção. Falha de download, XML malformado ou divergência de chave, emitente, modelo, ambiente, protocolo ou mês resulta em **Lote incompleto**, com a pendência no CSV. O conteúdo e a correspondência do documento são conferidos; não há validação criptográfica da assinatura nem validação integral pelo XSD da SEFAZ.

Este lote inclui XMLs das notas. XMLs de eventos de cancelamento, carta de correção e inutilização devem ser obtidos separadamente no provedor; essa limitação aparece na tela e no arquivo `LEIA-ME.txt`.

Aplicar a migration `2026_11_13_000002_create_fiscal_exports_table.php` e manter o worker da fila `low` ativo. Cada XML é preparado em um job separado e o ZIP é montado após o lote, inclusive quando um job falha. A tela atualiza os últimos cinco lotes enquanto estiverem em processamento. Os arquivos ficam no disco privado `local`, com download autenticado; o diretório `storage/app/private` deve ser persistente, compartilhado entre aplicação e workers e incluído nos backups. O `cloud.yaml` declara esse diretório junto ao armazenamento público. PHP precisa das extensões SimpleXML e ZIP.

Se o lote permanecer em **Preparando**, confira os logs e a conexão do worker ao banco. Depois de alterar `.env` ou a conexão do banco, reinicie os processos de fila: `php artisan queue:restart` e `php artisan horizon:terminate`. Se não houver supervisor que reinicie o Horizon, inicie `php artisan horizon` novamente. Um worker antigo pode continuar usando outra conexão, consumir o job e falhar antes de registrar seu resultado ou atualizar o batch.

Para recuperar um lote interrompido após corrigir a conexão, execute `php artisan fiscal:resume-export ID`. O comando cancela batches antigos ainda abertos, reaproveita resultados existentes, prepara os XMLs restantes e conclui o ZIP no próprio processo, sem depender da fila. Não emite notas. A recuperação tem trava contra execuções simultâneas e respeita a empresa, a filial e o ambiente gravados no lote. Jobs de batches cancelados são ignorados e os novos batches cancelados não disparam a finalização.

## Segurança e recuperação

- Referência UUID persistida antes da requisição à Focus.
- Trava no pedido protege emissões concorrentes e alterações concorrentes do destinatário.
- Timeout e HTTP 5xx na emissão mantêm a nota pendente; não liberam nova emissão.
- Consulta, cancelamento e webhook usam o modelo, o ambiente e a configuração associados à nota.
- O CNPJ do emitente é vinculado à configuração. Divergências no cadastro bloqueiam emissão; o CNPJ fica protegido no servidor depois do cadastro remoto ou da primeira nota, independentemente do que o navegador enviar.
- Novas notas preservam razão social, CNPJ e endereço do emitente para reimpressão térmica e PDF. Notas antigas usam o CNPJ salvo ou identificado na chave de acesso para localizar o emitente original.
- Gerentes de filial só acessam configurações, documentos, inutilizações e histórico da própria filial.
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

Antes de liberar a emissão real de cada filial, homologar seu CNPJ habilitado: autorização, rejeição, cancelamento, carta de correção, inutilização, totais com descontos/frete e operações interestaduais do perfil atendido. O habilitador de emissão da filial controla os dois modelos; NF-e não possui habilitador separado.

## Liberação de múltiplas filiais

1. Aplicar `2026_11_10_000001_isolate_branch_fiscal_settings.php` antes de iniciar os novos processos da aplicação. A migration adiciona campos sem excluir dados existentes, preenche CNPJ/razão social a partir do cadastro atual e mantém o modo automático das configurações antigas. Perfis de produtos existentes continuam no cadastro geral.
2. Limpar/recompilar as views no ambiente de destino (`php artisan view:clear`, depois o cache habitual do deploy). Recompilar os assets (`npm run build`) e reiniciar os workers pelo procedimento de deploy; jobs antigos não devem continuar emitindo com o resolver anterior.
3. Configurar **todas** as filiais que emitem. Uma unidade que dependia da configuração padrão precisará de configuração própria. A preferência de filial padrão passa a escolher apenas o formulário inicialmente exibido.
4. Conferir a razão social de cada CNPJ, certificado, tokens dos dois ambientes, IE, regime, séries e a numeração já usada na Focus. Em produção, conferir CSC e seu identificador por emitente. Não reiniciar sequências existentes.
5. Para produtos compartilhados entre regimes distintos, preencher a exceção completa de cada filial do outro regime. A migration `2026_11_17_000001_compact_branch_fiscal_profiles.php` tira das exceções existentes os valores iguais ao cadastro geral (o dado usado na nota não muda; exceção de outro regime fica inteira). Os campos adicionais JSON não podem sobrescrever CST/CSOSN, origem e situações de PIS/COFINS; esses valores devem estar nos campos próprios.
6. Manter a nova filial em **Homologação**, validar NF-e/NFC-e, documentos, impressão, consulta, cancelamento e webhook com o provedor real. Só então selecionar **Produção**, sem mudar o ambiente das outras filiais. A suíte automatizada usa respostas simuladas e não comprova habilitação na SEFAZ, validade do certificado ou autorização tributária de um CNPJ real.
7. Manter scheduler, workers e backups do disco privado ativos. Se precisar suspender a liberação, desabilitar a emissão da filial ou retornar novas emissões à homologação. Notas existentes preservam seu ambiente original para consulta/cancelamento. Não reverter a migration após configurar perfis por filial: seu `down()` remove esses perfis e os novos campos.

Referências: [Emissão](https://doc.focusnfe.com.br/reference/emitir_nfe), [Consulta](https://doc.focusnfe.com.br/reference/consultar_nfe), [Cancelamento](https://doc.focusnfe.com.br/reference/cancelar_nfe), [Carta de correção](https://doc.focusnfe.com.br/reference/emitir_carta_correcao), [Inutilização](https://doc.focusnfe.com.br/reference/inutilizar_numeracao), [Campos NF-e](https://campos.focusnfe.com.br/nfe/NotaFiscalXML.html).
