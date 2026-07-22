<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Termos de Uso — Veddi</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-zinc-50 text-zinc-800">
    <header class="bg-white border-b border-zinc-200">
        <div class="max-w-3xl mx-auto px-6 h-14 flex items-center">
            <a href="{{ route('register.create') }}" class="text-sm font-bold text-[#7A00A3]" style="font-family: 'Montserrat', sans-serif;">Veddi</a>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-6 py-10">
        <h1 class="text-2xl font-extrabold text-zinc-900 mb-1" style="font-family: 'Montserrat', sans-serif;">Termos de Uso</h1>
        <p class="text-sm text-zinc-500 mb-8">Última atualização: 22/07/2026</p>

        <div class="space-y-6 text-sm leading-relaxed">
            <p>Estes Termos de Uso regulam o acesso e uso da plataforma <strong>Veddi</strong>, um sistema de gestão de pedidos, cardápio e operações financeiras para negócios de alimentação. Ao criar uma conta, você declara que leu, compreendeu e concorda com os termos abaixo.</p>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">1. Cadastro e Conta</h2>
                <p>Para usar a plataforma, sua empresa deve fornecer dados verdadeiros, completos e atualizados no cadastro. Você é responsável por manter a confidencialidade das credenciais de acesso e por todas as atividades realizadas na sua conta.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">2. Planos e Cobrança</h2>
                <p>A plataforma oferece planos gratuitos e pagos, cada um com limites e funcionalidades próprias. Planos pagos podem incluir mensalidade, taxa de ativação e taxa por pedido, conforme descrito no momento da contratação.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">3. Uso da Plataforma</h2>
                <p>Você concorda em usar a plataforma apenas para fins lícitos, relacionados à operação do seu negócio de alimentação, e em não utilizá-la de forma que possa danificar, sobrecarregar ou comprometer o funcionamento do sistema.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">4. Pedidos e Pagamentos de Clientes</h2>
                <p>Os pedidos feitos pelos clientes finais no chat público da sua empresa são processados por gateways de pagamento parceiros. A Veddi atua como intermediadora tecnológica e não se responsabiliza pela qualidade dos produtos ou serviços oferecidos pela empresa contratante.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">5. Disponibilidade</h2>
                <p>A Veddi se esforça para manter a plataforma disponível e funcional, mas não garante operação ininterrupta e não se responsabiliza por falhas decorrentes de terceiros, conexão de internet ou casos fortuitos.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">6. Cancelamento</h2>
                <p>Você pode cancelar sua conta a qualquer momento pelo painel administrativo. O cancelamento não gera reembolso proporcional de valores já cobrados.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">7. Alterações nestes Termos</h2>
                <p>Podemos atualizar estes Termos periodicamente. Alterações relevantes serão comunicadas por e-mail com antecedência mínima de 15 dias. O uso continuado da plataforma após esse prazo implica aceitação dos novos termos.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">8. Privacidade</h2>
                <p>O tratamento de dados pessoais realizado pela plataforma está descrito na nossa <a href="{{ route('legal.privacy') }}" class="text-[#7A00A3] hover:underline font-medium">Política de Privacidade</a>.</p>
            </div>

            <p class="text-xs text-zinc-400 pt-4 border-t border-zinc-200">Em caso de dúvidas sobre estes Termos, entre em contato com nosso suporte.</p>
        </div>

        <a href="{{ route('register.create') }}" class="inline-flex items-center gap-1.5 mt-10 text-sm font-medium text-[#7A00A3] hover:underline">
            ← Voltar ao cadastro
        </a>
    </main>
</body>
</html>
