<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Política de Privacidade — Veddi</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-zinc-50 text-zinc-800">
    <header class="bg-white border-b border-zinc-200">
        <div class="max-w-3xl mx-auto px-6 h-14 flex items-center">
            <a href="{{ route('register.create') }}" class="text-sm font-bold text-[#7A00A3]" style="font-family: 'Montserrat', sans-serif;">Veddi</a>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-6 py-10">
        <h1 class="text-2xl font-extrabold text-zinc-900 mb-1" style="font-family: 'Montserrat', sans-serif;">Política de Privacidade</h1>
        <p class="text-sm text-zinc-500 mb-8">Última atualização: 22/07/2026</p>

        <div class="space-y-6 text-sm leading-relaxed">
            <p>Esta Política de Privacidade descreve como a <strong>Veddi</strong> coleta, usa, armazena e protege os dados pessoais de empresas cadastradas, seus usuários e clientes finais, em conformidade com a Lei Geral de Proteção de Dados (Lei nº 13.709/2018 — LGPD).</p>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">1. Dados que Coletamos</h2>
                <p>Coletamos dados de cadastro (nome, e-mail, telefone, CPF/CNPJ, endereço), dados de uso da plataforma e dados de pedidos realizados por clientes finais no chat público de cada empresa.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">2. Como Usamos os Dados</h2>
                <p>Os dados são usados para operar a plataforma, processar pedidos e pagamentos, emitir cobranças, prestar suporte e cumprir obrigações legais e fiscais.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">3. Compartilhamento com Terceiros</h2>
                <p>Compartilhamos dados estritamente necessários com processadores de pagamento (Asaas e Vindi) para viabilizar cobranças e repasses financeiros. Não vendemos dados pessoais a terceiros.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">4. Dados de Cartão de Crédito</h2>
                <p>Dados de cartão de crédito são transmitidos diretamente ao processador de pagamentos via checkout transparente e não são armazenados nos servidores da Veddi.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">5. Armazenamento e Segurança</h2>
                <p>Adotamos medidas técnicas e organizacionais para proteger os dados contra acesso não autorizado, perda ou alteração indevida. Cada empresa tem seus dados isolados dentro da plataforma (multiempresa).</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">6. Seus Direitos</h2>
                <p>Você pode solicitar acesso, correção, portabilidade ou exclusão dos seus dados pessoais, conforme previsto na LGPD, entrando em contato com nosso suporte.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">7. Retenção de Dados</h2>
                <p>Mantemos os dados pelo tempo necessário para cumprir as finalidades descritas nesta Política e obrigações legais, fiscais e regulatórias aplicáveis.</p>
            </div>

            <div>
                <h2 class="font-semibold text-zinc-900 mb-1">8. Alterações nesta Política</h2>
                <p>Podemos atualizar esta Política periodicamente. Alterações relevantes serão comunicadas por e-mail com antecedência mínima de 15 dias.</p>
            </div>

            <p class="text-xs text-zinc-400 pt-4 border-t border-zinc-200">Em caso de dúvidas sobre o tratamento dos seus dados, entre em contato com nosso suporte.</p>
        </div>

        <a href="{{ route('register.create') }}" class="inline-flex items-center gap-1.5 mt-10 text-sm font-medium text-[#7A00A3] hover:underline">
            ← Voltar ao cadastro
        </a>
    </main>
</body>
</html>
