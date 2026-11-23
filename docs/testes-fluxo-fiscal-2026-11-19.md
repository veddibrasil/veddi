# Testes do fluxo fiscal — 19/11/2026

O fluxo foi exercitado no navegador, do login à emissão, retorno de autorização e documentos, com banco SQLite isolado e respostas simuladas de CNPJ, CEP e Focus NFe. As requisições passaram pelas telas, componentes Livewire, fila `database` com worker real, webhook, autorização de acesso e controladores reais do Veddi.

## Resultado consolidado

| Verificação | Resultado |
| --- | --- |
| Suíte PHP completa | 3.419 passaram; 2 ignorados |
| Testes JavaScript de autorização e impressão | 6 passaram |
| Navegador: pedido, preenchimento e celular | 19 verificações passaram |
| Navegador: PDV, rejeição, documentos e cancelamento | 11 verificações passaram |
| Jobs no worker de teste | Sem falhas |
| Build Vite e formatação PHP do fluxo | Aprovados |

As 30 verificações de navegador terminaram sem erros JavaScript ou respostas HTTP 5xx. A correção de isolamento do bloco lazy de sugestões foi coberta novamente pelos cenários de digitação rápida e pelos 100 testes PHP do conjunto de pedido/emissão.

## Preenchimento mínimo no fluxo validado

| Cenário | Dados a digitar | Confirmação |
| --- | --- | --- |
| NFC-e sem identificar o comprador | Nenhum dado do destinatário | Emitir NFC-e |
| NF-e para CPF conhecido | CPF, quando ainda não estiver preenchido | Escolher NF-e e emitir; dados conhecidos são reaproveitados |
| NF-e para CPF novo | CPF, nome, CEP e número | Salvar e emitir NF-e |
| CNPJ novo | CNPJ | Conferir os dados e a IE; salvar e emitir |
| CNPJ com dados fiscais completos na loja | CNPJ, quando necessário | Reaproveitar dados sem repetir consulta externa |

O CEP completa logradouro, bairro, município e UF. A consulta de CNPJ fornece razão social, endereço e e-mail quando disponíveis. Dados do pedido têm prioridade; dados fiscais não são substituídos por informações de outro documento ou partes de outro endereço. Número ausente e inscrição estadual exigem preenchimento ou conferência. Não há consulta pública de dados pessoais pelo CPF.

## Cobertura no navegador

- Login do operador e acesso às telas reais.
- NFC-e sem documento, autorização e PDF do cupom.
- Autorização e liberação da impressão na própria tela do pedido, sem recarregar.
- Clique duplo sem duplicação de nota.
- CNPJ novo com sete campos preenchidos automaticamente e emissão em uma confirmação.
- Nota pendente e autorização recebida pelo webhook autenticado.
- NF-e para CPF conhecido sem redigitar endereço.
- NF-e para CPF novo com nome, CEP e número digitados em sequência.
- Campo `cpf_destinatario` no payload e ausência de `cnpj_destinatario` na NF-e para pessoa física.
- Documento inválido bloqueando a emissão.
- Fechamento por Escape e formulários em celular com 390 px de largura.
- Rejeição fiscal com motivo visível para correção.
- Download autenticado de XML e DANFE em PDF.
- Cancelamento com justificativa e confirmação do provedor.
- Venda no PDV com NF-e e dados fiscais reaproveitados.
- Aviso de autorização no PDV com a conexão em tempo real desligada.
- CEP indisponível com alternativa de preenchimento manual.
- Ausência de erros JavaScript e respostas HTTP 5xx nas rodadas aprovadas.

## Regressões automatizadas

Os testes PHP verificam também isolamento entre empresas, restrição do preenchimento no chat público, prioridade dos dados fiscais existentes, reaproveitamento de notas anteriores autorizadas, ausência de número inventado e preservação de endereços diferentes. Os testes JavaScript verificam consulta imediata, espera por nota pendente, falha, cancelamento da espera e concorrência entre consulta e evento sem duplicar a impressão.

Comandos executados:

```sh
RANDFILE=/private/tmp/veddi-fiscal-tests-openssl.rnd php -d memory_limit=1024M vendor/bin/pest --compact
npm run test:js
npm run build
```

A formatação dos arquivos PHP do fluxo foi verificada com Pint. O `RANDFILE` temporário permite gerar os certificados de teste sem escrever no diretório pessoal.

## Evidências locais

- [Resultado dos cenários de pedido](/private/tmp/veddi-fiscal-flow/browser-results.json).
- [Resultado dos cenários de PDV e documentos](/private/tmp/veddi-fiscal-flow/pdv-browser-results.json).
- [Resultado PHPUnit/JUnit](/private/tmp/veddi-fiscal-flow/pest-results.xml).
- [CNPJ preenchido no pedido](/private/tmp/veddi-fiscal-flow/shots/cnpj-preenchido.png).
- [CPF novo com endereço preenchido pelo CEP](/private/tmp/veddi-fiscal-flow/shots/cpf-novo-cep.png).
- [Formulário preenchido no celular](/private/tmp/veddi-fiscal-flow/shots/mobile-cnpj-preenchido.png).
- [Autorização da NF-e no PDV](/private/tmp/veddi-fiscal-flow/shots/pdv-nfe-autorizada.png).

Scripts temporários: `/private/tmp/playwright-test-fiscal-flow.js` e `/private/tmp/playwright-test-fiscal-pdv-flow.js`. Servidor e fixtures de teste: `/private/tmp/veddi-fiscal-flow/`.

## Limites da validação

O provedor fiscal, a consulta de CNPJ e a consulta de CEP foram simulados; os tempos observados não representam latência ou disponibilidade dos serviços reais. O teste de impressão verifica a solicitação de impressão e a geração/entrega do PDF. Homologação com certificado da filial, autorização real na SEFAZ e impressão em equipamento físico continuam sendo validações externas necessárias.
