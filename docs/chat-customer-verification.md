# Identificação do cliente no chat

O telefone informado no chat não autoriza acesso a um cadastro. Há duas formas de reutilizar dados:

- **Dados deste aparelho:** apenas os dados preenchidos naquele navegador em um pedido visitante. O pedido seguinte continua com `customer_id = null`; nenhum `Customer` é criado, alterado ou vinculado.
- **Perfil salvo:** dados de `Customer` liberados após código confirmado por `PhoneVerification` e `ChatAccess`, ou pela restauração válida de um aparelho autenticado.

## Dados do aparelho

O checkbox “Lembrar meus dados neste aparelho” vem marcado. Após criar um pedido visitante, o serviço `DeviceProfile` grava nome, e-mail, CPF e endereço no campo `data`, com cast `encrypted:array`. A coluna é `text`, pois armazena o JSON criptografado.

O cookie `chat_device_profile_{companyId}` é Secure, HttpOnly e SameSite=Lax. O banco guarda apenas o SHA-256 do token aleatório de 64 caracteres e o HMAC-SHA256 do telefone. A consulta exige empresa, cookie, telefone e registro válido. Cada leitura rotaciona o token sem estender os 30 dias de validade. Uma nova compra lembrada renova a validade.

Desmarcar o checkbox e concluir o pedido visitante revoga o registro anterior. A ação “Não sou eu / esquecer este aparelho” revoga tanto os dados do visitante quanto o aparelho autenticado e limpa o estado do chat.

## Código e perfil salvo

`CHAT_CODE_CHANNEL` seleciona o canal:

| Valor | Comportamento |
| --- | --- |
| `email` (padrão) | Código para o e-mail válido do cliente na própria empresa. |
| `whatsapp` | Exige `CHAT_WHATSAPP_AUTH_ENABLED=true`. Prioriza a Meta com template aprovado; pode usar o fallback WuzAPI temporário descrito abaixo. |
| `none` ou valor desconhecido | Sem envio de código; dados do aparelho continuam disponíveis. |

Sem cliente ou e-mail válido, o canal de e-mail retorna sem enviar e mantém a mesma etapa e mensagem. O chat não mostra endereço mascarado. O serviço aplica um tempo mínimo de 100 ms para reduzir diferenças usuais de consulta e enfileiramento; isso não é garantia de tempo constante sob carga.

`ChatVerificationCode` implementa `ShouldQueue` e `ShouldBeEncrypted`. O payload persistido na fila protege tanto o código quanto o destinatário. O assunto não contém o código.

O código vale por cinco minutos, é vinculado à empresa, telefone e sessão, e só pode ser consumido uma vez. Cinco tentativas incorretas bloqueiam o desafio. Os limites de solicitação são:

| Escopo | Limite |
| --- | --- |
| Sessão/telefone | 1 por minuto |
| Telefone/empresa | 5 por 10 minutos |
| IP | 20 por 10 minutos |
| Empresa | 60 por 10 minutos |
| Global | 1.000 por 10 minutos |

“Lembrar neste aparelho por até 30 dias” vem marcado na confirmação do código. A restauração usa `RememberedDevice`, rotaciona o token e não concede verificação recente para cartões salvos. O chat carrega apenas a identidade autorizada por `ChatAccess` e preserva preenchimentos de visitante em andamento.

## Tela de identificação

Com dados deste aparelho, o chat mostra "Continuar como {primeiro nome}" (só o primeiro nome que o próprio navegador digitou) e "Preencher meus dados". O perfil salvo fica como opção secundária, "Já tenho cadastro: usar meus dados salvos", sempre visível quando o canal de código está ativo, para não revelar se o telefone tem cadastro.

## Cupom com limite por cliente

Visitantes podem usar cupons com `max_uses_per_customer`. O limite conta, juntos, os usos do cliente identificado, os pedidos visitantes com o mesmo telefone (`orders.buyer_data->phone`) e os usos de clientes cadastrados com esse telefone. Pedido cancelado ou estornado devolve o uso. A verificação é repetida com lock na criação do pedido (`CouponService::revalidateForOrder`). Como o telefone do visitante não é confirmado, o limite é um freio de boa-fé: quem usa outro número consegue repetir o cupom, e o limite global `max_uses` continua valendo.

## Troco

No pagamento em dinheiro, a confirmação pergunta se o cliente precisa de troco. O valor precisa ser igual ou maior que o total e vira a linha "Troco para R$ X" nas observações do pedido, que a loja já vê no painel e na impressão.

## Produção

Configuração mínima, usando SMTP como exemplo:

```dotenv
CHAT_CODE_CHANNEL=email
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=noreply@seudominio.com.br
```

Configure também host, porta, credenciais e demais parâmetros do provedor de e-mail. Resend ou SES podem ser usados quando o respectivo transporte e suas dependências estiverem configurados.

`config/mail.php` tem fallback `log`: **produção exige um provedor real**. O mailer `log` grava o corpo do e-mail, incluindo o código e destinatário. Também não use a configuração `failover` que inclui `log` como alternativa. `.env.example` usa SMTP com Mailpit para desenvolvimento; `cloud.yaml` já declara SMTP, mas credenciais e remetente devem estar configurados no ambiente de destino.

Execute a migration `2026_10_26_000001_create_chat_device_profiles_table.php` no deploy. Mantenha HTTPS para os cookies Secure, uma fila assíncrona (por exemplo, a conexão Redis já indicada em `.env.example`) e um worker consumindo `default`. O `cloud.yaml` já declara essa fila. Recarregue o cache de configuração e reinicie os workers/Horizon após alterar o ambiente. Não altere `APP_KEY` sem planejar a migração dos dados criptografados e dos hashes de telefone.

### WhatsApp com fallback temporário WuzAPI

O fallback usa a máquina existente do Barberya, `https://wa.locufybr.com` (WuzAPI self-hosted, não o serviço `wuapi.dev`). A sessão `veddi-auth` deve ter token e pareamento próprios da Veddi. Nunca reaproveite tokens de barbearias ou altere seus webhooks. O token administrativo é necessário apenas para criar a sessão na máquina; não é usado nem armazenado pela integração de envio.

```dotenv
CHAT_CODE_CHANNEL=whatsapp
CHAT_WHATSAPP_AUTH_ENABLED=true
CHAT_WHATSAPP_META_ENABLED=true
CHAT_WHATSAPP_WUZAPI_FALLBACK_ENABLED=true
WUZAPI_BASE_URL=https://wa.locufybr.com
WUZAPI_SESSION_TOKEN=<token da sessão veddi-auth>
```

Mantenha os segredos no ambiente de destino. O `.env` local não configura o Laravel Cloud. Recarregue o cache de configuração e reinicie os workers após mudar o ambiente.

`CHAT_WHATSAPP_AUTH_ENABLED` é a chave geral. `CHAT_WHATSAPP_META_ENABLED` controla somente a API oficial (padrão `true` para preservar instalações existentes); pode ficar `false` enquanto o onboarding da Meta estiver em preparação. Sem Meta habilitada e sem fallback configurado, a opção de acessar o perfil salvo fica indisponível.

Com a Meta habilitada, o fallback é selecionado **somente antes de enviar** quando não há remetente oficial, falta WABA, ou o template consultado não está aprovado na categoria/idioma esperados. Erros de consulta, token inválido, falhas de envio, respostas sem confirmação e timeouts não acionam outro provedor. Não há retentativas HTTP automáticas: um timeout pode significar que o código já foi enviado.

O cliente WuzAPI exige HTTPS, não segue redirects, consulta `GET /session/status` e exige `connected=true` e `loggedIn=true`. Só então envia `POST /chat/send/text`, com header `token` e campos `Phone`, `Body` e `Id`. Usa `55` + o telefone nacional exato, sem alterar o nono dígito. O `Id` é derivado do identificador do desafio, sem código ou telefone. Isso identifica a mensagem, mas não pressupõe garantia de idempotência do servidor. O bloqueio e os limites de `PhoneVerification` impedem solicitações concorrentes/repetidas do mesmo escopo.

Cada chamada WuzAPI tem timeout de quatro segundos (conexão: dois segundos). O lock do desafio dura 45 segundos para cobrir também o caminho de consulta da Meta e posterior fallback. Respostas e exceções do provedor são sanitizadas, sem registrar código, telefone ou token; o Nightwatch registra apenas metadados da chamada de saída. A criação, expiração de cinco minutos, tentativas, consumo único e isolamento por empresa/sessão continuam em `PhoneVerification`. Falha de envio invalida o desafio.

Use a sessão apenas para autenticação, com histórico desabilitado (`history=0`), sem webhook e sem assinatura de mensagens recebidas. O envio WuzAPI é síncrono; não utiliza a fila de envio do serviço `wuapi.dev`. Não há recebimento de mensagens ou fallback de notificações de pedidos, PIX, marketing ou status. Os avisos de pedido e o consentimento continuam seguindo exclusivamente a configuração da Cloud API oficial.

Antes de ativar, verifique a sessão e conclua o pareamento no WhatsApp do número da Veddi. Os testes automatizados simulam a API e não enviam mensagens reais.

### Migrar para a API oficial

Após aprovação da Meta:

```dotenv
CHAT_CODE_CHANNEL=whatsapp
CHAT_WHATSAPP_AUTH_ENABLED=true
CHAT_WHATSAPP_META_ENABLED=true
CHAT_WHATSAPP_WUZAPI_FALLBACK_ENABLED=false
CHAT_WHATSAPP_AUTH_TEMPLATE=veddi_confirmar_telefone
CHAT_WHATSAPP_AUTH_LANGUAGE=pt_BR
```

O nome e idioma devem corresponder ao template aprovado na categoria **AUTHENTICATION**, com botão **COPY_CODE**, na WABA do remetente que a empresa utiliza. Recarregue a configuração e os workers. O código continua validando remotamente a aprovação do template antes de enviar.

Após homologar o envio oficial, desabilite o fallback e retire o token WuzAPI do ambiente. A sessão dedicada pode ser desconectada posteriormente sem afetar as sessões dos outros projetos.

## Validação

Os testes de feature estão em `tests/Feature/Chat/DeviceProfileTest.php`, `EmailAuthenticationTest.php` e `CustomerAccessFlowTest.php`, além dos testes existentes de segurança e WhatsApp.

```sh
composer lint
./vendor/bin/pest
php artisan route:list
```

Se o PHP local limitar a suíte a 128 MB, use `php -d memory_limit=1G ./vendor/bin/pest`, conforme o `CLAUDE.md`. Em sandbox onde o OpenSSL não consegue gravar seu estado aleatório, a execução pode usar `RANDFILE` apontando para um arquivo temporário gravável.
