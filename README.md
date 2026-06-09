# Veddi

Plataforma SaaS multiempresa para operação de pedidos, cardápio e gestão financeira de negócios de alimentação.

## O que é

O Veddi permite que negócios de alimentação — como lanchonetes, deliveries e food trucks — tenham sua própria loja digital com chat de pedidos, cardápio online, controle de estoque e gestão financeira integrada.

Cada empresa cadastrada opera de forma isolada, com seu próprio slug, cardápio, filiais e carteira.

## Funcionalidades

### Para o cliente final
- Acessa a loja pelo link da empresa (`/{slug}`)
- Monta o pedido via chat interativo
- Escolhe entre entrega ou retirada
- Paga via PIX ou cartão de crédito (parcelado)
- Acompanha o status do pedido em tempo real

### Para a empresa
- Painel administrativo completo (`/admin`)
- Gestão de cardápio, categorias, estoque e filiais
- Acompanhamento de pedidos em tempo real
- Cupons de desconto
- Relatórios de vendas
- Carteira com saldo, retiradas e antecipação de recebíveis
- Suporte via chat

### Para a plataforma (super admin)
- Governança de todas as empresas
- Gestão de usuários e permissões
- Configuração de taxas
- Simulação de pagamentos

## Planos

| Plano | Mensalidade | Taxa por pedido | Filiais |
|-------|------------|----------------|---------|
| Free | Grátis | 1% | 1 |
| Essencial | R$59/mês | 0% | 1 |
| Pro | R$119/mês | 0% | 3 |

Todos os planos pagos têm taxa de ativação de R$99.

## Stack

- **Backend:** Laravel 12, PHP 8.2+
- **Frontend:** Livewire 4, Flux 2, Tailwind CSS 4, Vite 7
- **Banco de dados:** MySQL (SQLite em testes)
- **Tempo real:** Laravel Reverb (WebSockets)
- **Pagamentos:** Asaas (assinaturas) + Vindi (PIX, cartão, boleto)
- **WhatsApp:** Z-API
- **Testes:** Pest 3

## Instalação

> Requisito: extensão PHP `gmp` (usada pela integração com Stark Bank/assinaturas ECDSA).  
> Se estiver usando Sail: `./vendor/bin/sail build --no-cache`

```bash
# Clone e instale dependências
composer setup

# Suba o ambiente completo (servidor, queue, Vite, Reverb)
composer dev
```

## Desenvolvimento

```bash
composer lint        # Formatar código (Pint)
composer lint:check  # Verificar lint sem alterar
php artisan test     # Rodar testes
npm run build        # Build de assets
```

## Documentação

Documentação técnica detalhada (rotas, modelos, serviços, fluxos, APIs): [`docs/sistema.md`](docs/sistema.md)

## Variáveis de Ambiente

Copie `.env.example` para `.env` e configure:

- `ASAAS_API_KEY` — gateway de pagamento
- `STARK_PROJECT_ID` + `STARK_PRIVATE_KEY` — PIX via Stark Bank
- `ZAPI_INSTANCE_ID` + `ZAPI_TOKEN` — WhatsApp
- `REVERB_*` — WebSockets

Veja `.env.example` para lista completa.
