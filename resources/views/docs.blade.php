<x-layouts::docs title="Documentação">
    @php
        $docMedia = function (string $name): ?array {
            $path = public_path("images/docs/{$name}");
            if (file_exists($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $type = in_array($ext, ['mov', 'mp4', 'webm']) ? 'video' : 'image';
                return ['url' => asset("images/docs/{$name}"), 'type' => $type];
            }
            return null;
        };
    @endphp

{{-- Header --}}
<header class="sticky top-0 z-40 bg-white border-b border-zinc-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center gap-4">
        <x-app-logo href="{{ route('docs') }}" class="shrink-0" />
        <span class="text-zinc-300 hidden sm:block">|</span>
        <span class="text-sm font-semibold text-zinc-500">Documentação</span>
        <button
            type="button"
            class="ml-auto lg:hidden flex items-center gap-2 text-sm text-zinc-600 border border-zinc-200 rounded-lg px-3 py-1.5"
            onclick="document.getElementById('mobile-nav').classList.toggle('hidden')"
        >
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
            Menu
        </button>
    </div>
    <nav id="mobile-nav" class="hidden lg:hidden border-t border-zinc-100 bg-white px-4 pb-4 pt-2 grid grid-cols-2 gap-1">
        <a href="#visao-geral" class="nav-link">Visão Geral</a>
        <a href="#onboarding" class="nav-link">Cadastro de Empresa</a>
        <a href="#planos" class="nav-link">Planos</a>
        <a href="#chat-pedidos" class="nav-link">Chat de Pedidos</a>
        <a href="#painel-admin" class="nav-link">Dashboard & Pedidos</a>
        <a href="#kanban" class="nav-link">Kanban de Pedidos</a>
        <a href="#cardapio" class="nav-link">Cardápio</a>
        <a href="#estoque" class="nav-link">Estoque</a>
        <a href="#filiais" class="nav-link">Filiais & Entrega</a>
        <a href="#impressora" class="nav-link">Impressora</a>
        <a href="#cupons" class="nav-link">Cupons</a>
        <a href="#relatorios" class="nav-link">Relatórios</a>
        <a href="#pdv" class="nav-link">PDV</a>
        <a href="#fiscal" class="nav-link">Notas Fiscais</a>
        <a href="#carteira" class="nav-link">Carteira</a>
        <a href="#faturamento" class="nav-link">Faturamento</a>
        <a href="#usuarios" class="nav-link">Usuários & Papéis</a>
        <a href="#configuracoes" class="nav-link">Configurações</a>
        <a href="#pagamentos" class="nav-link">Pagamentos</a>
    </nav>
</header>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 lg:flex lg:gap-8">

    {{-- Sidebar (desktop) --}}
    <aside class="hidden lg:block shrink-0 w-56">
        <nav class="sticky top-20 space-y-0.5">
            <p class="text-xs font-bold text-zinc-400 uppercase tracking-widest px-3 mb-2">Geral</p>
            <a href="#visao-geral" class="nav-link">Visão Geral</a>
            <a href="#onboarding" class="nav-link">Cadastro de Empresa</a>
            <a href="#planos" class="nav-link">Planos</a>
            <a href="#chat-pedidos" class="nav-link">Chat de Pedidos</a>

            <p class="text-xs font-bold text-zinc-400 uppercase tracking-widest px-3 mb-2 mt-4">Painel Admin</p>
            <a href="#painel-admin" class="nav-link">Dashboard & Pedidos</a>
            <a href="#kanban" class="nav-link">Kanban de Pedidos</a>
            <a href="#cardapio" class="nav-link">Cardápio</a>
            <a href="#estoque" class="nav-link">Estoque</a>
            <a href="#filiais" class="nav-link">Filiais & Entrega</a>
            <a href="#impressora" class="nav-link">Impressora</a>
            <a href="#cupons" class="nav-link">Cupons</a>
            <a href="#relatorios" class="nav-link">Relatórios</a>
            <a href="#pdv" class="nav-link">PDV</a>
            <a href="#fiscal" class="nav-link">Notas Fiscais</a>
            <a href="#carteira" class="nav-link">Carteira</a>
            <a href="#faturamento" class="nav-link">Faturamento</a>
            <a href="#usuarios" class="nav-link">Usuários & Papéis</a>
            <a href="#configuracoes" class="nav-link">Configurações</a>

            <p class="text-xs font-bold text-zinc-400 uppercase tracking-widest px-3 mb-2 mt-4">Plataforma</p>
            <a href="#pagamentos" class="nav-link">Pagamentos</a>
        </nav>
    </aside>

    {{-- Content --}}
    <main class="flex-1 space-y-12 min-w-0">

        {{-- Visão Geral --}}
        <section id="visao-geral" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-purple-100 shrink-0">
                        <svg class="size-6 text-purple-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Visão Geral</h2>
                        <p class="mt-1 text-sm text-zinc-500">O que é o Veddi e como o sistema está organizado</p>
                    </div>
                </div>
                <div class="mt-5 grid sm:grid-cols-2 gap-4">
                    <div class="info-box">
                        <p class="sub-title">O que é o Veddi</p>
                        <p class="text-sm text-zinc-600">O Veddi é uma plataforma SaaS completa para restaurantes, lanchonetes e negócios de alimentação. Centraliza pedidos online, gerenciamento de cardápio, controle de estoque e operações financeiras em um único sistema multiempresa.</p>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">O que você pode fazer</p>
                        <ul class="list">
                            <li class="text-sm text-zinc-600">Cadastrar sua empresa e começar a receber pedidos</li>
                            <li class="text-sm text-zinc-600">Gerenciar cardápio, estoque e filiais pelo painel</li>
                            <li>Acompanhar vendas, relatórios e financeiro</li>
                            <li class="text-sm text-zinc-600">Receber o dinheiro das vendas com PIX</li>
                        </ul>
                    </div>
                    <div class="info-box sm:col-span-2">
                        <p class="sub-title mb-2">Quem usa o sistema</p>
                        <div class="grid flex-wrap gap-4 text-sm text-zinc-600 mt-1">
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-green-400 inline-block"></span><strong>Clientes</strong> — fazem pedidos pelo link público da loja</span>
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-purple-500 inline-block"></span><strong>Administradores</strong> — gerenciam todo o negócio pelo painel</span>
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-indigo-400 inline-block"></span><strong>Gerentes de unidade</strong> — acompanham pedidos e estoque da sua filial</span>
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-400 inline-block"></span><strong>Equipe Veddi</strong> — administra a plataforma como um todo</span>
                        </div>
                    </div>
                    <div class="info-box sm:col-span-2">
                        <p class="sub-title">Áreas do sistema</p>
                        <div class="grid sm:grid-cols-3 gap-3 mt-1">
                            <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                <p class="font-semibold text-xs text-zinc-700 mb-1">Chat público <code class="text-purple-700 font-normal">/{slug}</code></p>
                                <p class="text-xs text-zinc-500">Onde seus clientes acessam o cardápio e fazem pedidos. Cada empresa tem seu endereço único.</p>
                            </div>
                            <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                <p class="font-semibold text-xs text-zinc-700 mb-1">Painel Admin <code class="text-purple-700 font-normal">/admin</code></p>
                                <p class="text-xs text-zinc-500">Para administradores e gerentes gerenciarem pedidos, cardápio, estoque, financeiro e configurações.</p>
                            </div>
                          
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Onboarding --}}
        <section id="onboarding" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-green-100 shrink-0">
                        <svg class="size-6 text-green-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Cadastro de Empresa</h2>
                        <p class="mt-1 text-sm text-zinc-500">Como uma nova empresa entra no Veddi <span class="font-mono text-purple-700 text-xs">/cadastro</span></p>
                    </div>
                </div>
                <div class="mt-5 space-y-4">
                    <div class="flex gap-3 items-start">
                        <span class="step-num">1</span>
                        <div>
                            <p class="font-semibold text-sm text-zinc-700">Preencha o formulário de cadastro</p>
                            <p class="text-sm text-zinc-500 mt-0.5">Informe nome e dados da empresa, endereço da sua primeira unidade, crie uma senha de acesso e escolha o plano desejado (Grátis, Essencial ou PRO).</p>
                        </div>
                    </div>
                    <div class="flex gap-3 items-start">
                        <span class="step-num">2</span>
                        <div>
                            <p class="font-semibold text-sm text-zinc-700">O sistema cria tudo automaticamente</p>
                            <p class="text-sm text-zinc-500 mt-0.5">Em um único processo, o sistema cria sua empresa, a primeira filial e seu usuário com acesso de administrador. Um cliente também é criado na Asaas para cobranças futuras.</p>
                        </div>
                    </div>
                    <div class="flex gap-3 items-start">
                        <span class="step-num">3</span>
                        <div>
                            <p class="font-semibold text-sm text-zinc-700">Plano Grátis: acesso imediato</p>
                            <p class="text-sm text-zinc-500 mt-0.5">No plano gratuito, sua empresa é ativada na hora. Você já pode entrar no painel e configurar sua loja.</p>
                        </div>
                    </div>
                    <div class="flex gap-3 items-start">
                        <span class="step-num">4</span>
                        <div>
                            <p class="font-semibold text-sm text-zinc-700">Planos pagos: aguarde o pagamento da taxa de adesão</p>
                            <p class="text-sm text-zinc-500 mt-0.5">Uma taxa de adesão única é cobrada via PIX. A tela exibe o QR Code e o código copia e cola. O sistema monitora o pagamento automaticamente e libera o acesso assim que confirmado — sem você precisar fazer nada.</p>
                        </div>
                    </div>
                </div>
                <div class="mt-4 info-box-amber">
                    <p class="text-xs text-amber-700"><strong>Atenção:</strong> A taxa de adesão é cobrada uma única vez ao se cadastrar em plano pago. Downgrades para o plano Grátis não recebem reembolso dessa taxa.</p>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('onboarding.mov') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Cadastro de empresa" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/onboarding.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Planos --}}
        <section id="planos" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-amber-100 shrink-0">
                        <svg class="size-6 text-amber-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Planos e Limites</h2>
                        <p class="mt-1 text-sm text-zinc-500">O plano define o que sua empresa pode usar no Veddi</p>
                    </div>
                </div>
                <div class="mt-5 grid sm:grid-cols-3 gap-4">
                    <div class="info-box">
                        <p class="sub-title">Grátis</p>
                        <ul class="list">
                            <li>Até <strong>50 pedidos por mês</strong></li>
                            <li>Apenas <strong>1 filial</strong></li>
                            <li>Taxa por pedido (percentual sobre as vendas)</li>
                            <li>Sem mensalidade</li>
                            <li>Sem personalização de cores e logo</li>
                            <li>Sem entrega por distância</li>
                        </ul>
                    </div>
                    <div class="info-box border-purple-200 bg-purple-50">
                        <p class="sub-title text-purple-800">Essencial</p>
                        <ul class="list">
                            <li>Pedidos <strong>ilimitados</strong></li>
                            <li>Até <strong>2 filiais</strong></li>
                            <li>Taxa por pedido (menor que o Grátis)</li>
                            <li>Mensalidade recorrente</li>
                            <li>Personalização completa de marca</li>
                            <li>Antecipação de recebíveis disponível</li>
                        </ul>
                    </div>
                    <div class="info-box border-yellow-200 bg-yellow-50">
                        <p class="sub-title text-yellow-800">PRO</p>
                        <ul class="list">
                            <li>Pedidos <strong>ilimitados</strong></li>
                            <li>Filiais <strong>ilimitadas</strong></li>
                            <li>Menor taxa por pedido</li>
                            <li>Mensalidade recorrente</li>
                            <li>Personalização completa de marca</li>
                            <li>Entrega por distância (km) habilitada</li>
                            <li>Antecipação de recebíveis disponível</li>
                        </ul>
                    </div>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="text-left border-b border-zinc-200">
                                <th class="pb-2 font-semibold text-zinc-700 pr-4">Conceito</th>
                                <th class="pb-2 font-semibold text-zinc-700">O que significa</th>
                            </tr>
                        </thead>
                        <tbody class="text-zinc-600 divide-y divide-zinc-100">
                            <tr><td class="py-2 pr-4 font-medium text-zinc-700">Mensalidade</td><td class="py-2">Valor fixo pago todo mês para usar o sistema. Cobrado automaticamente via Asaas.</td></tr>
                            <tr><td class="py-2 pr-4 font-medium text-zinc-700">Taxa por pedido</td><td class="py-2">Percentual descontado de cada venda. Calculado sobre o subtotal dos produtos (não inclui frete).</td></tr>
                            <tr><td class="py-2 pr-4 font-medium text-zinc-700">Taxa de adesão</td><td class="py-2">Cobrada uma única vez ao migrar do Grátis para um plano pago.</td></tr>
                            <tr><td class="py-2 pr-4 font-medium text-zinc-700">Limite de pedidos</td><td class="py-2">No plano Grátis, ao atingir 50 pedidos no mês, o sistema envia um alerta. Novos pedidos ainda entram, mas você receberá notificações para fazer upgrade.</td></tr>
                            <tr><td class="py-2 pr-4 font-medium text-zinc-700">Limite de filiais</td><td class="py-2">Número máximo de unidades que você pode cadastrar. Ao tentar criar além do limite, o botão de criar fica desabilitado.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-sm text-zinc-500">Para ver ou alterar seu plano, acesse o painel e clique em <span class="font-medium text-zinc-700">Assinatura</span>.</p>

                    <div class="doc-media">
                    @php $media = $docMedia('assinatura.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Comparativo de planos" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/planos.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Chat de Pedidos --}}
        <section id="chat-pedidos" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-blue-100 shrink-0">
                        <svg class="size-6 text-blue-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Chat Público de Pedidos</h2>
                        <p class="mt-1 text-sm text-zinc-500">Como o cliente final realiza um pedido <span class="font-mono text-purple-700 text-xs">/{slug-da-empresa}</span></p>
                    </div>
                </div>

                <div class="mt-5 space-y-4">
                    <p class="text-sm text-zinc-600">O fluxo de pedido é guiado passo a passo em formato de chat. O cliente nunca precisa criar conta — basta informar o telefone. Cada etapa só avança após validação no servidor.</p>

                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">1. Identificação do cliente</p>
                            <ul class="list">
                                <li>O cliente informa o <strong>telefone</strong> (10 ou 11 dígitos)</li>
                                <li>Se já fez pedido antes, é reconhecido automaticamente</li>
                                <li>Se for novo: informa nome e e-mail para cadastro</li>
                                <li>Para entrega: endereço completo com CEP</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">2. Seleção de unidade e cardápio</p>
                            <ul class="list">
                                <li>Se houver mais de uma filial aberta, o cliente escolhe qual unidade quer</li>
                                <li>Se apenas uma estiver aberta, a seleção é automática</li>
                                <li>O cardápio exibe categorias e produtos da filial escolhida</li>
                                <li>Cada produto pode ter <strong>opcionais e complementos</strong> para o cliente personalizar</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">3. Carrinho e cupom</p>
                            <ul class="list">
                                <li>O cliente adiciona itens ao carrinho com suas opções</li>
                                <li>Pode aplicar um <strong>código de cupom</strong> de desconto</li>
                                <li>O sistema valida o cupom em tempo real: verifica validade, limite de usos, valor mínimo e escopo</li>
                                <li>O valor do desconto é exibido antes da confirmação</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">4. Tipo de pedido</p>
                            <ul class="list">
                                <li><strong>Entrega:</strong> o cliente confirma o endereço. A taxa de entrega é calculada automaticamente conforme configuração da filial</li>
                                <li><strong>Retirada:</strong> o cliente retira no local. Sem taxa de entrega, sem etapa de endereço</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">5. Pagamento</p>
                            <ul class="list">
                                <li><strong>PIX:</strong> o sistema gera QR Code e código copia e cola. O cliente paga pelo app do banco e a confirmação acontece automaticamente</li>
                                <li><strong>Cartão de crédito:</strong> o cliente informa os dados do cartão diretamente no chat. Pagamento processado de forma transparente</li>
                                <li>O sistema exibe o detalhamento de taxas antes de confirmar</li>
                                <li>O pagamento PIX expira em 30 minutos</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">6. Após o pedido</p>
                            <ul class="list">
                                <li>O status do pedido é atualizado em tempo real na tela do cliente</li>
                                <li>O cliente pode acompanhar: aguardando pagamento → pago → em preparo → pronto → entregue</li>
                                <li>Se o cliente tiver e-mail cadastrado, recebe um e-mail de confirmação do pedido</li>
                            </ul>
                        </div>
                    </div>

                    <div class="info-box-blue">
                        <p class="sub-title text-blue-800">Validações do servidor</p>
                        <p class="text-sm text-zinc-600">Todos os preços, disponibilidade de produtos, validade de cupons e cálculo de frete são verificados no servidor no momento da criação do pedido. O valor exibido na tela é sempre o valor cobrado — o cliente nunca é surpreendido.</p>
                    </div>

                    <div class="info-box">
                        <p class="sub-title">Persistência da sessão</p>
                        <p class="text-sm text-zinc-600">O estado do chat (itens no carrinho, dados do cliente) fica salvo durante a navegação. Se o cliente fechar e reabrir a página, o carrinho é recuperado automaticamente.</p>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('chat.mov') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Chat público de pedidos" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/chat.mov</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Dashboard e Pedidos --}}
        <section id="painel-admin" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-purple-100 shrink-0">
                        <svg class="size-6 text-purple-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0H3"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Dashboard e Pedidos</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                            <span class="badge bg-indigo-100 text-indigo-700">branch_manager</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="info-box">
                        <p class="sub-title">Dashboard <span class="font-mono text-purple-700 text-xs font-normal">/admin/dashboard</span></p>
                        <ul class="list">
                            <li>Painel inicial com resumo do dia: total de pedidos, receita e saldo disponível na carteira</li>
                            <li>Quantos pedidos estão pendentes neste momento</li>
                            <li>Empresas no plano Grátis visualizam quanto do limite mensal de 50 pedidos já foi utilizado</li>
                            <li>Os números são atualizados automaticamente — sem precisar recarregar a página</li>
                            <li>Gerentes de unidade visualizam apenas os dados da sua filial</li>
                        </ul>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Lista de Pedidos <span class="font-mono text-purple-700 text-xs font-normal">/admin/orders</span></p>
                        <ul class="list">
                            <li>Veja todos os pedidos com busca por número do pedido ou nome do cliente</li>
                            <li>Filtre por status: pendente, aguardando pagamento, pago, em preparo, pronto, entregue, cancelado, reembolsado</li>
                            <li>Os pedidos mais recentes aparecem primeiro</li>
                            <li>Clique em qualquer pedido para ver os detalhes completos</li>
                        </ul>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Detalhes do Pedido <span class="font-mono text-purple-700 text-xs font-normal">/admin/orders/{id}</span></p>
                        <ul class="list">
                            <li>Visualize todos os itens pedidos, valores, forma de pagamento e dados do cliente</li>
                            <li>Avance o status do pedido pelo ciclo: pendente → pago → em preparo → pronto → entregue</li>
                            <li>Cancele o pedido quando necessário — o estoque é restaurado automaticamente</li>
                            <li>Para pedidos pagos cancelados: o reembolso é processado automaticamente</li>
                            <li>Histórico de mudanças de status e todas as ações realizadas no pedido</li>
                            <li>Chat interno entre admin e cliente para comunicação sobre o pedido</li>
                            <li>Imprima ou salve o comprovante do pedido em PDF <span class="font-mono text-purple-700 text-xs">/admin/orders/{id}/receipt</span></li>
                        </ul>
                    </div>
                    <div class="info-box-amber">
                        <p class="text-xs text-amber-700"><strong>Regra de cancelamento:</strong> Apenas pedidos com status "pago" ou "em preparo" podem ser cancelados pelo painel. Pedidos cancelados com pagamento já processado geram reembolso automático para o cliente.</p>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('dashboard.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Dashboard e pedidos" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/dashboard.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Kanban de Pedidos --}}
        <section id="kanban" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-lime-100 shrink-0">
                        <svg class="size-6 text-lime-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Kanban de Pedidos</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                            <span class="badge bg-indigo-100 text-indigo-700">branch_manager</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="info-box">
                        <p class="sub-title">Alternar entre Lista e Kanban <span class="font-mono text-purple-700 text-xs font-normal">/admin/orders?viewMode=kanban</span></p>
                        <p class="text-sm text-zinc-600">Na tela de pedidos, um botão alterna entre a visão em lista (tradicional) e a visão em quadro Kanban. A escolha fica salva na URL — se você atualizar a página ou compartilhar o link, a visão selecionada é mantida.</p>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Colunas por status</p>
                        <p class="text-sm text-zinc-600 mb-2">Cada coluna representa um status do pedido, na ordem do fluxo operacional:</p>
                        <ul class="list">
                            <li>Agendado → Pendente → Aguardando pagamento → Pago → Em preparo → Pronto → Entregue → Cancelado</li>
                            <li>Cada coluna mostra a contagem de pedidos e carrega <strong>15 pedidos por vez</strong> — use "Carregar mais" para ver os próximos</li>
                        </ul>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Arrastar e soltar (drag-and-drop)</p>
                        <ul class="list">
                            <li>Arraste o cartão de um pedido para outra coluna para mudar seu status</li>
                            <li>O sistema valida a transição no servidor — movimentos inválidos são revertidos automaticamente</li>
                            <li>Ao mover um pedido para "Cancelado", o estoque dos itens é restaurado, igual ao cancelamento pela lista</li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('kanban.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Kanban de pedidos" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/kanban.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Cardápio --}}
        <section id="cardapio" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-orange-100 shrink-0">
                        <svg class="size-6 text-orange-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Cardápio</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                            <span class="badge bg-indigo-100 text-indigo-700">branch_manager</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-4">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Categorias <span class="font-mono text-purple-700 text-xs font-normal">/admin/categories</span></p>
                            <ul class="list">
                                <li>Organize os produtos em grupos (ex: Lanches, Bebidas, Sobremesas)</li>
                                <li>Defina a ordem de exibição no cardápio por prioridade numérica</li>
                                <li>Edição inline — sem sair da página</li>
                                <li>Exclua categorias que não têm mais produtos</li>
                                <li>Você também pode criar uma categoria nova diretamente na tela de produto</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Produtos <span class="font-mono text-purple-700 text-xs font-normal">/admin/products</span></p>
                            <ul class="list">
                                <li>Cadastre cada item com nome (máx. 150 caracteres), descrição (máx. 500), preço e foto</li>
                                <li>Fotos são armazenadas de forma segura e entregues por CDN</li>
                                <li>Ative ou desative produtos sem excluí-los</li>
                                <li>Defina a ordem de exibição dentro de cada categoria</li>
                                <li>Associe o produto a uma ou mais filiais</li>
                                <li>Filtre por nome, categoria ou filial na listagem</li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Opcionais e complementos (grupos de opções)</p>
                        <p class="text-sm text-zinc-600 mb-2">Cada produto pode ter grupos de personalização, por exemplo: "Ponto da carne", "Adicionais", "Tamanho". Dentro de cada grupo você define as opções individuais.</p>
                        <ul class="list">
                            <li>Nome do grupo (ex: "Tamanho") e quantidade total de escolhas permitidas</li>
                            <li>Cada opção tem nome, quantidade padrão selecionada e preço adicional (pode ser R$ 0)</li>
                            <li>O cliente vê as opções no chat e personaliza o pedido antes de adicionar ao carrinho</li>
                            <li>Os preços das opções são somados ao valor base do produto</li>
                        </ul>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Produtos por filial</p>
                        <ul class="list">
                            <li>Um mesmo produto pode estar disponível em várias filiais ao mesmo tempo</li>
                            <li>Administradores de empresa selecionam todas as filiais por padrão ao criar</li>
                            <li>Gerentes de unidade criam produtos apenas para a sua filial</li>
                            <li>Cada filial tem seu próprio controle de estoque por produto</li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('cardapio.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Gestão de cardápio" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/cardapio.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Estoque --}}
        <section id="estoque" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-teal-100 shrink-0">
                        <svg class="size-6 text-teal-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Estoque</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                            <span class="badge bg-indigo-100 text-indigo-700">branch_manager</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="info-box">
                        <p class="sub-title">Controle de estoque <span class="font-mono text-purple-700 text-xs font-normal">/admin/stock</span></p>
                        <ul class="list">
                            <li>Controle o estoque produto a produto, filial a filial</li>
                            <li>O rastreamento de estoque é opcional: você habilita ou desabilita por produto/filial</li>
                            <li>Busque produtos por nome ou filtre por unidade</li>
                            <li>Gerentes de unidade veem apenas a filial da qual fazem parte</li>
                        </ul>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Ajuste de estoque</p>
                        <p class="text-sm text-zinc-600 mb-2">Ao ajustar, você escolhe um dos três modos:</p>
                        <div class="grid sm:grid-cols-3 gap-2">
                            <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                <p class="font-semibold text-xs text-zinc-700 mb-1">Adicionar</p>
                                <p class="text-xs text-zinc-500">Soma a quantidade informada ao estoque atual. Útil para reposição.</p>
                            </div>
                            <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                <p class="font-semibold text-xs text-zinc-700 mb-1">Subtrair</p>
                                <p class="text-xs text-zinc-500">Desconta a quantidade informada do estoque atual. Útil para perdas ou ajustes.</p>
                            </div>
                            <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                <p class="font-semibold text-xs text-zinc-700 mb-1">Definir</p>
                                <p class="text-xs text-zinc-500">Define o estoque para o valor exato informado. Útil para inventários.</p>
                            </div>
                        </div>
                        <p class="text-xs text-zinc-500 mt-2">Toda movimentação exige uma <strong>observação</strong> (máx. 500 caracteres) para rastreabilidade. O histórico de todos os ajustes fica registrado.</p>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Quantidade mínima</p>
                            <p class="text-sm text-zinc-600">Defina a quantidade mínima aceitável por produto. Quando o estoque cair abaixo desse valor, o item aparece como "estoque baixo" na listagem.</p>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Desconto automático</p>
                            <p class="text-sm text-zinc-600">Quando um pedido é criado, o estoque de cada item é descontado automaticamente. Não é necessário fazer ajuste manual após cada venda.</p>
                        </div>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('estoque.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Controle de estoque" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/estoque.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Filiais --}}
        <section id="filiais" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-sky-100 shrink-0">
                        <svg class="size-6 text-sky-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Filiais e Configurações de Entrega</h2>
                        <p class="mt-1 text-sm text-zinc-500">Gerencimento de unidades <span class="font-mono text-purple-700 text-xs">/admin/branches</span></p>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Dados da filial</p>
                            <ul class="list">
                                <li>Nome, endereço, cidade e telefone de contato</li>
                                <li>Horário de funcionamento: hora de abertura e fechamento</li>
                                <li>Ative ou pause uma filial sem precisar excluí-la</li>
                                <li>O número máximo de filiais depende do plano (Grátis: 1, Essencial: 2, PRO: ilimitadas)</li>
                                <li>A empresa precisa ter ao menos 1 filial ativa — a exclusão da última é bloqueada</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Horário de funcionamento</p>
                            <p class="text-sm text-zinc-600">O sistema verifica o horário de abertura e fechamento em tempo real. Se a filial estiver fechada quando o cliente acessar o link da loja, ele verá uma mensagem informando o horário de reabertura — sem conseguir fazer pedidos.</p>
                        </div>
                    </div>

                    <div class="info-box">
                        <p class="sub-title">Configurações de entrega <span class="badge bg-yellow-100 text-yellow-700 ml-1">PRO</span> <span class="font-mono text-purple-700 text-xs font-normal">/admin/branches/{id}/delivery</span></p>
                        <p class="text-sm text-zinc-600 mb-3">Disponível apenas no plano PRO. Defina como a taxa de entrega da filial é calculada:</p>
                        <div class="grid sm:grid-cols-3 gap-2">
                            <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                <p class="font-semibold text-xs text-zinc-700 mb-1">Taxa fixa</p>
                                <p class="text-xs text-zinc-500">Um único valor de frete para todos os pedidos, independente da distância ou localização.</p>
                            </div>
                            <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                <p class="font-semibold text-xs text-zinc-700 mb-1">Por bairro</p>
                                <p class="text-xs text-zinc-500">Taxas diferentes para cada bairro. Você cadastra os bairros atendidos e o valor de frete de cada um.</p>
                            </div>
                            <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                <p class="font-semibold text-xs text-zinc-700 mb-1">Por distância (km)</p>
                                <p class="text-xs text-zinc-500">Faixas de distância com taxas diferentes. Informe as coordenadas GPS da filial e defina o valor por faixa de km.</p>
                            </div>
                        </div>
                        <div class="mt-3 space-y-1">
                            <p class="text-sm text-zinc-600">Além do tipo de taxa, você também pode configurar:</p>
                            <ul class="list">
                                <li><strong>Valor mínimo de pedido:</strong> pedidos abaixo desse valor não aceitam entrega</li>
                                <li><strong>Frete grátis acima de:</strong> pedidos que ultrapassem esse valor não pagam frete</li>
                                <li><strong>Ativar ou desativar a entrega</strong> da filial com um único toggle</li>
                            </ul>
                        </div>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('filial.mov') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Gestão de filiais e entrega" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/filial.mov</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Impressora --}}
        <section id="impressora" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-slate-200 shrink-0">
                        <svg class="size-6 text-slate-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Impressora</h2>
                        <p class="mt-1 text-sm text-zinc-500">Configuração por filial <span class="font-mono text-purple-700 text-xs">/admin/branches/{id}/printer</span></p>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Dados da impressora</p>
                            <ul class="list">
                                <li><strong>Endereço IP</strong> e <strong>porta</strong> (padrão 9100) da impressora térmica na rede da filial</li>
                                <li><strong>Largura do papel:</strong> 58mm ou 80mm</li>
                                <li><strong>Nome:</strong> identificação opcional, útil quando há mais de um terminal</li>
                                <li><strong>Impressão automática:</strong> imprime o recibo sem precisar clicar em imprimir a cada venda</li>
                                <li>Ative ou desative a impressora sem remover a configuração</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Teste de conexão</p>
                            <p class="text-sm text-zinc-600">Antes de salvar, use o botão de teste para confirmar que o sistema consegue se conectar à impressora pelo IP e porta informados. Cada filial pode ter sua própria impressora configurada de forma independente.</p>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Onde é usada</p>
                        <p class="text-sm text-zinc-600">A impressora configurada aqui é usada pelo <a href="#pdv" class="text-purple-700 underline">PDV</a> para imprimir o recibo de cada venda e o relatório de fechamento de caixa via protocolo ESC-POS.</p>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('impressora.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Configuração de impressora" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/impressora.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Cupons --}}
        <section id="cupons" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-pink-100 shrink-0">
                        <svg class="size-6 text-pink-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185ZM9.75 9h.008v.008H9.75V9Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 4.5h.008v.008h-.008V13.5Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Cupons de Desconto</h2>
                        <p class="mt-1 text-sm text-zinc-500"><span class="font-mono text-purple-700 text-xs">/admin/coupons</span></p>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box sm:col-span-2">
                            <p class="sub-title">Tipos de cupom</p>
                            <div class="grid sm:grid-cols-2 gap-2">
                                <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                    <p class="font-semibold text-xs text-zinc-700 mb-1">Desconto percentual</p>
                                    <p class="text-xs text-zinc-500">Desconta um percentual do valor do pedido. Ex: 15% de desconto. Informe o percentual no campo "Valor do desconto".</p>
                                </div>
                                <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                    <p class="font-semibold text-xs text-zinc-700 mb-1">Desconto em valor fixo</p>
                                    <p class="text-xs text-zinc-500">Desconta um valor fixo em reais. Ex: R$ 5,00 de desconto. Informe o valor no campo "Valor do desconto".</p>
                                </div>
                                <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                    <p class="font-semibold text-xs text-zinc-700 mb-1">Frete grátis</p>
                                    <p class="text-xs text-zinc-500">Zera o valor da taxa de entrega. Não exige valor de desconto — basta criar o cupom com esse tipo.</p>
                                </div>
                                <div class="bg-white rounded-lg border border-zinc-200 p-3">
                                    <p class="font-semibold text-xs text-zinc-700 mb-1">Produto gratuito</p>
                                    <p class="text-xs text-zinc-500">Adiciona um produto específico ao pedido sem cobrança. Selecione qual produto será o brinde ao criar o cupom.</p>
                                </div>
                            </div>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Escopo do cupom</p>
                            <p class="text-sm text-zinc-600 mb-1">Para cupons percentuais ou de valor fixo, você pode restringir onde o desconto se aplica:</p>
                            <ul class="list">
                                <li><strong>Pedido inteiro:</strong> aplica sobre o subtotal total</li>
                                <li><strong>Categoria específica:</strong> aplica somente nos produtos da(s) categoria(s) selecionada(s)</li>
                                <li><strong>Produto específico:</strong> aplica somente nos produtos selecionados</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Limites e validade</p>
                            <ul class="list">
                                <li><strong>Data de início e expiração:</strong> o cupom só funciona dentro da janela de datas definida</li>
                                <li><strong>Total de usos:</strong> quantas vezes o cupom pode ser usado no total (por todos os clientes)</li>
                                <li><strong>Usos por cliente:</strong> quantas vezes o mesmo cliente pode usar o cupom (padrão: 1)</li>
                                <li><strong>Valor mínimo:</strong> pedidos abaixo desse valor não aceitam o cupom</li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Gerenciamento</p>
                        <ul class="list">
                            <li>Códigos de cupom são sempre em letras maiúsculas e únicos por empresa</li>
                            <li>Ative ou pause qualquer cupom sem precisar excluí-lo</li>
                            <li>Filtre a lista por status (ativo/inativo) e tipo de cupom</li>
                            <li>O contador de usos é exibido na listagem</li>
                            <li>Todas as criações, edições e exclusões são registradas no log de auditoria</li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('cupons.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Cupons de desconto" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/cupons.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Relatórios --}}
        <section id="relatorios" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-violet-100 shrink-0">
                        <svg class="size-6 text-violet-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Relatórios</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                            <span class="badge bg-indigo-100 text-indigo-700">branch_manager</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Filtros disponíveis <span class="font-mono text-purple-700 text-xs font-normal">/admin/orders/report</span></p>
                            <ul class="list">
                                <li>Período de datas (início e fim) — padrão: mês atual</li>
                                <li>Filial específica ou todas as filiais</li>
                                <li>Status dos pedidos</li>
                                <li>Forma de pagamento (PIX, cartão, dinheiro)</li>
                                <li>Tipo de pedido (entrega ou retirada)</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Métricas calculadas</p>
                            <ul class="list">
                                <li>Total de pedidos no período</li>
                                <li>Receita total (excluindo cancelados e reembolsados)</li>
                                <li>Ticket médio por pedido</li>
                                <li>Forma de pagamento mais utilizada pelos clientes</li>
                                <li>Número de filiais com pedidos no período</li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Exportação</p>
                        <ul class="list">
                            <li><strong>CSV:</strong> exporta os dados filtrados em formato planilha. Inclui número do pedido, cliente, filial, data, método de pagamento, tipo, status e total</li>
                            <li><strong>PDF:</strong> relatório formatado com o logo e nome da sua empresa para guardar ou compartilhar <span class="font-mono text-purple-700 text-xs">/admin/orders/report/pdf</span></li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('relatorios.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Relatório de pedidos" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/relatorios.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- PDV --}}
        <section id="pdv" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-cyan-100 shrink-0">
                        <svg class="size-6 text-cyan-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.25-4.5h.008v.008H10.5v-.008Zm0 2.25h.008v.008H10.5V13.5Zm0 2.25h.008v.008H10.5v-.008Zm0 2.25h.008v.008H10.5V18Zm2.25-4.5h.008v.008H12.75v-.008Zm0 2.25h.008v.008H12.75V13.5Zm0 2.25h.008v.008H12.75v-.008Zm0 2.25h.008v.008H12.75V18Zm2.25-2.25h.008v.008H15v-.008Zm0-4.5h.008v.008H15v-.008Zm0 2.25h.008v.008H15V13.5ZM6.75 8.25h10.5M6 21h12a1.5 1.5 0 0 0 1.5-1.5V4.5A1.5 1.5 0 0 0 18 3H6a1.5 1.5 0 0 0-1.5 1.5v15A1.5 1.5 0 0 0 6 21Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">PDV — Ponto de Venda</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-cyan-100 text-cyan-700">Add-on pago</span>
                            <span class="font-mono text-purple-700 text-xs self-center">/admin/pdv</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="info-box">
                        <p class="sub-title">O que é</p>
                        <p class="text-sm text-zinc-600">Terminal de venda presencial para vender no balcão ou em mesas/comandas, sem depender do chat público. É um <strong>módulo pago opcional</strong> (R$ 99/mês, somado à mensalidade do plano atual) — ative ou cancele em <a href="#faturamento" class="text-purple-700 underline">Faturamento</a>. Só opera quem tiver a permissão <code class="text-purple-700 font-normal">pdv.operate</code>.</p>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Fluxo de venda</p>
                        <div class="space-y-2 mt-1">
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-cyan-600 text-white text-xs">1</span>
                                <p class="text-sm text-zinc-600">Abra o caixa informando o valor inicial em dinheiro e, opcionalmente, o nome do terminal</p>
                            </div>
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-cyan-600 text-white text-xs">2</span>
                                <p class="text-sm text-zinc-600">Monte o carrinho pelo catálogo, busca por nome ou leitor de código de barras. Produtos com opcionais abrem uma tela de personalização</p>
                            </div>
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-cyan-600 text-white text-xs">3</span>
                                <p class="text-sm text-zinc-600">Escolha venda no balcão ou com entrega (exige cliente cadastrado), aplique desconto manual se habilitado, e finalize o pagamento</p>
                            </div>
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-cyan-600 text-white text-xs">4</span>
                                <p class="text-sm text-zinc-600">O recibo é impresso automaticamente (se configurado) e o pedido entra no histórico da sessão</p>
                            </div>
                        </div>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Mesa / Comanda</p>
                            <ul class="list">
                                <li>Alterne entre modo "Balcão" (venda direta) e modo "Mesa/Comanda" (conta aberta)</li>
                                <li>Abra uma comanda por vez ou em lote — informe vários nomes separados por vírgula ou linha</li>
                                <li>Adicione itens à comanda aberta a qualquer momento, antes de fechar</li>
                                <li>Ao fechar, escolha a forma de pagamento e finalize a conta</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Pagamento offline</p>
                            <ul class="list">
                                <li><strong>Dinheiro:</strong> informe o valor recebido — o troco é calculado automaticamente</li>
                                <li><strong>Cartão (maquininha):</strong> registrado como pago manualmente, sem cobrança pelo Vindi</li>
                                <li><strong>PIX manual:</strong> conferido visualmente pelo operador, também sem cobrança pelo Vindi</li>
                                <li>Desconto manual (fixo ou percentual) só fica disponível se a empresa habilitar essa opção</li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Sessão de caixa</p>
                        <ul class="list">
                            <li>Cada operador tem sua própria sessão de caixa por filial (suporte a múltiplos terminais)</li>
                            <li>Registre suprimentos (entrada de dinheiro) e sangrias (retirada) durante o turno, sempre com motivo</li>
                            <li>Ao fechar o caixa, informe o valor contado — diferenças acima de R$ 5,00 exigem justificativa</li>
                            <li>O relatório de fechamento mostra valor de abertura, vendas em dinheiro, suprimentos, sangrias e o valor esperado</li>
                            <li>Histórico de sessões fechadas e pedidos cancelados, com restauração automática de estoque</li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('pdv.mov') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="PDV — Ponto de Venda" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/pdv.mov</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Notas Fiscais --}}
        <section id="fiscal" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-red-100 shrink-0">
                        <svg class="size-6 text-red-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Notas Fiscais — NFC-e</h2>
                        <p class="mt-1 text-sm text-zinc-500">Emissão fiscal dos pedidos <span class="font-mono text-purple-700 text-xs">/admin/fiscal/notas</span></p>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="info-box">
                        <p class="sub-title">O que é</p>
                        <p class="text-sm text-zinc-600">Emissão de Nota Fiscal de Consumidor Eletrônica (NFC-e) para os pedidos, integrada com a Focus NFe. Quem visualiza precisa da permissão <code class="text-purple-700 font-normal">fiscal.view</code>; quem emite ou cancela precisa de <code class="text-purple-700 font-normal">fiscal.issue</code>.</p>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Status da nota</p>
                            <ul class="list">
                                <li><strong>Emitida:</strong> nota gerada e autorizada, com chave de acesso disponível</li>
                                <li><strong>Pendente:</strong> aguardando retorno da Focus NFe</li>
                                <li><strong>Cancelada:</strong> emissão cancelada, com justificativa registrada</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Busca e consulta</p>
                            <p class="text-sm text-zinc-600">Encontre uma nota pela chave de acesso, pela referência do provedor (Focus NFe) ou pelo número do pedido. Filtre a lista por status.</p>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Cancelamento</p>
                        <p class="text-sm text-zinc-600">Só é possível cancelar notas ainda ativas. É obrigatório informar uma justificativa com no mínimo 15 caracteres antes de confirmar.</p>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('fiscal.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Notas fiscais — NFC-e" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/fiscal.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Carteira --}}
        <section id="carteira" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-emerald-100 shrink-0">
                        <svg class="size-6 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Carteira e Financeiro</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Saldo <span class="font-mono text-purple-700 text-xs font-normal">/admin/wallet</span></p>
                            <ul class="list">
                                <li><strong>Disponível:</strong> valor já liberado, pronto para saque imediato</li>
                                <li><strong>A receber (em carência):</strong> valor aguardando o prazo de liberação — PIX libera em D+1, cartão e boleto em D+2</li>
                                <li>O saldo é atualizado automaticamente conforme os pagamentos são confirmados pelo gateway</li>
                                <li>Consulte o histórico das últimas 50 transações</li>
                                <li>Veja as últimas 20 solicitações de saque</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Saque</p>
                            <ul class="list">
                                <li>Valor mínimo de saque: <strong>R$ 10,00</strong></li>
                                <li><strong>PIX:</strong> informe sua chave PIX (CPF, CNPJ, e-mail, telefone ou chave aleatória). Taxa de R$ 0,50 por saque</li>
                                <li><strong>TED:</strong> informe banco, agência, conta e dados do titular</li>
                                <li>Os dados de saque são salvos como padrão para facilitar o próximo saque</li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Antecipação de recebíveis</p>
                        <ul class="list">
                            <li>Disponível para qualquer plano — não depende mais de Essencial ou PRO</li>
                            <li>Selecione quais transações em carência você quer antecipar</li>
                            <li>A taxa varia pelo prazo restante até a liberação: até 2 dias 1,45%, até 7 dias 0,75%, até 15 dias 0,75%, 30 dias ou mais sem taxa</li>
                            <li>Confirme e o valor líquido (já descontada a taxa de antecipação) fica disponível na hora</li>
                        </ul>
                    </div>
                    <div class="info-box-blue">
                        <p class="text-xs text-blue-800"><strong>Como o dinheiro chega à carteira:</strong> Após a confirmação do pagamento, o valor líquido é creditado automaticamente. No PIX, primeiro é descontada a taxa do gateway (0,99% — repasse Vindi + margem da plataforma) e, sobre o que resta (sem contar a taxa de entrega, que fica inteira com a empresa), é aplicada a taxa da plataforma do seu plano. No cartão, a taxa da bandeira (1% a 5,33%, conforme o cartão usado) é descontada da mesma forma. O valor fica em carência até o prazo de liberação e depois pode ser sacado.</p>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('carteira.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Carteira financeira" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/carteira.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Faturamento --}}
        <section id="faturamento" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-amber-100 shrink-0">
                        <svg class="size-6 text-amber-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Faturamento e Plano</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Seu plano atual <span class="font-mono text-purple-700 text-xs font-normal">/admin/billing</span></p>
                            <ul class="list">
                                <li>Veja o plano atual, status da conta e próxima data de vencimento</li>
                                <li>Histórico de cobranças realizadas pelo Veddi</li>
                                <li>Esta área fica acessível mesmo que sua conta esteja temporariamente bloqueada</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Status da conta</p>
                            <ul class="list">
                                <li><strong>Ativo:</strong> tudo funcionando normalmente</li>
                                <li><strong>Pagamento pendente:</strong> aguardando confirmação da taxa de adesão</li>
                                <li><strong>Em atraso:</strong> mensalidade não paga — funcionalidades podem ser limitadas</li>
                                <li><strong>Bloqueado:</strong> conta suspensa por inadimplência</li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Mudar de plano</p>
                        <ul class="list">
                            <li><strong>Upgrade (Grátis → pago):</strong> cobra a taxa de adesão + primeiro mês pelo cartão. A assinatura recorrente começa no mês seguinte</li>
                            <li><strong>Troca entre planos pagos:</strong> cancela a assinatura atual e cria uma nova com o novo valor. Sem taxa de adesão adicional</li>
                            <li><strong>Downgrade para Grátis:</strong> cancela a assinatura recorrente. A conta permanece ativa</li>
                        </ul>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Pagamento pelo cartão de crédito</p>
                        <ul class="list">
                            <li>Informe número do cartão, validade, CVV, nome do titular e CPF/CNPJ</li>
                            <li>O pagamento é processado de forma segura via Asaas (checkout transparente)</li>
                            <li>É necessário aceitar os termos de uso antes de confirmar</li>
                            <li>Um feedback de sucesso ou erro é exibido imediatamente após o processamento</li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('assinatura.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Faturamento e plano" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/assinatura.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Usuários e Papéis --}}
        <section id="usuarios" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-rose-100 shrink-0">
                        <svg class="size-6 text-rose-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Usuários e Papéis</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Tipos de acesso padrão</p>
                            <ul class="list">
                                <li><strong>Administrador (company_admin):</strong> acesso completo — pedidos, cardápio, estoque, financeiro, configurações, usuários e papéis</li>
                                <li><strong>Gerente de unidade (branch_manager):</strong> acesso a pedidos, cardápio e estoque apenas da filial à qual está vinculado. Sem acesso a financeiro, configurações e usuários</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Gerenciar usuários <span class="font-mono text-purple-700 text-xs font-normal">/admin/users</span></p>
                            <ul class="list">
                                <li>Crie um novo usuário informando nome, e-mail e papel. O sistema envia um e-mail de boas-vindas com a senha temporária</li>
                                <li>Vincule um usuário que já existe na plataforma informando o e-mail dele</li>
                                <li>Altere o papel de qualquer usuário da equipe</li>
                                <li>Para gerentes de unidade, defina a qual filial ele pertence</li>
                                <li>Remova um usuário da empresa (a conta dele continua existindo, apenas perde o acesso à sua empresa)</li>
                                <li>Ajuste permissões individuais de cada usuário em <span class="font-mono text-purple-700 text-xs">/admin/users/{id}/permissions</span></li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Papéis personalizados <span class="font-mono text-purple-700 text-xs font-normal">/admin/roles</span></p>
                        <ul class="list">
                            <li>Crie papéis customizados com conjuntos de permissões específicos para sua operação</li>
                            <li>Selecione permissões individuais por grupo (pedidos, cardápio, estoque, financeiro, etc.)</li>
                            <li>Atribua um papel personalizado a qualquer usuário informando o e-mail</li>
                            <li>Ao editar as permissões de um papel, todos os usuários com aquele papel são atualizados automaticamente</li>
                            <li>Papéis do sistema (administrador, gerente) não podem ser editados ou excluídos</li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('usuarios.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Usuários e papéis" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/usuarios.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Configurações --}}
        <section id="configuracoes" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-zinc-200 shrink-0">
                        <svg class="size-6 text-zinc-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Configurações da Empresa</h2>
                        <div class="flex gap-2 mt-1">
                            <span class="badge bg-purple-100 text-purple-700">company_admin</span>
                        </div>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Identidade <span class="font-mono text-purple-700 text-xs font-normal">/admin/settings</span></p>
                            <ul class="list">
                                <li><strong>Nome da empresa:</strong> exibido no painel e no chat do cliente</li>
                                <li><strong>Slug (endereço):</strong> o sufixo do link público da sua loja (ex: veddi.com.br/<strong>minha-loja</strong>). Só letras minúsculas, números e hífens</li>
                                <li><strong>Slogan:</strong> frase curta que aparece no chat do cliente</li>
                                <li><strong>Texto do rodapé:</strong> informação que aparece no rodapé do chat</li>
                                <li><strong>Prefixo do pedido:</strong> código inicial de cada número de pedido (ex: ORD, MCX). Máx. 10 caracteres</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Visual da loja <span class="badge bg-purple-100 text-purple-700 ml-1">Essencial / PRO</span></p>
                            <ul class="list">
                                <li>Upload do <strong>logotipo</strong> (substituição automática da versão anterior)</li>
                                <li>Paleta de <strong>6 cores</strong> para personalizar a identidade visual do chat e do painel (cor principal, escura, clara, secundária, clara secundária e destaque)</li>
                                <li>Cada cor deve ser um código hexadecimal válido (#RRGGBB)</li>
                                <li>Plano Grátis não pode personalizar cores e logo</li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Destaques do chat <span class="badge bg-purple-100 text-purple-700 ml-1">Essencial / PRO</span></p>
                        <p class="text-sm text-zinc-600 mb-2">Adicione até <strong>6 destaques</strong> que aparecem na tela inicial do chat do cliente (antes de começar o pedido). Cada destaque tem:</p>
                        <ul class="list">
                            <li><strong>Ícone:</strong> emoji ou símbolo (máx. 10 caracteres)</li>
                            <li><strong>Título:</strong> texto principal (máx. 60 caracteres)</li>
                            <li><strong>Descrição:</strong> texto de apoio (máx. 120 caracteres)</li>
                        </ul>
                        <p class="text-sm text-zinc-500 mt-2">Exemplo: "🚀 Entrega rápida — Pedidos entregues em até 40 minutos!"</p>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Absorção de taxas de pagamento</p>
                        <p class="text-sm text-zinc-600 mb-1">Defina quem paga as taxas dos meios de pagamento:</p>
                        <ul class="list">
                            <li><strong>Taxa PIX (R$ 0,50):</strong> se a empresa absorver, o cliente não paga — o custo fica com a empresa</li>
                            <li><strong>Taxa cartão (1% a 5,33%, conforme a bandeira):</strong> se a empresa absorver, o cliente paga o valor do pedido sem acréscimo</li>
                            <li>Se não absorver, o acréscimo correspondente é somado ao total antes de confirmar o pagamento</li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('configuracoes.mov') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Configurações da empresa" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/configuracoes.mov</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Pagamentos e Integrações --}}
        <section id="pagamentos" class="scroll-mt-20">
            <div class="section-card">
                <div class="section-head">
                    <div class="p-2.5 rounded-xl bg-yellow-100 shrink-0">
                        <svg class="size-6 text-yellow-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-zinc-900">Pagamentos e Integrações</h2>
                        <p class="mt-1 text-sm text-zinc-500">Gateways que movimentam o dinheiro na plataforma</p>
                    </div>
                </div>
                <div class="mt-5 space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div class="info-box">
                            <p class="sub-title">Asaas — cobranças da plataforma</p>
                            <ul class="list">
                                <li>Cria e cobra a taxa de adesão no cadastro de novas empresas</li>
                                <li>Gerencia a assinatura mensal de cada empresa</li>
                                <li>Processa pagamentos de cartão de crédito no checkout transparente</li>
                                <li>Envia notificações de cobrança e confirmações via webhook</li>
                                <li>O Veddi recebe a confirmação automaticamente — sem ação manual</li>
                            </ul>
                        </div>
                        <div class="info-box">
                            <p class="sub-title">Vindi — pagamentos dos pedidos</p>
                            <ul class="list">
                                <li>Gera o QR Code e código copia e cola do PIX para cada pedido</li>
                                <li>Processa o pagamento por cartão de crédito do cliente no checkout do chat</li>
                                <li>Gerencia o split, a carteira, o saldo e as transações de cada empresa</li>
                                <li>Processa as solicitações de saque da carteira (PIX e TED)</li>
                                <li>Confirma o pagamento em tempo real via webhook — o pedido é atualizado automaticamente</li>
                                <li>O PIX de cada pedido expira em 30 minutos</li>
                            </ul>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Fluxo completo de um pagamento de pedido</p>
                        <div class="space-y-2 mt-1">
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-emerald-600 text-white text-xs">1</span>
                                <p class="text-sm text-zinc-600">O cliente finaliza o pedido e escolhe PIX ou cartão no chat</p>
                            </div>
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-emerald-600 text-white text-xs">2</span>
                                <p class="text-sm text-zinc-600">O sistema calcula o valor final: subtotal + entrega − desconto do cupom ± taxa do meio de pagamento</p>
                            </div>
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-emerald-600 text-white text-xs">3</span>
                                <p class="text-sm text-zinc-600">O pedido é criado com status "aguardando pagamento" e o estoque é reservado</p>
                            </div>
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-emerald-600 text-white text-xs">4</span>
                                <p class="text-sm text-zinc-600">Para PIX: QR Code é gerado pelo Vindi e exibido ao cliente. Para cartão: dados são enviados para o Vindi</p>
                            </div>
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-emerald-600 text-white text-xs">5</span>
                                <p class="text-sm text-zinc-600">O gateway confirma o pagamento via webhook. O pedido muda para "pago" e a equipe da loja é avisada em tempo real</p>
                            </div>
                            <div class="flex gap-3 items-start">
                                <span class="step-num bg-emerald-600 text-white text-xs">6</span>
                                <p class="text-sm text-zinc-600">O valor líquido entra na carteira da empresa — já descontadas a taxa do gateway (Vindi) e a taxa da plataforma do plano</p>
                            </div>
                        </div>
                    </div>
                    <div class="info-box">
                        <p class="sub-title">Taxas aplicadas por pedido</p>
                        <ul class="list">
                            <li><strong>Taxa do gateway (Vindi):</strong> no PIX, 0,99% do valor cobrado (0,85% repasse Vindi + 0,14% margem da plataforma); no cartão, varia por bandeira — Visa 1%, Mastercard/Diners 2,8%, Amex 1,88%, Elo 4,48%, Hipercard 5,33%</li>
                            <li><strong>Taxa da plataforma:</strong> percentual do plano, aplicado sobre o valor líquido já descontada a taxa do gateway — sem contar a taxa de entrega, que fica inteira com a empresa</li>
                            <li><strong>Taxa PIX de R$ 0,50:</strong> exibida ao cliente no checkout, a menos que a empresa opte por absorvê-la nas configurações</li>
                            <li><strong>Acréscimo do cartão:</strong> se a empresa não absorver a taxa da bandeira, o valor correspondente é somado automaticamente ao total cobrado do cliente</li>
                        </ul>
                    </div>
                </div>

                    <div class="doc-media">
                    @php $media = $docMedia('pagamentos.png') @endphp
                    @if($media)
                        @if($media['type'] === 'video')
                            <video src="{{ $media['url'] }}" controls playsinline muted class="doc-img"></video>
                        @else
                            <img src="{{ $media['url'] }}" alt="Pagamentos e integrações" class="doc-img">
                        @endif
                    @else
                        <div class="doc-img-placeholder">
                            <svg class="size-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                            <p class="text-xs text-zinc-400 text-center px-4">Mídia não encontrada — salve em <code class="bg-zinc-100 px-1 rounded">public/images/docs/pagamentos.png</code></p>
                        </div>
                    @endif
                    </div>
            </div>
        </section>

        {{-- Footer --}}
        <footer class="pt-4 pb-8 text-center text-xs text-zinc-400">
            Veddi &mdash; Documentação interna &bull; <a href="/" class="hover:text-purple-600 transition-colors">Voltar ao início</a>
        </footer>

    </main>
</div>

<script>
    const sections = document.querySelectorAll('section[id]');
    const navLinks = document.querySelectorAll('.nav-link[href^="#"]');

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                navLinks.forEach(link => {
                    link.classList.toggle('active', link.getAttribute('href') === '#' + entry.target.id);
                });
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(section => observer.observe(section));
</script>
</x-layouts::docs>
