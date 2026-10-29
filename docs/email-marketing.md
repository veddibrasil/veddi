# E-mail marketing (super admin)

O super admin cria, segmenta, testa, agenda e dispara campanhas de e-mail para os **usuários das empresas** (`company_admin` e `branch_manager`), com métricas de entrega, abertura, clique e descadastro. Clientes finais dos restaurantes (`customers`) nunca entram: os dados são de cada empresa. Este documento é interno.

Em **Contatos externos**, crie uma lista e importe um CSV (e-mail, nome e empresa, separados por vírgula, ponto e vírgula ou tab) ou cole os contatos, um por linha. Só o e-mail é obrigatório. O botão **Criar campanha para esta lista** abre o editor com a lista selecionada, sem incluir usuários cadastrados.

Em **Colar lista de e-mails**, escolha **Só e-mails** para colar endereços separados por linhas, espaços, vírgulas ou ponto e vírgula. Use **Nome e empresa** para linhas de planilha/CSV com dados adicionais. A conferência mostra novos contatos, e-mails já na lista, repetidos e itens para revisão antes de adicionar. A prévia não grava dados; importações com itens ignorados mantêm o texto para correção.

No passo **Público**, a opção **Contatos externos (possíveis clientes)** permite selecionar listas externas. Marque **Incluir também os donos das empresas** para somar os administradores (`company_admin`), se desejar. Campanhas de listas já salvas mantêm a escolha anterior. O total da prévia conta e-mails únicos, e cada endereço recebe uma vez por campanha, mesmo que apareça em várias listas ou empresas. Donos seguem as preferências atuais de consentimento, descadastro e supressão; a cópia de um e-mail importado não contorna essas preferências.

Telas: `/superadmin/marketing` (Campanhas, Templates, Segmentos, Automações, Supressões).

## Fluxo de uma campanha

1. **Conteúdo** (wizard, passo 1): nome, assunto, pré-cabeçalho, remetente e blocos (cabeçalho, texto, imagem, botão, divisor, rodapé). Pode começar de um template. O HTML é gerado e sanitizado no servidor (`TemplateRenderer` + `EmailHtmlSanitizer`); o conteúdo nunca é compilado como Blade.
2. **Público** (passo 2): filtros da própria campanha ou um segmento salvo ("sem pedidos há N dias" só pega empresa cadastrada há pelo menos N dias); contagem ao vivo já sem supressões, opt-out e (com `MARKETING_REQUIRE_OPT_IN=true`) sem quem não deu aceite. Teste A/B de assunto opcional.
3. **Revisão** (passo 3): preview desktop/celular com dados de uma empresa real, envio de teste (até 5 e-mails) e checklist obrigatório: conteúdo, variáveis válidas, remetente de domínio verificado, link de descadastro, rodapé legal, público não vazio e teste enviado com o conteúdo atual.
4. **Envio** (passo 4): agora ou agendado (horário de Brasília). O público é recalculado no disparo.

Status (`EmailCampaignStatus`): `draft` → (`scheduled`) → `building` → `sending` → `sent`; fora disso `paused`, `cancelled` e `failed`. Editar uma campanha agendada volta para rascunho.

## Modelos prontos

Tudo que é pronto vira registro comum (editável, duplicável) e é comparado pelo **nome** ao instalar: o que já existe com o mesmo nome não muda, então dá para clicar de novo sem duplicar. Em Campanhas, enquanto faltar template, segmento ou automação, o card **Primeiros passos → Instalar modelos prontos** instala os três de uma vez.

**Templates** (`DefaultEmailTemplates`, botão em Templates): boas-vindas, cadastro pendente (lembrete e último aviso), empresa sem pedidos, dicas para vender mais, upgrade do plano Grátis, conheça o PDV, nota fiscal pelo Veddi, lançamento de novidade, comunicado importante e pesquisa de satisfação.

- Cadastro pendente e pesquisa pedem resposta por e-mail: configure `MARKETING_REPLY_TO` com uma caixa atendida. Cadastro pendente não tem botão de propósito: quem saiu da tela do cartão no `/cadastro` não tem cobrança para pagar no painel, então a ativação é concluída com a equipe.
- Logo (`public/images/email/veddi-logo.png`) e links saem absolutos com o `APP_URL` de quando o botão é usado; os números do upgrade (limite, filiais, taxa) vêm de `config/plans.php` e o preço do PDV e do Fiscal de `AddonModulePricing`, nesse momento. Mudou o preço? Edite o template (ou apague e instale de novo).
- Trechos `[EDITAR: ...]` (novidade e comunicado) são para trocar: o checklist (`pending_edits`) bloqueia o disparo e a ativação de automação enquanto existirem.

**Segmentos** (`DefaultEmailSegments`, botão em Segmentos): todos os usuários de empresas ativas, donos de empresas ativas, gerentes de filial, plano Grátis ativo, assinantes Essencial e PRO, cadastro não concluído, sem pedidos há 30 dias, ativos sem PDV, ativos sem nota fiscal e empresas inadimplentes. Só filtros fixos ou relativos (nenhuma data absoluta, que envelheceria).

**Campanhas** (`DefaultEmailCampaigns`, **Começar de um modelo** em Campanhas): cada modelo é um par template + segmento (upgrade do Grátis, dicas, reengajamento, cadastros pendentes, PDV, nota fiscal, novidade, comunicado, pesquisa). Usar cria um **rascunho** com conteúdo, assunto, remetente padrão e segmento, instalando só o template e o segmento que faltarem, e abre o wizard. Nada é disparado: o checklist (teste enviado, trechos `[EDITAR]`) continua valendo.

**Automações** (`DefaultEmailAutomations`, botão em Automações), todas criadas **pausadas** com o remetente padrão:

| Automação | Gatilho | Sequência |
|-----------|---------|-----------|
| Boas-vindas a empresas novas | Empresa nova, 1 h | Boas-vindas → 72 h → Dicas para vender mais |
| Recuperar cadastro abandonado | Cadastro abandonado, 2 h | Lembrete → 48 h → Último aviso |
| Reengajar empresa sem pedidos | Sem pedidos, 30 dias | Reengajamento → 168 h → Dicas para vender mais |

**Supressões** (`SuppressionCleanup`, **Limpeza da lista → Verificar lista** em Supressões): procura entre os usuários das empresas (papéis do público, já sem os suprimidos) endereços inválidos, domínio digitado errado (`gmail.con`, `hotmal.com`...), e-mails de teste (`example.com`, `teste.com`, `.test`, `.local`), caixas temporárias (mailinator, yopmail...) e endereços automáticos (`noreply@`, `nao-responda@`, `postmaster@`). **Suprimir** recalcula a lista no servidor e grava supressão manual com a observação "Limpeza da lista: ...", removível depois com motivo. Rodar antes do primeiro disparo real.

## Disparo e filas

Tudo na fila `marketing` (worker próprio no `cloud.yaml`; local, `composer dev` e o Horizon já consomem):

| Job | O que faz |
|-----|-----------|
| `BuildCampaignRecipients` | Monta o público em blocos de 1.000, congela HTML (`html_snapshot`) e variáveis, grava com `insertOrIgnore` (`unique(campaign_id, email)`), sorteia a amostra A/B. |
| `DispatchCampaignBatch` | Pega até 500 `pending`, marca `queued` e enfileira um envio por pessoa. Respeita pausa/cancelamento e o limite diário; marca como falha quem ficou preso em `sending` (sem reenviar) e devolve à fila quem ficou `queued` por horas (job perdido). |
| `SendCampaignEmail` | Limitador `marketing-send` (envios/segundo). Reserva `queued → sending` com UPDATE condicional, confere supressão e o consentimento atual do usuário, envia. Falha permanente: `failed`; temporária: volta após 1, 5 e 15 min; erro de conta (chave inválida, domínio não verificado, SMTP 535) pausa a campanha e devolve o destinatário para `pending`. |
| `ProcessEmailProviderWebhook` | Entrega, bounce, spam e falha. `provider_event_id` único garante idempotência; bounce permanente e spam viram supressão. |
| `RefreshCampaignStats` | Recalcula os contadores e pausa a campanha (alerta no `discord`) com bounce > 5% ou spam > 0,1%. |

Agendador (`routes/console.php`):

- `marketing:dispatch-scheduled` (a cada minuto): inicia agendadas, decide teste A/B vencido e retoma lotes (cobre job perdido e o limite diário de aquecimento);
- `marketing:run-automations` (a cada 15 min): automações;
- `marketing:prune-events` (diário): apaga `email_events` com mais de 12 meses (os contadores ficam).

## Provedor de envio

Contrato `EmailMarketingProviderInterface`, escolhido por `MARKETING_EMAIL_PROVIDER`:

- `resend` (o provedor em uso): API HTTP do Resend com `Idempotency-Key` por destinatário e tags `campaign_id`/`recipient_id`. Webhook em `POST /webhooks/email` assinado pelo Svix (`RESEND_WEBHOOK_SECRET`, `whsec_...`). No painel do Resend, assinar os eventos `email.delivered`, `email.bounced`, `email.complained` e `email.failed`; **desligar** o rastreio de abertura/clique do Resend (o rastreio é próprio). Sem domínio verificado, o Resend só aceita remetente `onboarding@resend.dev` e destinatário igual ao dono da conta.
- `mail` (alternativa, e o que vale se a variável faltar): mailer `marketing` de `config/mail.php` (`MARKETING_MAIL_TRANSPORT`: smtp, ses, postmark, log). Sem configuração cai no `log`. Não recebe webhook, então entrega/bounce/spam não chegam — usar só para desenvolvimento (log/Mailpit) ou SMTP simples.

Outro provedor (SES, Postmark Broadcast, Brevo) = nova classe em `app/Services/Marketing/Providers` + `match` no `AppServiceProvider`.

## Variáveis de ambiente

| Variável | Uso |
|----------|-----|
| `MARKETING_EMAIL_PROVIDER` | `resend` (em uso) ou `mail` |
| `RESEND_API_KEY` / `RESEND_WEBHOOK_SECRET` | Provedor Resend (chave só com permissão de envio) |
| `MARKETING_MAIL_*` | Só com provider `mail`: credenciais do mailer `marketing` (nunca as do transacional) |
| `MARKETING_FROM_ADDRESS` / `MARKETING_FROM_NAME` / `MARKETING_REPLY_TO` | Remetente padrão |
| `MARKETING_VERIFIED_DOMAINS` | Domínios com SPF/DKIM no provedor (ex.: `news.veddi.com.br`); o checklist bloqueia outros |
| `MARKETING_REQUIRE_OPT_IN` | `true`: só quem aceitou; `false`: legítimo interesse (todos menos quem saiu). Definir com o jurídico |
| `MARKETING_RATE_PER_SECOND` | Envios por segundo (0 = sem limite) |
| `MARKETING_DAILY_LIMIT` | Aquecimento: envios por dia somando campanhas e automações (0 = sem limite) |
| `MARKETING_LEGAL_NAME` / `MARKETING_LEGAL_DOCUMENT` / `MARKETING_LEGAL_ADDRESS` | Rodapé obrigatório (razão social, CNPJ, endereço) |

## Entregabilidade (antes do primeiro disparo real)

1. Subdomínio exclusivo (ex.: `news.veddi.com.br`) verificado no provedor com SPF e DKIM; DMARC começando em `p=none` e indo para `p=quarantine` depois de estabilizar.
2. `MARKETING_DAILY_LIMIT` baixo (algumas centenas) e subir aos poucos nas primeiras semanas.
3. Gmail e Yahoo exigem descadastro em um clique: todo e-mail sai com `List-Unsubscribe` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (rota assinada `POST /email/u/{recipient}`, fora do CSRF).

## LGPD, descadastro e rastreio

- Aceite opcional (desmarcado) no `/cadastro` e em Configurações → Perfil, gravando `users.marketing_opt_in_at`; sair grava `marketing_opt_out_at`.
- Link de descadastro assinado (`URL::signedRoute`, sem login) abre a página de preferências; descadastro vira supressão na hora. Voltar a receber só desfaz descadastro — bounce, spam e supressão manual continuam.
- Supressão global (`email_suppressions`, e-mail minúsculo) é consultada ao montar o público e de novo antes de cada envio. Remoção pelo super admin exige motivo (registrado no `discord`).
- Pixel (`/email/o/{recipient}`) e redirect de clique (`/email/c/{recipient}?u=`) assinados; o redirect só aceita links que estão no HTML da campanha daquele destinatário. IP guardado só como hash. Abertura é estimativa (Apple Mail abre sozinho).
- Links públicos usam o limitador `marketing-links` (60/min por destinatário + IP), não o throttle por IP: proxy de imagem do Gmail/Apple e NAT corporativo concentram muita gente num IP.
- Os links são assinados com o `APP_URL` do worker: se o host público mudar, links já enviados deixam de validar.
- Passo de automação aceita no redirect qualquer link http(s) assinado (o conteúdo muda quando a automação é salva de novo); campanha exige o link no HTML congelado.
- Logs (`marketing`): só ids e contagens — nunca e-mail de destinatário, chave ou segredo do provedor.

## Automações

`EmailAutomation` + passos (`EmailAutomationStep`). Cada passo envia por uma campanha interna `kind=automation` (mesmo motor, supressão, descadastro e métricas), que fica `sending` com a automação ativa e `paused` com ela pausada. Gatilhos:

- **Cadastro abandonado**: empresa em `PENDING_PAYMENT` criada há N horas (até 30 dias); sai quando a empresa ativa.
- **Empresa sem pedidos**: empresa ativa sem pedidos há N dias; sai quando volta a vender.
- **Empresa nova**: empresa ativa cadastrada nos últimos 7 dias, N horas depois do cadastro (plano pago entra quando a ativação é paga, se for dentro da janela); sai se a empresa deixar de estar ativa. A janela evita mandar boas-vindas para a base antiga ao ativar.

Uma pessoa entra uma vez por automação (`unique(automation_id, email)`); ao sair, o e-mail de passo que ainda não saiu é cancelado. Ativar exige remetente verificado, rodapé legal e variáveis válidas; editar uma automação ativa que deixe de passar nisso a pausa. Salvar a automação copia o conteúdo atual do template para os passos.

## Pendências conhecidas

- Com limite diário baixo, o teste A/B pode decidir (após `ab_wait_hours`) antes de a amostra inteira sair.
- `saveDraft`/`schedule` gravam sem condição de status: edição exatamente no minuto do agendamento pode sobrescrever o início (janela de milissegundos).
- Abertura/clique do provedor não são usados (rastreio próprio).

## Testes

`tests/Feature/Marketing/` (provedor fake em `tests/Fakes/FakeEmailMarketingProvider.php`, helpers `marketingSetup()`/`marketingMember()` em `tests/Pest.php`): acesso, público, render/sanitização, disparo/idempotência/pausa/cancelamento/A/B/limite diário, webhook Resend (assinatura Svix), descadastro, rastreio, wizard e telas, automações e opt-in.
