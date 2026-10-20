# Implementação da segurança do chat

Data: 19/10/2026. Implementação local; sem migração, publicação, envio de mensagens ou alteração de contas externas em produção.

## Comportamento entregue

- Carrinho → entrega/retirada → telefone → preencher dados ou usar dados salvos. Retirada não pede endereço; CPF só é obrigatório para Pix/cartão. E-mail é opcional e não consulta unicidade de cadastros.
- Informar telefone não consulta nem importa perfis, revela existência de cadastro ou altera consentimento. O caminho visitante não cria clientes artificiais.
- `orders.customer_id` é opcional. `buyer_data` preserva contato, CPF, endereço, coordenadas e indicação de telefone verificado. O endereço também usa a estrutura de snapshot já existente.
- Pedidos antigos continuam usando a relação de cliente. Pedidos novos usam o contato registrado no pedido em painel, busca, impressão, eventos, cobrança e e-mails. O adaptador `Order::customer` fornece um Customer não persistido para os consumidores existentes; jobs e e-mails reconstroem esse contato a partir do pedido, sem serializar um modelo sem ID.
- Identidade vem da sessão do servidor, com empresa, telefone e validade. Os IDs do Livewire não autenticam o visitante. Edição explícita de perfil e histórico exigem identidade; confirmação de endereço e CPF da compra não alteram o perfil.
- Cartões salvos exigem verificação nos últimos 10 minutos. Visitantes usam cartão novo; os tokens desses cartões não são gravados em perfis. A cobrança de cartão salvo também valida o vínculo no serviço executor.
- Cupom público sem limite pessoal aceita visitante. Cupom com `max_uses_per_customer` exige cadastro verificado. Não há associação futura automática de pedido visitante ao perfil do telefone.
- A sessão criadora recebe autorização de gestão do pedido por 7 dias. As políticas existentes continuam decidindo quando cancelar/estornar ou trocar pagamento.
- Avisos pelo WhatsApp exigem telefone verificado e consentimento daquela compra. Telefone não verificado recebe apenas acompanhamento pelo link; nenhum aviso automático pelo WhatsApp. Opt-out permanente é respeitado, e PARAR também retira o consentimento de avisos dos pedidos visitantes já existentes, sem criar perfil.
- Cada checkout tem chave aleatória protegida no componente e índice único no banco. Confirmações passam por lock e reutilizam o pedido existente, evitando duplicação por reenvio do mesmo checkout.
- Sessões anteriores à versão 2 perdem identidade, pedidos, mensagens e dados privados; preservam carrinho e filial. Snapshots com identidade expirada/revogada são limpos durante a hidratação.

## Verificação pelo WhatsApp

Canal escolhido: integração existente com a Cloud API da Meta, usando template **AUTHENTICATION**, botão **COPY_CODE** e validade de 5 minutos. O código vai no corpo e no parâmetro do botão; o envio segue o [exemplo de autenticação da AWS para a Cloud API](https://aws.amazon.com/blogs/messaging-and-targeting/send-whatsapp-business-messages-with-aws-end-user-messaging-social/) e a [definição de template da coleção oficial da Meta](https://www.postman.com/meta/whatsapp-business-platform/request/6vkv46u/create-authentication-template-w-otp-copy-code-button).

O adaptador confere remotamente nome, idioma, categoria e aprovação do template na WABA do remetente. Não usa templates de notificação para autenticar, não altera o nono dígito do telefone e não precisa que exista um cadastro para enviar.

Configuração nova, desabilitada por padrão:

```dotenv
CHAT_WHATSAPP_AUTH_ENABLED=false
CHAT_WHATSAPP_AUTH_TEMPLATE=veddi_confirmar_telefone
CHAT_WHATSAPP_AUTH_LANGUAGE=pt_BR
```

O remetente usa conexão ativa da empresa ou o fallback já configurado da plataforma. O fallback também precisa de `WHATSAPP_PLATFORM_WABA_ID`. A habilitação de avisos de pedidos não controla a autorização de envio do código solicitado pelo usuário.

Preparar a definição sem chamadas externas:

```sh
php artisan chat:whatsapp-auth-template ID_DA_EMPRESA
```

Depois da revisão operacional, enviar para aprovação:

```sh
php artisan chat:whatsapp-auth-template ID_DA_EMPRESA --submit
```

O comando consulta templates existentes e não recria um template de mesmo nome/idioma. Só habilitar o envio após aprovação, confirmação do custo aplicável à WABA e teste com número de homologação. Nenhum preço foi presumido e nenhum template foi submetido nesta execução.

### Controles

| Controle | Implementado |
| --- | --- |
| Código | 6 dígitos com `random_int`, incluindo zeros iniciais |
| Armazenamento | HMAC com segredo da aplicação e ID do desafio; sem telefone em texto na tabela |
| Escopo | Empresa, telefone exato, finalidade fixa e nonce privado da sessão |
| Expiração / erros | 5 minutos / 5 tentativas |
| Reenvio | 60 segundos; invalida desafio anterior da mesma sessão e destino |
| Consumo | Transação e `lockForUpdate`; código de uso único |
| Limites adicionais | 5 envios/destino/empresa, 20/IP, 300/empresa e 1.000 globais em 10 minutos |
| Falha de envio | Invalida desafio e permite preencher compra, preservando carrinho |

Os limites globais dependem de cache compartilhado entre os servidores. O bloqueio por telefone expira em 10 minutos; tentativas de reenvio já bloqueadas não estendem essa janela.

## Sessão e dispositivo

Sessão verificada: 2 horas; sessão regenerada após código válido. Telefone alterado no perfil invalida a identidade antiga.

“Lembrar neste aparelho” nasce desmarcado. Cookie `Secure`, `HttpOnly`, `SameSite=Lax`; token aleatório de 64 caracteres, apenas SHA-256 no banco. Validade absoluta de 30 dias, rotação ao restaurar e checagem de revogação/expiração no servidor. Restauração não conta como verificação recente. “Sair e esquecer este aparelho” revoga dispositivo, identidade, autorizações de pedidos dessa empresa e nonce de desafios na sessão.

A interface de perfil não oferece alteração de telefone. Não foi acrescentado um fluxo de troca sem confirmar o novo número.

## Acompanhamento

Link de 7 dias com token aleatório de 64 caracteres, armazenado só como SHA-256. URL `/{empresa}/acompanhar#pedido=TOKEN`: o fragmento não é enviado no GET, é removido imediatamente no início do documento e resgatado por chamada Livewire. O tratamento de exceções não registra payloads Livewire.

Não há mais broadcast público por ID sequencial: o chat consulta status por polling autorizado (5 segundos para pagamento; 15 para acompanhamento). O painel mantém canais privados.

Página dedicada sem pixels, mapas ou fontes externas; política de referência `no-referrer`. Exibe apenas número e situação do pedido. O link não dá acesso a perfil, cartões, histórico, cancelamento, renovação de Pix ou mudança de pagamento. Expiração e revogação são conferidas também depois do resgate, a cada uso da autorização.

Desafios, dispositivos e acessos expirados são removidos diariamente após mais um dia. Nenhum código ou token é incluído nos logs adicionados; respostas de erro da Meta no envio de autenticação são substituídas por mensagem genérica antes de qualquer registro.

## Implantação e validações externas pendentes

1. Fazer backup e aplicar a migração aditiva antes de liberar a aplicação e reiniciar workers. Validar primeiro em homologação com o mesmo banco/cache/filas de produção. A migração foi exercitada pelos testes em SQLite; MySQL e concorrência distribuída precisam da homologação.
2. A migração não admite `down`: remover `buyer_data` perderia os dados de pedidos visitantes. Uma reversão deve preservar o esquema e usar versão da aplicação que mantenha visitante e bloqueio por telefone; nunca restaurar a identificação antiga.
3. Preparar/submeter template, aguardar aprovação, validar custos e entregar código a um aparelho de teste. Habilitar a configuração após essa validação. Até lá, visitante funciona e a recuperação apresenta alternativa de preenchimento.
4. Exercitar entrega, retirada, Pix e cartão com gateway de homologação, impressão, emissão fiscal, e-mails, cancelamento/estorno e restauração após falhas. Os testes locais usam integrações falsas.
5. Testar em celular com pessoas pouco familiarizadas com tecnologia, incluindo teclado, colar código, leitura, foco e falha de entrega. Esse teste com pessoas não foi realizado.
6. Fazer liberação gradual e acompanhar métricas agregadas de abandono, conversão, erro, sucesso de códigos e custo. Não foram coletadas métricas nem feita liberação nesta execução.

A criação explícita de um novo perfil a partir de uma compra visitante e um fluxo de troca de telefone permanecem fora da interface entregue. A verificação sem cadastro continua para preenchimento e não cria um perfil automaticamente.

## Evidência de validação local

- Suíte completa: **1.977 testes aprovados, 5.962 asserções**.
- Após a remoção do broadcast público: **149 testes aprovados** de canais, chat, pagamentos e auditoria de privacidade.
- Checagem final de chat, canais e cupons: **59 testes aprovados**.
- `npm run build`, Pint dos arquivos alterados e `git diff --check`: aprovados.
- Cobertura nova inclui compra completa via endpoint Livewire, manutenção de perfil existente, snapshots por pedido, serialização de jobs/e-mails de visitante, escopo e consumo de códigos, substituição/expiração/tentativas, isolamento entre empresas/sessões, link somente leitura e revogação após resgate, rotação de dispositivo sem estender validade, confirmação recente, template/categoria WhatsApp, opt-out e supressão de segredos no contexto de exceção.

Esses resultados não substituem envio real pela Meta, teste com gateway/banco de produção, teste distribuído de concorrência ou avaliação presencial no celular.
