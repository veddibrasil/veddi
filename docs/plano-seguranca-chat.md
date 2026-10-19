# Plano de segurança do chat e compra sem verificação obrigatória

Data: 19/10/2026  
Status: implementação local das proteções, compra visitante, recuperação por código e dispositivo lembrado; habilitação do WhatsApp e homologação pendentes. Ver [registro de implementação](seguranca-chat-implementacao.md).

## 1. Objetivo

Permitir que qualquer pessoa compre sem criar senha ou confirmar um código, exigindo confirmação do telefone para acessar dados de um cadastro existente.

Regras centrais:

- Saber um telefone não concede acesso ao cadastro correspondente.
- Dados preenchidos por um visitante pertencem àquela compra e não sobrescrevem um cadastro existente.
- Acesso a um pedido específico não libera o cadastro completo.
- Toda autorização é validada no servidor, inclusive em chamadas diretas ao Livewire.
- A falha do serviço de envio de códigos não impede uma compra como visitante.

## 2. Situação atual observada

O fluxo atual identifica o cliente pelo telefone, sem confirmar sua posse. Foram identificados os seguintes pontos:

- `HasCustomerProfile::submitPhone()` carrega dados do cliente e oferece recuperação de pedido.
- `fillCustomerData()` coloca nome, e-mail, endereço e CPF em propriedades públicas do Livewire. Essas informações chegam ao navegador mesmo que a interface não as exiba.
- Existe limite de 30 consultas por IP e empresa em 10 minutos. Ele reduz enumeração, mas não impede uma consulta direcionada a um telefone conhecido.
- `submitAddress()` usa `updateOrCreate` por empresa e telefone, podendo atualizar um cadastro existente.
- A confirmação do endereço de entrega também pode atualizar o cliente.
- O fluxo de pagamento inclui cartões salvos e precisa participar da revisão de autorização.
- A restauração de sessão precisa distinguir carrinho salvo de identidade verificada.
- A busca global entre empresas ainda pode reaproveitar o nome de outro cadastro.
- Há testes de auditoria desativados aguardando a decisão de verificar a posse do telefone.

Essa análise orienta o plano. A implementação deve completar o inventário de endpoints, serviços, eventos e integrações envolvidos.

## 3. Situações de acesso

| Situação | Permissões |
| --- | --- |
| Visitante | Navegar, montar carrinho e preencher uma nova compra |
| Visitante com acesso ao próprio pedido | Acompanhar e executar as ações autorizadas naquele pedido |
| Cliente com telefone verificado | Recuperar dados salvos e acessar recursos do cadastro na empresa autorizada |

Um `customerId` no componente não constitui autenticação. O servidor deve manter a identidade verificada, seu escopo e sua validade separadamente dos dados editáveis da interface.

## 4. Fluxo completo de compra

### 4.1. Caminho principal

1. Escolher produtos e observações.
2. Revisar o carrinho.
3. Selecionar entrega ou retirada.
4. Informar telefone.
5. Escolher entre **Preencher meus dados** e **Usar meus dados salvos**.
6. Preencher ou confirmar os dados necessários.
7. Conferir frete, prazo, descontos e total.
8. Escolher pagamento e concluir.
9. Acompanhar o pedido.

As duas opções do passo 5 aparecem independentemente da existência de cadastro. A interface não deve confirmar se determinado telefone já está cadastrado.

Para retirada, não solicitar endereço. Solicitar e-mail e CPF somente quando necessários para a operação escolhida.

### 4.2. Preencher meus dados

- Caminho principal para quem deseja apenas comprar.
- Coletar somente as informações necessárias à compra.
- Não devolver dados de um cadastro anterior.
- Não alterar nome, endereço, CPF, consentimentos ou cartões de um cliente existente.
- Permitir os meios de pagamento compatíveis com visitante.
- Preservar o carrinho durante correções, navegação e recuperação de falhas.
- Ao concluir, conceder acesso específico ao pedido criado.

### 4.3. Usar meus dados salvos

1. Confirmar o telefone de destino.
2. Solicitar envio do código.
3. Exibir campo único, com suporte a colar e preenchimento automático quando disponível.
4. Validar o código no servidor.
5. Criar uma sessão verificada para aquela empresa.
6. Carregar somente os dados necessários para continuar.
7. Oferecer confirmação do endereço salvo ou outro endereço para aquela compra.

Se não existir cadastro após a verificação, continuar para o preenchimento. Manter sempre a opção **Continuar preenchendo meus dados**, sem perder o carrinho.

## 5. Separação entre compra e cadastro

O pedido deve preservar seus próprios dados de contato e entrega, independentemente de alterações futuras no perfil:

- Nome de contato.
- Telefone informado e indicação de sua verificação, quando houver.
- E-mail, quando necessário.
- Endereço de entrega e coordenadas, quando aplicáveis.
- Dados fiscais estritamente necessários.

Já existe uma estrutura de endereço por pedido que pode ser aproveitada. Revisar os demais dados e todos os consumidores: painel, impressão, notificações, cobrança e integrações.

Preferência de arquitetura: permitir comprador visitante separado do cadastro permanente. Adaptar os serviços que hoje exigem `customer_id`, sem criar cadastros duplicados artificiais ou associar automaticamente a compra ao dono do número.

Antes da migração, definir os campos e a compatibilidade com pedidos antigos. O preenchimento de um pedido não deve alterar silenciosamente o perfil, mesmo para clientes verificados; salvar no perfil deve ser uma ação explícita.

## 6. Verificação do telefone

Parâmetros iniciais propostos, sujeitos a ajuste conforme uso real:

| Controle | Proposta |
| --- | --- |
| Código | 6 dígitos aleatórios, gerados com fonte criptograficamente segura |
| Validade | 5 minutos |
| Tentativas | Até 5 por desafio |
| Reenvio | Intervalo mínimo de 60 segundos |
| Reutilização | Código consumido após sucesso |
| Novo envio | Invalida o código anterior |
| Limites complementares | Por telefone, IP, empresa e volume global |

Requisitos técnicos:

- Vincular o desafio ao telefone normalizado, empresa, finalidade e sessão solicitante.
- Armazenar somente representação protegida do código, com chave secreta do servidor, evitando hash simples de um espaço pequeno de seis dígitos.
- Nunca registrar códigos em logs ou ferramentas de monitoramento.
- Consumir o desafio de forma atômica para impedir reutilização simultânea.
- Impedir que alterações no telefone reaproveitem um desafio anterior.
- Não permitir que ataques a um número bloqueiem indefinidamente seu titular.
- Retornar mensagens que não revelem a existência de cadastro.

Criar uma interface de envio independente do fornecedor. Verificar disponibilidade, configuração, requisitos e custo de mensagens de autenticação antes de escolher WhatsApp, SMS ou ambos. Não presumir que a integração atual de notificações atende autenticação.

## 7. Sessão e dispositivo lembrado

Após confirmar o código:

- Regenerar a sessão.
- Registrar no servidor empresa, identidade confirmada, momento e validade da verificação.
- Limitar o acesso à empresa autorizada.
- Oferecer **Lembrar neste aparelho**, desmarcado por padrão.
- Disponibilizar **Sair e esquecer este aparelho**.

Proposta inicial para dispositivo lembrado:

- Validade máxima de 30 dias.
- Token aleatório em cookie `Secure`, `HttpOnly` e `SameSite` adequado ao fluxo.
- Representação protegida do token no servidor.
- Rotação, revogação e expiração verificadas no servidor.
- Política de validade que não prolongue o acesso indefinidamente por rotação.

Exigir confirmação recente para uso de cartão salvo e alteração de telefone. Na troca de telefone, confirmar o novo número e revogar acessos afetados. O prazo de confirmação recente deve ser definido antes de implementar esses fluxos.

Não restaurar autorização a partir de um estado antigo do componente ou de dados enviados pelo navegador.

## 8. Matriz de autorização

| Ação | Autorização necessária |
| --- | --- |
| Consultar cardápio e montar carrinho | Pública |
| Criar compra com dados preenchidos | Sessão de visitante e validações da compra |
| Consultar endereço ou CPF salvo | Cliente verificado na empresa |
| Editar cadastro | Cliente verificado na empresa |
| Consultar histórico | Cliente verificado na empresa |
| Recuperar pedido anterior | Cliente verificado ou acesso específico ao pedido |
| Listar ou usar cartão salvo | Cliente verificado e confirmação recente |
| Acompanhar pedido recém-criado | Acesso específico ao pedido |
| Cancelar ou alterar pagamento | Acesso ao pedido e regras da operação |

Centralizar as regras e aplicá-las nos métodos do chat e nos serviços executores. Propriedades `Locked` do Livewire não substituem autorização.

Antes da verificação, informações de cadastro não podem aparecer em propriedades públicas, HTML, snapshots, respostas JSON ou eventos enviados ao navegador.

## 9. Acesso ao pedido do visitante

Na criação, conceder acesso ao pedido à sessão que o criou. Para recuperação em outro navegador, disponibilizar link exclusivo com token longo e aleatório.

Requisitos:

- Escopo de um pedido e uma empresa.
- Token protegido no armazenamento, com validade definida e revogação.
- Nenhum acesso a perfil, histórico ou cartões.
- Exibir somente os dados necessários ao acompanhamento.
- Não registrar o token em logs ou enviá-lo a ferramentas de análise.
- Evitar vazamento por referências e recursos externos na página de acompanhamento.
- Diferenciar leitura de ações sensíveis.

O link de acompanhamento não deve automaticamente permitir alterar endereço ou cancelar pedido. Definir as permissões de cada operação conforme estado do pedido.

O número sequencial do pedido permanece uma referência de atendimento, sem funcionar como credencial.

## 10. Integrações e regras de negócio

### Pagamentos

- Usar os dados da compra no fluxo de visitante.
- Não reutilizar cartões ou identificadores de cobrança de outro cadastro por coincidência de telefone.
- Validar autorização em consulta de cobrança, geração de Pix, troca de pagamento e cancelamento.
- Preservar idempotência de criação de pedido e cobrança.
- Revisar a relação entre comprador visitante, cliente do gateway e armazenamento de cartões.

### Cupons e benefícios

- Manter cupons públicos disponíveis a visitantes.
- Exigir verificação para benefícios pessoais.
- Definir o comportamento de regras de primeira compra e limites por cliente no fluxo visitante.

### Notificações e consentimento

- Não reativar consentimentos de um cadastro existente pela digitação do número.
- Separar avisos operacionais da compra de preferências permanentes.
- Definir quais mensagens podem ser enviadas a um telefone ainda não verificado e seus limites contra abuso.
- Não incluir dados de histórico ou cadastro anterior em mensagens originadas por uma compra visitante.

### Painel, impressão e histórico

- Exibir os dados registrados no pedido, sem depender do endereço atual do cadastro.
- Identificar operacionalmente pedidos de visitante sem presumir identidade confirmada.
- Remover reaproveitamento automático de cadastro entre empresas no chat.
- Não incorporar automaticamente pedidos antigos de visitante a um cadastro só porque o telefone foi confirmado depois. Qualquer vínculo futuro exige uma regra explícita de comprovação.

## 11. Organização da implementação

| Área | Alteração prevista |
| --- | --- |
| `app/Livewire/Chat/Concerns/HasCustomerProfile.php` | Separar dados da compra, verificação e edição de perfil |
| `app/Livewire/Chat/OrderChat.php` | Novos estados e restauração condicionada à autorização |
| `app/Livewire/Chat/Concerns/HasOrderFlow.php` | Usar dados da compra sem atualizar automaticamente o cadastro |
| `app/Livewire/Chat/Concerns/HasOrderRecovery.php` | Exigir identidade verificada ou acesso específico |
| `app/Livewire/Chat/Concerns/HasPaymentFlow.php` | Proteger cartões, cobranças e ações sobre pedidos |
| `app/Services/Customer/CustomerService.php` | Separar consulta autorizada e atualização de cadastro |
| Serviço de pedidos e modelos | Suportar visitante e preservar dados próprios da compra |
| Views e JavaScript do chat | Opções de preenchimento, código e recuperação de falhas |

Criar serviços próprios para desafios de verificação, sessões de cliente e acesso a pedidos. Os nomes finais e as tabelas serão definidos após revisar as convenções do projeto.

Dados novos esperados:

- Desafios de verificação com destino protegido, escopo, expiração, tentativas e consumo.
- Dispositivos lembrados com token protegido, escopo, validade e revogação.
- Autorizações de acesso específico a pedidos.
- Dados de comprador visitante e indicação de verificação no contexto da compra.

Definir retenção e limpeza dos registros temporários, índices e operações atômicas. Manter compatibilidade com pedidos e integrações existentes durante a migração.

## 12. Validação e critérios de aceitação

### Segurança

- [ ] Informar telefone conhecido não revela dados nem existência de cadastro.
- [ ] Dados privados não aparecem nos snapshots ou respostas antes da autorização.
- [ ] Compra visitante não altera cliente existente.
- [ ] Chamadas diretas ao Livewire respeitam as mesmas restrições da interface.
- [ ] Troca de identificadores não acessa outro cliente, pedido ou empresa.
- [ ] Código expirado, reutilizado, incorreto ou substituído é recusado.
- [ ] Código não funciona para outro telefone, empresa, finalidade ou sessão.
- [ ] Requisições simultâneas não reutilizam desafios nem duplicam pedidos ou cobranças.
- [ ] Sessão antiga, expirada ou revogada não recupera acesso.
- [ ] Visitante não lista nem utiliza cartões salvos.
- [ ] Link de pedido não libera cadastro nem ações além do seu escopo.
- [ ] Tokens e códigos não aparecem em logs.

### Fluxo e regressão

- [ ] Compra de visitante funciona do início ao fim sem código.
- [ ] Falha no envio permite continuar preenchendo os dados.
- [ ] Carrinho permanece após código expirado, reenvio, retorno e atualização da página.
- [ ] Entrega, retirada, agendamento, Pix, cartão, cupons e acompanhamento continuam funcionando.
- [ ] Painel, impressão e notificações usam os dados corretos da compra.
- [ ] Edição do perfil não altera o retrato de pedidos anteriores.
- [ ] Pedidos antigos continuam acessíveis pelos caminhos autorizados.
- [ ] Testes de auditoria antes desativados por falta de verificação são habilitados e aprovados.

Realizar teste prático em celular com pessoas pouco familiarizadas com tecnologia: compra sem ajuda, entendimento das duas opções, preenchimento do código e recuperação após falha. Avaliar acessibilidade, teclado, foco, mensagens e leitura em telas pequenas.

## 13. Etapas de entrega

### Etapa 1 — Inventário e estrutura

- Completar mapa de acessos e dependências de `customer_id`.
- Definir contratos de autorização e dados do comprador visitante.
- Preparar migrações compatíveis e serviços centrais.
- Definir regras pendentes de pagamento, benefícios e acesso ao pedido.

Entrega: estrutura revisável e critérios de aceitação preparados.

### Etapa 2 — Compra visitante e fechamento do acesso por telefone

- Entregar preenchimento da compra sem alterar cadastro.
- Retirar carregamento de dados e recuperação de pedido por telefone isolado.
- Bloquear perfil, histórico e cartões sem verificação.
- Conceder acesso ao pedido recém-criado.
- Remover busca global automática do chat.

Entrega: compra completa sem código e sem exposição do cadastro. Essas mudanças devem entrar juntas para manter o fechamento funcional.

### Etapa 3 — Recuperação por código

- Integrar canal de autenticação validado.
- Implementar desafios, limites, telas e sessão verificada.
- Liberar dados salvos, edição e recuperação autorizada.

Entrega: recuperação de informações sem impedir o caminho visitante.

### Etapa 4 — Dispositivo lembrado

- Implementar persistência opcional, rotação e revogação.
- Exigir confirmação recente nas ações definidas.
- Entregar saída e opção de esquecer aparelho.

Entrega: conveniência nas próximas compras com controle de validade.

### Etapa 5 — Expansão e acompanhamento

- Validar em ambiente de homologação e iniciar liberação gradual.
- Acompanhar erros, conclusão da compra, abandono, tempo de fechamento, sucesso da verificação e custo de mensagens.
- Usar métricas agregadas, sem registrar dados pessoais desnecessários.
- Ajustar textos e limites com base nas evidências.

## 14. Migração e reversão

- Invalidar identificações antigas obtidas apenas por telefone.
- Preservar carrinhos quando possível, sem restaurar dados privados ou autorização antiga.
- Não marcar automaticamente clientes antigos como verificados.
- Preservar pedidos antigos com regras explícitas de compatibilidade.
- Testar migrações e implantação na ordem correta antes da produção.
- Em reversão, manter compra visitante e desabilitar recuperação do cadastro se necessário. Não reabrir o acesso apenas por telefone.

## 15. Decisões a concluir durante a implementação

| Decisão | Diretriz proposta |
| --- | --- |
| Canal de código | Validar integração de autenticação e custos antes de escolher |
| Prazo de confirmação recente | Definir para cartões salvos e alterações sensíveis |
| Validade do acesso ao pedido | Definir conforme operação e retenção do pedido |
| Modelo do comprador visitante | Separar do perfil permanente e adaptar dependências existentes |
| Cupons pessoais e primeira compra | Exigir identidade quando o benefício depender do cliente |
| Mensagens a telefone não verificado | Minimizar conteúdo e limitar abuso |
| Vínculo futuro de pedidos visitantes | Não vincular automaticamente por telefone |
| Retenção de desafios e dispositivos | Definir limpeza e mínimo necessário para operação e auditoria |

## 16. Resultado esperado

A pessoa que deseja apenas comprar consegue concluir preenchendo os dados necessários, sem senha ou código. Quem deseja recuperar dados confirma o telefone. Nenhum caminho permite acessar ou modificar informações de outra pessoa apenas conhecendo seu número.
