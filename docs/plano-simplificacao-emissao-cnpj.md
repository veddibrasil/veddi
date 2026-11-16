# Plano de simplificação da emissão para CNPJ

Pesquisa e revisão técnica: 16/11/2026, America/Sao_Paulo.

Status: etapas 2, 3 e 4 implementadas e a parte de código das etapas 1 e 5 (política versionada e liberação por filial, desligada por padrão) em 16/11/2026, sem commit. Falta o que depende de terceiros: matriz aprovada pela contabilidade, homologação real com Focus/SEFAZ e classificação dos produtos. Nenhuma nota real foi emitida.

## Execução e validação (16/11/2026)

**Fontes conferidas:** Ajuste SINIEF 12/26 (revoga o 11/25, DOU 09/04/2026), página da Receita/RS, Ajuste 19/16 cl. 4ª (§ 4º, R$ 200 mil) e documentação Focus da NFC-e (`cpf_destinatario`, `cnpj_destinatario`, `nome_destinatario`, `indicador_inscricao_estadual_destinatario`). Correção ao plano: o inciso VII, "a", também exige CPF/CNPJ em NFC-e a partir de R$ 10 mil; entrou na política. Não conferidos verbatim: as regras do MOC/PR (rejeição por IE e por operação não destinada a consumidor final) e a regra dos DV na notícia da Receita; o DV alfanumérico foi validado pelo exemplo `12.ABC.345/01DE-35`.

**Implementado:** `FiscalEmissionPolicy` (versão `2026-11-16`) usada por pedido, PDV, Controle de notas, pré-checagem e job; flag `nfce_cnpj_enabled` por filial; preferência em `orders.fiscal_document_preference` e modelo conferido no job; payload NFC-e com os campos documentados; NF-e presencial como operação interna; CNPJ alfanumérico em destinatário, consulta, chave de acesso, recibo, impressão e lote mensal (emitente alfanumérico recusado com aviso); pré-checagem estruturada; modal único no pedido com link "Corrigir dados" e retorno; Tributação em lote com prévia, trava contra edição concorrente, histórico e desfazer. Detalhes em `docs/nfe-integration.md`.

**Decisões a confirmar com a contabilidade:** NFC-e com CNPJ só em balcão/comanda (retirada e entrega seguem NF-e até tratar o endereço da entrega do inciso VII, "c"); IE exigida = 9 e conferida; IE só sugerida mantém NF-e; NFC-e com CNPJ exige o cadastro fiscal completo do produto.

**Testes:** suíte completa verde (Pest, SQLite); 70 testes novos em `FiscalEmissionPolicyTest` e `FiscalTemplateTest`. E2E Playwright em servidor isolado com provedor simulado: 37/37 (modal com CNPJ alfanumérico, pendências 143/159, correção e retorno, lote, NFC-e e NF-e enfileiradas com modelo confirmado, PDV, caixa sem link, celular sem rolagem horizontal, Esc fecha o diálogo, sem erro JS/5xx).

**Fora do código, ainda abertos:** homologação Focus/SEFAZ (XML com o destinatário), NFC-e não presencial com endereço da entrega (lacuna já existente para CPF/sem documento desde 03/08/2026), grupos IBS/CBS, métricas da etapa 5 e conferência real dos produtos 143 e 159.

## Decisão recomendada

Separar a identificação do comprador da escolha do documento fiscal. O fluxo pretendido é **informar CNPJ → conferir empresa e documento → emitir**. A tributação deve estar preparada previamente, com correção das exceções na própria tela.

Para o piloto no Paraná, avaliar NFC-e com CNPJ para vendas elegíveis a consumidor final. Preservar a opção de NF-e para situações que a exijam e para pedidos expressos do comprador. A elegibilidade depende da operação, da filial e das regras vigentes; informar CNPJ, sozinho, não resolve essa decisão.

A afirmação anterior de que qualquer CNPJ sempre deve gerar NF-e estava incompleta: essa é a política atual do Veddi. A restrição nacional prevista no Ajuste 11/25 foi revogada pelo [Ajuste SINIEF 12/26](https://www.confaz.fazenda.gov.br/legislacao/ajustes/2026/AJ012_26), publicado em 09/04/2026. A [Receita Estadual do RS](https://atendimento.receita.rs.gov.br/ainda-e-permitido-emitir-nfc-e-para-destinatario-cnpj) confirma expressamente essa consequência. A conclusão para o produto é permitir uma política por operação, sujeita à validação estadual e à homologação.

## Skills utilizadas e limites da validação

- [find-skills](../.agents/skills/find-skills/SKILL.md): consulta ao catálogo e busca `skills find 'fiscal nfe'`. A busca não retornou resultados; nenhuma skill tributária externa foi instalada. Skills não foram usadas como autoridade fiscal.
- [UI/UX Design Review](/Users/guilhermejeske/.agents/skills/ui-ux-design-review/SKILL.md): revisão do screenshot e do código dos formulários, recuperação de erros, permissões, foco e complexidade da tarefa.
- [playwright](/Users/guilhermejeske/.agents/skills/playwright/SKILL.md): definição da validação de navegador e detecção de servidores locais. Nenhum servidor estava ativo; testes visuais/E2E do fluxo proposto permanecem por executar após a implementação.

A revisão de interface foi documental e pelo screenshot fornecido. Não constitui certificação WCAG nem teste de usabilidade com operadores reais.

## Evidências que mudam o plano

| Evidência | Consequência para o projeto |
| --- | --- |
| A SEFA/PR contempla identificação por CPF/CNPJ e endereço para entrega, nas perguntas 1470 e 1508 do [SAC NFC-e](https://atendimento.fazenda.pr.gov.br/sacsefa/portal/assuntosReferente/22). | NFC-e com CNPJ é uma alternativa concreta a validar para o piloto da filial. |
| O [Ajuste 19/16 consolidado](https://www.confaz.fazenda.gov.br/legislacao/ajustes/2016/AJ_019_16), cláusula quarta, VII, exige identificação em situações específicas e endereço nas operações não presenciais; o § 4º impede NFC-e a partir de R$ 200 mil. | As exigências devem variar por operação. Conferir também limites estaduais e testar os valores de fronteira. Não aplicar a simplificação de balcão indiscriminadamente ao delivery. |
| O [MOC online do Paraná](https://moc.sped.fazenda.pr.gov.br/NFe_NFCe/NFe_NFCe/RegrasDeValida%C3%A7%C3%A3o.html) contempla rejeições por operação interestadual, por operação que não seja destinada a consumidor final e por indicação de IE incompatível com NFC-e. | A seleção precisa conhecer a natureza e o local da operação e o enquadramento do destinatário. Não alterar o indicador de IE apenas para passar na validação. |
| A [API Focus de NFC-e](https://doc.focusnfe.com.br/reference/emitir_nfce) documenta `cnpj_destinatario` e `cpf_destinatario`. | Corrigir e testar o contrato do destinatário antes de liberar o novo fluxo. |
| A Receita iniciou a emissão de [CNPJ alfanumérico em 31/07/2026](https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2026/julho/receita-federal-gera-o-primeiro-cnpj-em-formato-alfanumerico). | Preservar letras na entrada, normalização, armazenamento, consulta e envio. |
| A [documentação oficial da BrasilAPI](https://github.com/BrasilAPI/BrasilAPI/blob/main/pages/docs/doc/cnpj.json) aceita CNPJ alfanumérico. | A consulta existente pode ser reaproveitada, mas o adaptador local precisa de atualização e teste. Não foi realizada consulta real de dados empresariais nesta pesquisa. |

Uma mudança próxima também deve entrar no checklist de liberação: a restrição de NF-e de saída referenciando NFC-e, salvo a exceção prevista na norma, tem efeitos programados para 14/12/2026, conforme [Ajuste 32/25](https://www.confaz.fazenda.gov.br/legislacao/ajustes/2025/ajuste-sinief-32-25) e [Ajuste 29/26](https://www.confaz.fazenda.gov.br/legislacao/ajustes/2026/AJ029_26). Por isso, o desenho não deve prometer “emitir cupom agora e converter em NF-e depois”.

## O que já existe no Veddi

| Parte | Implementação atual | O que aproveitar ou corrigir |
| --- | --- | --- |
| Seleção de modelo | `app/Services/Fiscal/FiscalRecipient.php::model()` escolhe 55 por comprimento numérico do documento. | Substituir a decisão isolada por uma política de emissão compartilhada entre tela, pré-checagem e job. |
| Consulta de CNPJ | `BrasilApiCnpjLookup` e os formulários de destinatário já preenchem razão social e endereço. | Reutilizar o serviço em uma experiência comum no pedido, PDV e controle fiscal. |
| Situação da IE | A consulta cadastral preenche `ie_indicator = 9` por padrão, sem consultar IE. | Tratar como informação a conferir, não como comprovação de não contribuinte. |
| Dados dos produtos | `FiscalNoteService::buildItems()` usa o perfil próprio da filial quando existente, substituindo o geral inteiro. | Preservar essa precedência e deixar a origem visível na tela. |
| Pendências | `preflight()` retorna uma mensagem; há agregação dos campos ausentes dos produtos, mas outras validações ainda interrompem no primeiro erro. | Evoluir para resultado estruturado, com mensagens, campos, produto e cadastro a corrigir. |
| Emissão | Job, trava de nota ativa, referência persistida, reconciliação e webhooks já existem. | Manter a proteção contra duas notas da mesma venda ao adicionar escolha de modelo. |
| Lotes | Controle fiscal já emite até 200 pedidos por lote. | Reutilizar a seleção e os jobs; não criar uma segunda rotina de emissão em massa. |
| Payload NFC-e | `FocusNfeService::buildPayload()` envia `CPF`/`CNPJ` no nível superior. | Adequar aos campos documentados e conferir o documento efetivamente presente no XML autorizado. |
| Escopo NF-e | `NfePayloadBuilder` fixa venda normal para consumidor final. | Revenda, industrialização, devolução e exportação precisam de evolução própria; não tratá-las como suportadas só por selecionar modelo 55. |

## Validação executada

1. Suíte existente: **250 testes aprovados, 884 asserções**, incluindo `tests/Feature/Fiscal` e `tests/Feature/Pdv/PdvFiscalDocumentTest.php`. Execução com SQLite e respostas HTTP simuladas.
2. Reprodução isolada, sem HTTP real: o CNPJ alfanumérico publicado pela Receita foi recusado por `FiscalRecipient::isValidDocument()` e classificado como modelo 65 após a remoção das letras. Isso confirma a necessidade de separar normalização, validação e seleção fiscal.
3. Reprodução isolada do contrato NFC-e, com HTTP simulado: o corpo gerado continha `CNPJ`, mas não `cnpj_destinatario`. A diferença para a documentação foi confirmada; ainda falta verificar o comportamento real do provedor e do XML. Não se concluiu que a API necessariamente rejeita o campo antigo.
4. Revisão do screenshot: a mensagem original era longa, repetida e associada ao campo de CPF/CNPJ apesar de apontar pendências dos produtos. A correção local feita anteriormente já separa o erro de emissão e identifica o cadastro utilizado.

Os testes atuais são uma base de regressão. Eles não comprovam suporte ao CNPJ alfanumérico, adequação tributária dos produtos ou autorização real pela SEFAZ.

## Etapas de implementação

### 1. Definir e homologar a política de emissão

Criar uma matriz por UF/filial, tipo de operação, presença/entrega, valor, finalidade da compra, condição do destinatário e solicitação de NF-e. Registrar a fonte, data de revisão e versão da política.

| Situação | Comportamento proposto |
| --- | --- |
| Venda interna, consumidor final, destinatário e operação elegíveis, sem pedido de NF-e | Oferecer NFC-e com CNPJ quando a filial estiver habilitada e homologada para essa política. |
| Comprador pede NF-e, dentro do escopo de venda a consumidor final | Selecionar NF-e e preencher os dados completos pela consulta/cadastro. |
| Operação interestadual ou limite de NFC-e atingido | Não oferecer NFC-e; validar a operação no fluxo NF-e suportado. |
| Situação da IE ou natureza da operação desconhecida | Pedir apenas a informação necessária para decidir; não presumir enquadramento pelo CNPJ ou pelo CNAE. |
| Revenda, industrialização ou operação fora do escopo atual | Informar a limitação e encaminhar para tratamento próprio; não forçar o builder atual de consumidor final. |
| Nota já pendente ou autorizada | Mostrar a nota existente e consultar seu estado; impedir emissão de outro modelo para a mesma venda. |

O endereço da sede do comprador, sozinho, não deve definir se a operação é interestadual: considerar retirada, entrega e os locais efetivos, conforme regras aplicáveis. Casos ainda sem dados suficientes ficam pendentes de conferência.

**Aceite:** matriz revisada com a contabilidade da filial e cenários de autorização/rejeição executados em homologação. A revogação da restrição por CNPJ não é autorização irrestrita para qualquer venda.

### 2. Corrigir documento, contrato e persistência da escolha

Centralizar reconhecimento de CPF/CNPJ e normalização que preserve letras e zeros iniciais. Validar os dígitos conforme a Receita, mantendo o suporte aos números existentes. Ajustar máscara, teclado e mensagens.

Criar um serviço de política fiscal que receba o contexto da operação e retorne modelo permitido, campos exigidos e motivos. Usá-lo no PDV, pedido, controle individual/lote e job; não manter decisões diferentes em cada tela.

Persistir no pedido a preferência explícita de documento e, antes do envio, a decisão confirmada com sua versão. O job deve revalidar as condições. Se elas mudarem após a confirmação, solicitar nova conferência em vez de emitir silenciosamente outro modelo.

Adequar o payload NFC-e aos nomes documentados, incluindo identificação, endereço quando exigido, presença, pagamentos e totais. Comparar a requisição com o XML de homologação, não somente com o status HTTP. Manter as referências e a exclusividade de nota ativa.

Na compatibilidade alfanumérica, verificar também emitentes, CNPJ nos documentos, chaves de acesso, downloads, recibos e exportação mensal. Há expressões numéricas nesses caminhos; a aceitação na tela, sozinha, é insuficiente. A liberação de emitente alfanumérico depende dessa verificação completa e do suporte do provedor.

**Aceite:** testes de contrato para CPF, CNPJ numérico e alfanumérico; modelo confirmado igual ao enviado; identificação presente no XML; tentativa concorrente bloqueada entre os dois modelos.

### 3. Unificar a tela de emissão e correção

Apresentar filial emitente, CNPJ do comprador, resumo da empresa, documento selecionado e situação de prontidão. Reaproveitar o destinatário já salvo e consultar quando necessário, sem sobrescrever silenciosamente dados conferidos.

Quando houver pendências, agrupar por destinatário, produtos e emitente. Cada produto deve indicar nome, número, campos ausentes e origem: cadastro geral ou perfil da filial. Oferecer “Corrigir dados” sem perder o contexto do pedido e “Salvar e verificar novamente”. Salvar a configuração não deve emitir a nota automaticamente.

Os campos tributários ficam acessíveis a quem tem permissão de produtos/configuração. Quem só emite pode conferir os dados e identificar a pendência, sem ganhar permissão de alterar tributação. O formulário de rotina não exige JSON.

A consulta de CNPJ precisa ter estados de carregamento, indisponibilidade e preenchimento manual. O resumo deve distinguir endereço fiscal do local de entrega. A confirmação da situação da IE pode ser reutilizada em próximas compras, com possibilidade de revisão.

**Aceite UX:** não perder valores após erro; erro anunciado uma vez e relacionado ao campo correto; foco no resumo/primeira pendência; diálogo operável por teclado; contraste, zoom e layout móvel verificados. Critérios derivados da skill de UX e das orientações W3C sobre [identificação](https://www.w3.org/WAI/WCAG22/Understanding/error-identification) e [recuperação de erros](https://www.w3.org/WAI/tutorials/forms/notifications/).

### 4. Preparar a tributação em lote

Implementar modelos fiscais reutilizáveis por empresa, com compatibilidade de regime, UF, operação e vigência. Categorias comerciais podem ajudar a filtrar produtos; o nome “bebidas” ou “salgados” não determina automaticamente uma classificação tributária comum.

Aplicar inicialmente como cópia explícita dos valores aprovados aos cadastros selecionados. Isso reaproveita `products.fiscal_data` e `branch_product.fiscal_data`, sem introduzir uma herança dinâmica adicional no primeiro lançamento. Atualizações posteriores do modelo não alteram produtos silenciosamente.

Antes de gravar, mostrar produtos afetados, filial, valores atuais/propostos e diferenças. Permitir preencher campos vazios; substituição de valores existentes deve ser uma escolha explícita. Respeitar o perfil inteiro da filial, sem completar seus campos com alíquotas do cadastro geral. Guardar usuário, data, origem e valores anteriores para recuperação dos cadastros.

Validar requisitos condicionais da tributação, além da presença de NCM/CST: CEST, alíquotas, bases, benefícios e grupos IBS/CBS quando aplicáveis. A compatibilidade com as versões vigentes de schemas/tabelas deve ser conferida na homologação; não preencher códigos genéricos para remover bloqueios.

**Aceite:** aplicação limitada à empresa/filiais autorizadas; prévia fiel ao que será salvo; proteção contra edição concorrente; alterações dos cadastros não modificam XMLs já emitidos. Uma simulação deve mostrar quais pendências foram resolvidas e quais permanecem.

### 5. Homologar e liberar por filial

Executar testes automatizados e cenários reais no ambiente de homologação. Iniciar por uma filial do Paraná, após fechar a matriz de operações com sua contabilidade. Habilitar o novo comportamento por configuração da filial; preservar as demais até sua validação.

Acompanhar tempo de emissão, campos digitados, correções repetidas, rejeições por motivo e notas pendentes. Se a política nova for desativada, respeitar as notas e tentativas já existentes; retornar ao fluxo anterior apenas nas próximas decisões elegíveis, com o modelo visível ao operador.

## Matriz mínima de testes

| Área | Cenários obrigatórios |
| --- | --- |
| Política | CNPJ elegível para NFC-e; NF-e solicitada; operação interestadual; finalidade não suportada; IE desconhecida/contribuinte; valores imediatamente abaixo, no limite e acima do limite estadual. |
| Documento | CPF válido/inválido; CNPJ numérico/alfanumérico; máscara; letras minúsculas; zeros iniciais; comprimentos inválidos; documento não pode mudar de tipo após normalização. |
| Dados empresariais | Cliente recorrente; sem cliente cadastrado; consulta lenta/falha; edição manual; IE e endereço confirmados; endereço fiscal diferente da entrega. |
| Produtos | Cadastro geral completo/incompleto; perfil próprio ativo/incompleto; correção na filial certa; nomes iguais com IDs diferentes; produtos sem vínculo; produtos de outra empresa. |
| Payload/XML | Campo de documento correto; só um identificador do destinatário; modelo/série/emitente; endereço/presença; totais com frete/desconto e pagamentos divididos; códigos efetivamente presentes no XML. |
| Concorrência e histórico | Dois cliques; dois operadores escolhendo modelos diferentes; timeout; webhook antecipado; job repetido; consulta antes de reemitir; mudança de configuração entre pré-checagem e execução. |
| Lotes e acesso | Aplicação fiscal em lote com prévia; isolamento entre empresas/filiais; gerente restrito; caixa sem permissão de editar; emissão de pedidos em lote usando a mesma política. |
| Playwright | Pedido, PDV, comanda e controle fiscal; desktop e móvel; teclado/foco; erro único; correção e retorno ao pedido; dados preservados; carregamento; bloqueio de clique repetido. |

Na implementação, ampliar os testes existentes em vez de criar fluxos paralelos: `NfeIntegrationTest`, `MultiBranchFiscalTest`, `FocusNfeServiceTest`, `FiscalNoteServiceTest`, `IssueControlTest` e `PdvFiscalDocumentTest`. Rodar os E2E com banco isolado e serviços simulados; fazer a homologação com provedor separadamente.

## Aplicação ao TRA-2026-42814

O diagnóstico anterior identificou o produto 143 usando perfil próprio de Arapongas sem PIS/COFINS e o produto 159 sem classificação fiscal. Esses dados devem ser conferidos novamente no momento da correção.

Antes de propor outro modelo para esse pedido, verificar: solicitação do comprador, finalidade da compra, condição fiscal do destinatário, local da operação e existência de nota pendente/autorizada. O CNPJ e as pendências, isoladamente, não comprovam elegibilidade para NFC-e.

Se a venda permanecer em NF-e, a tela proposta deve permitir corrigir o perfil da filial do produto 143 e o cadastro geral do produto 159, e executar uma nova pré-checagem. Nenhum CST, NCM ou alíquota foi escolhido ou alterado por esta pesquisa. A falta de dados fiscais de um produto continua precisando de resolução mesmo que a operação seja elegível para NFC-e.

## Ordem de entrega

1. Matriz fiscal do piloto, testes de contrato e suporte correto ao documento.
2. Política compartilhada, persistência da escolha e payload correto.
3. Tela única de emissão/pendências e consulta integrada.
4. Configuração fiscal em lote com prévia e histórico.
5. Homologação por filial, acompanhamento e expansão.

Condições ainda abertas para liberação: classificação aprovada dos produtos, elegibilidade das operações reais da filial e resultado dos testes com Focus/SEFAZ em homologação. A pesquisa confirmou a viabilidade da direção e os pré-requisitos técnicos; não confirmou autorização fiscal para um pedido específico.
