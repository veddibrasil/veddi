# WhatsApp — notificações de pedido (Cloud API oficial)

Cada restaurante conecta o **próprio número** (Embedded Signup da Meta, com suporte a coexistência com o app WhatsApp Business) e a Veddi envia as atualizações do pedido por **templates aprovados** na WABA dele. Este documento é interno: a documentação para o restaurante fica em `resources/views/docs.blade.php` (seção `#whatsapp`, página pública `/docs`).

## Fluxo

1. **Conectar** (`/admin/settings/whatsapp`, só `company_admin`): o Alpine `whatsappSignup` (`resources/js/admin/whatsapp-signup.js`) abre o `FB.login` e junta o `code` (callback) com `waba_id`/`phone_number_id` (postMessage `WA_EMBEDDED_SIGNUP`). O componente `WhatsAppSettings::completeSignup()` chama `WhatsAppOnboardingService::start()`, que grava a conexão como `pending` e despacha `CompleteWhatsAppOnboarding` (fila `critical`, payload criptografado).
2. **Onboarding** (`WhatsAppOnboardingService::complete()`): troca o `code` por token (uso único, nunca retentado) → confere token/WABA (`debug_token`) → inscreve o app na WABA → registra o número na Cloud API (só `cloud_api`; na coexistência **não** registra) → busca dados do número → `provisioning`.
3. **Templates** (`ProvisionWhatsAppTemplates`, fila `whatsapp`): cria na WABA os templates de `config/whatsapp_templates.php` que faltam e sincroniza o status. A conexão vira `templates_pending` e só fica `active` quando **todos** estão aprovados (webhook `message_template_status_update`; se o webhook se perder, o `whatsapp:sync-templates --pending` agendado relê o status a cada 30 minutos).
4. **Envio**: eventos de pedido → listeners `SendWhatsAppOrderNotification` / `SendWhatsAppStatusNotification` → `WhatsAppService::shouldNotify()` → `SendWhatsAppOrderNotificationJob` (fila `whatsapp`). Idempotente por `order_id + event` (`whatsapp_messages`).
5. **Webhook** (`POST /webhooks/whatsapp` → `ProcessWhatsAppWebhook` → `WhatsAppWebhookProcessor`): status de entrega das mensagens, aprovação de templates, qualidade/limite, alertas e mudanças da conta, opt-out (PARAR/SAIR/STOP/CANCELAR) e ecos do app (coexistência).

Status da conexão (`whatsapp_connections.status`): `pending` → `provisioning` → `templates_pending` → `active`; fora do caminho feliz: `error` e `disconnected`.

## Regras de negócio

- Só pedidos `channel=chat` e `order_type != pdv` (PDV e iFood nunca notificam).
- O cliente precisa de opt-in **da própria empresa** (`customers.whatsapp_opt_in_at`, dado no chat, checkbox desmarcado por padrão) e não ter opt-out. `CustomerService::createFromGlobal` não herda consentimento.
- Pedido em dinheiro agendado recebe o template `scheduled` no lugar de `new_order`; `paid` em dinheiro não notifica (pagamento na entrega).
- `awaiting_payment` (código PIX por WhatsApp) **não tem template** — pendência (o toggle fica desabilitado "em breve").
- Sem conexão ativa, só envia pelo número da plataforma se `WHATSAPP_FALLBACK_TO_PLATFORM=true` (homologação).
- WABA e número são exclusivos por empresa; vínculo de outra empresa bloqueia, exceto se ela estiver `disconnected` ou em `error` sem nunca ter conectado (`connected_at` nulo; o `fail()` dessa tentativa já limpa os ids). O `phone_number_id` informado pelo navegador só é aceito se pertencer à WABA autorizada no token.

## Configuração do app Meta

Variáveis de ambiente (ver `.env.example`):

| Variável | O que é |
|----------|---------|
| `META_APP_ID` / `META_APP_SECRET` | App da Meta (o secret assina o webhook e gera o `appsecret_proof`) |
| `META_GRAPH_VERSION` | Versão da Graph API (padrão `v25.0`) |
| `WHATSAPP_ES_CONFIG_ID` | ID da configuração do Embedded Signup |
| `WHATSAPP_WEBHOOK_VERIFY_TOKEN` | String aleatória nossa, usada na verificação do webhook |
| `WHATSAPP_PLATFORM_WABA_ID` / `_PHONE_NUMBER_ID` / `_TOKEN` | Número da própria Veddi (homologação e fallback) |
| `WHATSAPP_FALLBACK_TO_PLATFORM` | `true` = empresas sem conexão ativa enviam pelo número da plataforma |

No painel do app (developers.facebook.com):

1. **Webhooks → WhatsApp Business Account**
   - URL de callback: `https://app.veddi.com.br/webhooks/whatsapp`
   - Token de verificação: o mesmo valor de `WHATSAPP_WEBHOOK_VERIFY_TOKEN`
   - Campos assinados: `messages`, `message_template_status_update`, `template_category_update`, `phone_number_quality_update`, `account_update`, `account_alerts`, `smb_message_echoes`, `history`, `smb_app_state_sync` (os três últimos são exigidos pela Meta para coexistência; `history` e `smb_app_state_sync` são descartados no controller, só registrando que chegaram — trazem conversas e contatos do restaurante)
2. **Embedded Signup v4**: em Login do Facebook para Empresas → Configurações → Criar configuração: variação de login **"Cadastro incorporado do WhatsApp"** (não "Geral" — a variação não muda depois de criada), produto **WhatsApp Cloud API** selecionado (selecionar o produto já coloca a configuração no v4), token de acesso de usuário do sistema com validade **Nunca**, ativo "Contas do WhatsApp" e as permissões `whatsapp_business_management` e `whatsapp_business_messaging`; o ID gerado vai em `WHATSAPP_ES_CONFIG_ID`. Sintoma de configuração errada (variação "Geral"): o popup é só um login comum e a tela mostra "A Meta não confirmou a conta e o número conectados" (o `code` chega, o evento `WA_EMBEDDED_SIGNUP` não). Configuração antiga (v2, sem produto selecionado) para de funcionar em 15/10/2026. O front manda `extras: {setup: {}, featureType: 'whatsapp_business_app_onboarding'}` — a versão do Embedded Signup vem da configuração, não do `extras`, e o v4 sempre manda o evento de sessão (o `sessionInfoVersion` era do v2/v3). Pelo modelo, a configuração certa é "Configuração do cadastro incorporado do WhatsApp com token de expiração em 60 dias" (o modelo "Parceiro de mensuração do WhatsApp" é só leitura e só v2). Confira no Embedded Signup Builder que aparece a opção de conectar um número do app WhatsApp Business (coexistência).
3. **Login do Facebook para Empresas → Configurações → Configurações de OAuth do cliente**: deixar como **Sim** "Login no OAuth do cliente", "Login do OAuth na Web", "Forçar HTTPS" e "Entrar com o SDK do JavaScript", e incluir em "Domínios permitidos para o SDK do JavaScript" `app.veddi.com.br` e, para teste local, o host https do ngrok. Em **Configurações → Básico → Domínios do app**, incluir os mesmos domínios. Sem isso o popup do `FB.login` falha; o erro "A opção JSSDK não está ativada" significa que "Entrar com o SDK do JavaScript" está desligado.
4. O app precisa estar em modo **Ativo** e com acesso avançado às permissões acima (verificação da empresa/Tech Provider) para atender restaurantes de terceiros.

> O front do Embedded Signup e as origens da CSP (`app/Http/Middleware/SecurityHeaders.php`: `frame-src`/`child-src` de `www.facebook.com`, `web.facebook.com`, `staticxx.facebook.com`) só foram validados em teste de servidor. No primeiro teste real, abra o console do navegador e confira se há bloqueio de CSP.

## Filas e agendamento

- Filas (ver `cloud.yaml`): `critical` (onboarding, o `code` expira em ~30 segundos), `whatsapp` (envio, webhook e templates, `timeout: 120`). Localmente, `composer dev` e o Horizon (`config/horizon.php`, ambiente `local`) consomem `critical,default,whatsapp,low`; depois de mudar o config, reinicie o Horizon (`php artisan horizon:terminate`).
- `whatsapp:check-connections` roda todo dia às 09:00 (`routes/console.php`, precisa do scheduler ligado).
- `whatsapp:sync-templates --pending` roda a cada 30 minutos: relê na Meta os templates das conexões em `provisioning`/`templates_pending` (webhook de aprovação perdido ou chegado no meio do provisionamento, template que falhou ao ser criado).
- Localmente o `php artisan dev:start` sobe um `queue:work` com `critical,default,whatsapp,low` além do Horizon.

## Comandos

| Comando | Para que serve |
|---------|----------------|
| `whatsapp:send-test {telefone} [--company=]` | Envia o template `pedido_em_preparo` com dados fictícios (homologação); ignora opt-in |
| `whatsapp:sync-templates [--company=]` | Recria/sincroniza os templates e atualiza qualidade/limite (suporte) |
| `whatsapp:sync-templates --pending` | Só as conexões aguardando templates (agendado a cada 30 minutos) |
| `whatsapp:sync-templates --platform` | Inscreve o app (webhook) e cria/sincroniza os templates na WABA do número da plataforma (necessário antes de `whatsapp:send-test` sem `--company` e do fallback). Exige `WHATSAPP_PLATFORM_TOKEN` permanente (usuário do sistema) |
| `whatsapp:check-connections` | Roda o monitoramento diário na hora |

Super admin: `/superadmin/whatsapp` lista todas as conexões (status, qualidade, tipo, último erro) e enfileira "Sincronizar templates".

## Monitoramento e alertas

`WhatsAppConnectionMonitor` (comando `whatsapp:check-connections`) avisa o restaurante — sino do painel (`CompanyNotification` tipo `whatsapp_alert`) + e-mail (`WhatsAppConnectionAlert`) a todos os `company_admin` — quando:

| Alerta | Condição |
|--------|----------|
| `app_inactive` | Conexão `active` em coexistência sem uso do app há ≥ 12 dias (`last_app_activity_at`; sem ecos, conta a partir de `connected_at`). A Meta desconecta em ~14 dias |
| `error` | Conexão que já funcionou (`connected_at` preenchido) e está em `error` (token revogado, conta desativada...). Falha de onboarding não alerta: o restaurante viu na tela |
| `quality_red` | Conexão `active` com `quality_rating = RED` |

Repetição: `whatsapp_connections.alerts_sent` guarda quando cada alerta foi enviado; o mesmo alerta só volta depois de 7 dias enquanto o problema durar, e o registro some quando o problema é resolvido.

## Logs

- Canal `whatsapp` (arquivo diário em `storage/logs/whatsapp.log` + Nightwatch): todo o fluxo. **Nunca** loga token, app secret, PIN, `code`, payload do postMessage, telefone nem texto de cliente.
- Canal `discord` (o mesmo dos pagamentos; nível pelo `DISCORD_LOG_LEVEL`): `WhatsAppCriticalLog` — `error` quando a Meta recusa um token (`WhatsAppAuthException`, no envio, no provisionamento ou no onboarding) e `critical` quando a Meta desativa (bane) uma conta de restaurante ou a da plataforma.

## Pendências e pontos a confirmar

- Código PIX por WhatsApp (`awaiting_payment`): falta o template e o gatilho.
- Fora de escopo: mensagens do atendente, chatbot, sincronização de contatos/histórico da coexistência (`history`, `smb_app_state_sync` só são logados), cobrança do custo das mensagens ao restaurante.
- Formatos de `account_update`, `phone_number_quality_update`, `account_alerts`, `debug_token` e os códigos de erro 133xxx foram implementados a partir da documentação, sem sandbox: confirmar com o primeiro evento real.
- Os templates aprovados não podem ter o texto editado: mudar `body`/`name` em `config/whatsapp_templates.php` exige um template novo.
