# Mister Coxinha

## O que e este projeto

O Mister Coxinha e uma plataforma SaaS multiempresa para operacao de pedidos, cardapio e gestao financeira de negocios de alimentacao.

O sistema cobre quatro frentes principais:

- onboarding publico de novas empresas;
- chat publico de pedidos por empresa;
- painel administrativo da empresa para operacao diaria;
- painel de super admin para governanca da plataforma.

Tambem ha integracoes de pagamento e webhook:

- Asaas para onboarding, taxa de ativacao e cobrancas recorrentes;
- Vindi para PIX e parte do fluxo financeiro;
- carteira, saque, antecipacao e conciliacao interna por empresa;
- WhatsApp Cloud API (Meta) para avisar o cliente a cada etapa do pedido, com numero proprio de cada empresa (detalhes na secao "Notificacoes por WhatsApp").

## Stack principal

- Laravel 12
- PHP 8.2+ no projeto, com CI validando em PHP 8.4 e 8.5
- Livewire 4
- Livewire Flux 2 e Blaze
- Vite 7
- Tailwind CSS 4
- Pest 3 para testes
- Laravel Pint com preset `laravel`
- Reverb para eventos em tempo real

## Como o produto esta organizado

### Entrada publica

- `GET /cadastro`: onboarding de novas empresas
- `POST /cadastro`: cria empresa, filial inicial e usuario admin
- `GET /{company}`: chat publico da empresa para montar pedido
- `POST /webhooks/asaas`: eventos financeiros do Asaas
- `POST /webhooks/vindi`: eventos financeiros do Vindi
- `POST /webhooks/fiscal`: eventos de nota fiscal
- `GET|POST /webhooks/whatsapp`: verificacao do endpoint e eventos da WhatsApp Cloud API (assinatura `X-Hub-Signature-256`)

### Painel administrativo da empresa

Fica em `/admin` e exige autenticacao. Ha separacao por papeis como `company_admin` e `branch_manager`.

Principais modulos:

- dashboard
- pedidos
- relatorios
- estoque
- suporte
- categorias
- produtos
- filiais
- cupons
- configuracoes
- faturamento
- carteira
- whatsapp (conexao do numero e notificacoes de pedido)
- usuarios e papeis

### Painel super admin

Fica em `/superadmin` e opera sem o escopo normal de empresa.

Principais modulos:

- empresas
- usuarios
- permissoes
- taxas/cartoes
- simulacao de pagamento
- conexoes de WhatsApp de todas as empresas (`/superadmin/whatsapp`)

## Estrutura do codigo

### Pastas mais importantes

- `app/Livewire`: interface principal do sistema
- `app/Livewire/Chat`: fluxo publico de pedido
- `app/Livewire/Admin`: painel da empresa
- `app/Livewire/SuperAdmin`: backoffice da plataforma
- `app/Livewire/Onboarding`: telas de onboarding e pagamento pendente
- `app/Services`: regras de negocio e orquestracao
- `app/Contracts`: contratos para servicos e gateways
- `app/DTOs`: objetos de transferencia, como `OnboardingDTO`
- `app/Models`: entidades Eloquent
- `app/Jobs`: processamento assincrono e integracoes
- `app/Http/Controllers`: endpoints HTTP, webhooks e acoes de entrada
- `app/Policies`, `app/Rules`, `app/Exceptions`: autorizacao e regras transversais
- `routes/web.php`: fluxo principal do produto
- `routes/settings.php`: rotas auxiliares de configuracao
- `resources/views`: Blade, layouts e views de Livewire
- `tests/Feature` e `tests/Unit`: testes com Pest

### Como as responsabilidades se dividem

- Controllers devem ser finos: recebem request, validam entrada e delegam.
- Services concentram regra de negocio e integracoes.
- DTOs ajudam a transportar dados validados entre camadas.
- Contracts evitam acoplamento direto com gateways externos.
- Jobs ficam com trabalhos assincronos, webhooks e tarefas demoradas.
- Livewire concentra a experiencia interativa e a orquestracao da UI.
- Models representam o dominio e seus relacionamentos, mas regras complexas devem ficar fora deles quando crescerem.

## Contexto de dominio importante

### Multi-tenancy

O sistema e multiempresa. Boa parte do dominio depende de `app('current.company')`.

Implicacoes praticas:

- sempre considere o contexto da empresa atual antes de consultar ou gravar dados;
- use `withoutGlobalScopes()` apenas quando houver motivo claro, como onboarding, super admin ou manutencao interna;
- cuidado para nao vazar dados entre empresas em consultas, listeners, jobs e testes;
- existe cobertura de teste para isolamento por empresa em `tests/Feature/CustomerMultiTenancyTest.php`.

### Planos e faturamento

Os planos ficam em `app/Enums/Plan.php`.

Cada plano pode definir:

- mensalidade;
- taxa por pedido;
- taxa de ativacao;
- limite de pedidos;
- limite de filiais;
- se possui assinatura mensal.

### Onboarding

O onboarding cria:

- empresa;
- filial inicial;
- usuario administrador;
- cliente Asaas;
- cobranca de taxa de ativacao quando aplicavel.

Fluxo atual:

1. `RegisterCompanyController` recebe os dados validados.
2. `OnboardingDTO` normaliza a entrada.
3. `OnboardingService` cria cliente no Asaas antes da transacao.
4. Dentro de transacao, cria empresa, filial e usuario.
5. Plano `free` ativa de imediato.
6. Planos pagos ficam em `PENDING_PAYMENT` ate confirmacao por webhook.

### Pedidos e pagamentos

O fluxo de pedido passa principalmente por:

- `app/Livewire/Chat/OrderChat.php`
- concerns em `app/Livewire/Chat/Concerns`
- `app/Services/OrderService.php`
- `app/Services/PaymentOrchestrator.php`

Regras importantes:

- o preco final deve ser calculado no servidor;
- disponibilidade de produto e filial deve ser validada no backend;
- estoque e cupom fazem parte da criacao do pedido;
- taxa da plataforma e valor liquido precisam ser calculados no backend;
- o gateway pode variar por metodo de pagamento.

### Webhooks e processamento assincrono

Webhooks e jobs sao parte critica da plataforma.

Cuidados:

- handlers devem ser idempotentes;
- logs de pagamento e pedido precisam preservar contexto;
- transicoes de status devem evitar duplicidade;
- ao alterar pagamento, revisar efeitos em pedido, carteira, saldo e notificacoes.

### Notificacoes por WhatsApp

Cada empresa conecta o proprio numero (Embedded Signup da Meta, com coexistencia com o app WhatsApp Business) e recebe os avisos do pedido por templates aprovados na WABA dela. Guia completo, com a configuracao do app Meta (callback, campos do webhook, variaveis de ambiente): `docs/whatsapp.md`. A documentacao publica para o restaurante fica em `resources/views/docs.blade.php` (secao `#whatsapp`).

Onde esta cada coisa:

- `app/Services/Messaging`: `WhatsAppService` (regras: template, remetente, opt-in), `MetaGraphClient` e `MetaCloudApiProvider` (Graph API, atras dos Contracts `WhatsAppProviderInterface` e `WhatsAppManagementInterface`), `WhatsAppOnboardingService`, `WhatsAppTemplateProvisioner`, `WhatsAppWebhookProcessor`, `WhatsAppConnectionMonitor` (alertas diarios) e `WhatsAppCriticalLog` (canal `discord`);
- `app/Jobs`: `CompleteWhatsAppOnboarding` (fila `critical`), `ProvisionWhatsAppTemplates`, `SendWhatsAppOrderNotificationJob` e `ProcessWhatsAppWebhook` (fila `whatsapp`);
- `app/Models`: `WhatsAppConnection` (uma por empresa; token e PIN criptografados e `hidden`), `WhatsAppTemplate`, `WhatsAppMessage`, `WhatsAppSetting` (toggles por evento);
- `config/whatsapp_templates.php`: os 10 templates (nome, corpo, variaveis, exemplo); mudar o texto de um template aprovado exige um template novo;
- telas: `app/Livewire/Admin/Settings/WhatsAppSettings` (`/admin/settings/whatsapp`, so `company_admin`), secao "Notificacoes WhatsApp" em `Admin/Orders/Show`, `app/Livewire/SuperAdmin/WhatsApp/Connections`; front do signup em `resources/js/admin/whatsapp-signup.js`;
- opt-in do cliente no chat: `OrderChat::$whatsappOptIn` gravado por `HasCustomerProfile` via `CustomerService::registerWhatsAppOptIn`.

Fluxo: conectar (`pending`) -> onboarding (`provisioning`) -> templates criados na Meta (`templates_pending`) -> todos aprovados via webhook (`active`). Fora disso: `error` e `disconnected`. O envio so ocorre com conexao `active` (ou fallback para o numero da plataforma com `WHATSAPP_FALLBACK_TO_PLATFORM`).

Regras que nao podem quebrar:

- so notifica pedidos `channel=chat` que nao sejam PDV, e so clientes com opt-in da propria empresa e sem opt-out;
- uma mensagem por `order_id + event` (idempotencia em `whatsapp_messages`); falha permanente nao retenta, temporaria retenta;
- consultas de conexao fora de request/tenant usam `withoutGlobalScopes()` com `company_id` explicito; nunca cruzar dados entre empresas;
- nunca logar token, app secret, PIN, `code` do signup, telefone nem texto de cliente (canal `whatsapp` so leva ids e codigos);
- o `code` do Embedded Signup e de uso unico: a troca por token nunca e retentada.

Comandos e operacao:

- `php artisan whatsapp:send-test {telefone} [--company=]`: envia um template de teste (ignora opt-in);
- `php artisan whatsapp:sync-templates [--company=]`: recria/sincroniza templates e atualiza qualidade/limite;
- `php artisan whatsapp:check-connections`: monitoramento diario (agendado as 09:00 em `routes/console.php`); avisa os `company_admin` por sino do painel e e-mail quando a coexistencia fica sem uso do app por 12+ dias, a conexao entra em erro ou a qualidade do numero fica vermelha;
- `composer dev` nao consome as filas `whatsapp` e `critical`; em producao os workers estao no `cloud.yaml`.

Pendencia conhecida: o evento `awaiting_payment` (codigo PIX por WhatsApp) ainda nao tem template nem gatilho; o toggle aparece desabilitado como "em breve".

## Boas praticas para contribuir neste projeto

### Regras gerais

- preserve o padrao existente antes de introduzir nova abstracao;
- prefira alteracoes pequenas e consistentes com o modulo afetado;
- nao mova regra de negocio para Blade ou para a camada de view;
- nao confie em dados calculados no cliente para preco, taxa ou total;
- evite acoplamento direto a gateways; prefira Contracts e Services;
- em mudancas sensiveis, revise efeitos em multi-tenancy, autorizacao e webhooks.

### Ao trabalhar com Livewire

- mantenha nomes de propriedades e metodos claros e alinhados ao fluxo atual;
- reaproveite `Concerns` quando o comportamento pertencer ao chat ou a um fluxo compartilhado;
- evite colocar consultas pesadas repetidas no ciclo de renderizacao;
- preserve a experiencia do usuario em fluxos longos, principalmente no chat de pedidos.

### Ao trabalhar com Services e dominio

- prefira services para regras com multiplos passos;
- use transacoes quando a operacao precisa ser atomica;
- valide invariantes de negocio no backend mesmo que a UI ja valide;
- ao mexer em cobranca, garanta consistencia entre `Order`, `Payment`, saldo e notificacoes.

### Ao trabalhar com Models

- mantenha relacionamentos explicitos e casts corretos;
- evite inflar models com muita orquestracao;
- se precisar burlar escopo global, documente a razao no codigo.

### Ao trabalhar com testes

- adicione ou ajuste testes quando mudar comportamento;
- para regra de negocio, prefira teste de feature cobrindo o fluxo real;
- para helpers, enums e pequenas regras isoladas, use unit tests;
- em fluxos multiempresa, sempre teste isolamento de dados.

## Fluxo funcional do sistema

### Fluxo de onboarding

1. A empresa se cadastra em `/cadastro`.
2. O sistema cria os registros iniciais e associa o admin.
3. Se o plano for pago, a empresa fica pendente de taxa de ativacao.
4. O webhook confirma o pagamento.
5. A empresa e ativada e pode operar no painel.

### Fluxo do cliente final

1. O cliente entra na rota publica da empresa por slug.
2. O chat identifica empresa e filial disponivel.
3. O cliente monta carrinho, informa dados e escolhe pagamento.
4. O pedido e criado com validacao de preco, estoque e cupom no backend.
5. O pagamento e iniciado pelo gateway adequado.
6. O status do pedido evolui via eventos, painel admin e webhooks.

### Fluxo operacional da empresa

1. A equipe acessa `/admin`.
2. Gerencia cardapio, estoque, filiais, cupons e configuracoes.
3. Acompanha pedidos e suporte em tempo real.
4. Consulta faturamento, carteira, saldo e acoes financeiras.

## Fluxo recomendado de desenvolvimento

1. Ler a rota, componente Livewire, service e model envolvidos.
2. Confirmar se ha impacto de multi-tenancy, permissao ou pagamento.
3. Implementar no menor ponto coerente da arquitetura.
4. Rodar `composer lint`.
5. Rodar `php artisan test` ou `./vendor/bin/pest`.
6. Rodar `npm run build` se houver alteracao de frontend/assets.

## Comandos principais

- Instalacao inicial: `composer setup`
- Ambiente local completo: `composer dev`
- Lint: `composer lint`
- Verificacao de lint: `composer lint:check`
- Testes: `php artisan test`
- Testes via Pest: `./vendor/bin/pest` (se estourar memoria: `php -d memory_limit=1G ./vendor/bin/pest`)
- Build de assets: `npm run build`
- Dev frontend: `npm run dev`

## Observacoes de ambiente

- O CI configura credenciais do Flux antes do `composer install`.
- Os testes usam `sqlite` em memoria conforme `phpunit.xml`.
- `composer dev` sobe servidor Laravel, queue listener, pail, Vite e Reverb em paralelo.
- O repositorio pode estar com alteracoes locais em andamento; revise antes de sobrescrever arquivos ja mexidos.
