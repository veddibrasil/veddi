<?php

namespace App\Services\Marketing;

use App\Enums\Plan;
use App\Models\EmailTemplate;
use App\Services\Company\AddonModulePricing;
use Illuminate\Support\Str;

/**
 * Modelos prontos de e-mail marketing, instalados pelo super admin na tela de Templates. Cada
 * um vira um EmailTemplate comum (editável, duplicável). Trechos "[EDITAR: ...]" precisam ser
 * trocados antes do envio: o checklist da campanha bloqueia enquanto existirem.
 *
 * Logo e links saem absolutos com o APP_URL do momento da instalação, como num template salvo
 * à mão. Os números de plano (limite, filiais, taxa) vêm de config/plans.php.
 */
class DefaultEmailTemplates
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * Cria os modelos que ainda não existem (comparando pelo nome). Retorna quantos criou.
     *
     * @param  list<string>|null  $only  nomes dos modelos (campanha e automação prontas instalam só o que usam)
     */
    public function install(?int $userId = null, ?array $only = null): int
    {
        $existing = EmailTemplate::pluck('name')->map(fn (string $name) => Str::lower(trim($name)))->all();
        $wanted = $only === null ? null : array_map(fn (string $name) => Str::lower(trim($name)), $only);
        $created = 0;

        foreach ($this->definitions() as $definition) {
            $name = Str::lower($definition['name']);

            if (in_array($name, $existing, true) || ($wanted !== null && ! in_array($name, $wanted, true))) {
                continue;
            }

            $blocks = $this->renderer->normalizeBlocks($definition['blocks']);

            EmailTemplate::create([
                'name' => $definition['name'],
                'subject_default' => $definition['subject'],
                'preheader_default' => $definition['preheader'],
                'blocks' => $blocks,
                'html' => $this->renderer->blocksToHtml($blocks),
                'created_by' => $userId,
            ]);

            $created++;
        }

        return $created;
    }

    /** Template pelo nome (sem diferenciar maiúsculas), instalando o modelo padrão se faltar. */
    public function findOrInstall(string $name, ?int $userId = null): ?EmailTemplate
    {
        $find = fn () => EmailTemplate::whereRaw('LOWER(TRIM(name)) = ?', [Str::lower(trim($name))])->oldest('id')->first();

        if ($template = $find()) {
            return $template;
        }

        $this->install($userId, [$name]);

        return $find();
    }

    /** @return list<array{name: string, subject: string, preheader: string, blocks: list<array<string, string>>}> */
    public function definitions(): array
    {
        $docs = $this->link('docs');

        return [
            [
                'name' => 'Boas-vindas',
                'subject' => 'Boas-vindas ao Veddi, {{primeiro_nome}}!',
                'preheader' => 'Quatro passos para a {{empresa}} receber o primeiro pedido.',
                'blocks' => [
                    $this->header('Que bom ter a {{empresa}} com a gente!'),
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}!</p>
                        <p>Sua conta no Veddi está pronta. Siga estes passos para receber o primeiro pedido:</p>
                        <ol>
                            <li><strong>Monte o cardápio:</strong> crie as categorias e cadastre os produtos com foto, descrição e preço.</li>
                            <li><strong>Revise a filial:</strong> endereço, telefone e horário de funcionamento. Fora do horário, a loja aparece fechada para o cliente.</li>
                            <li><strong>Veja a loja como o cliente:</strong> abra o link da sua loja no celular e navegue pelo cardápio.</li>
                            <li><strong>Divulgue o link:</strong> coloque na bio do Instagram, no status do WhatsApp e no perfil do Google.</li>
                        </ol>
                        HTML),
                    $this->button('Acessar o painel'),
                    $this->text(<<<HTML
                        <p>Quer o passo a passo com imagens? Está tudo no <a href="{$docs}">guia do Veddi</a>. E se travar em alguma etapa, é só responder este e-mail.</p>
                        <p>Um abraço,<br>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Cadastro pendente: lembrete',
                'subject' => '{{primeiro_nome}}, falta só um passo para ativar a {{empresa}}',
                'preheader' => 'O pagamento da ativação não foi concluído. A gente te ajuda a terminar.',
                'blocks' => [
                    $this->header('Sua loja está quase pronta'),
                    // Sem botão: quem saiu da tela do cartão não tem cobrança para pagar no painel.
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}!</p>
                        <p>Você começou o cadastro da {{empresa}} no plano {{plano}}, mas o pagamento da ativação (taxa de ativação + 1º mês, no cartão de crédito) não foi concluído. Por isso a loja ainda não está no ar.</p>
                        <p><strong>Responda este e-mail</strong> que a gente te ajuda a concluir a ativação. Leva poucos minutos e, com o pagamento aprovado, a loja é liberada na hora.</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Cadastro pendente: último aviso',
                'subject' => 'A {{empresa}} continua esperando para vender',
                'preheader' => 'Seu cadastro está salvo: falta só concluir o pagamento da ativação.',
                'blocks' => [
                    $this->header('Ainda dá tempo de ativar a {{empresa}}'),
                    $this->text(<<<'HTML'
                        <p>{{primeiro_nome}}, seu cadastro continua salvo, mas a {{empresa}} só começa a receber pedidos depois que o pagamento da ativação é concluído.</p>
                        <p>Com a loja ativa, você já pode:</p>
                        <ul>
                            <li>receber pedidos pelo link próprio da sua loja;</li>
                            <li>acompanhar cada pedido em tempo real no painel;</li>
                            <li>receber as vendas pelo PIX.</li>
                        </ul>
                        <p>Se o cartão foi recusado ou algo atrapalhou, <strong>responda este e-mail</strong> e a gente conclui a ativação com você.</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Empresa sem pedidos: reengajamento',
                'subject' => '{{primeiro_nome}}, vamos fazer a {{empresa}} voltar a vender?',
                'preheader' => 'Um checklist rápido para os pedidos voltarem a entrar.',
                'blocks' => [
                    $this->header('Faz um tempo que não chegam pedidos'),
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}!</p>
                        <p>Notamos que a {{empresa}} não recebe pedidos pelo Veddi há alguns dias. Estes pontos resolvem a maioria dos casos:</p>
                        <ul>
                            <li><strong>Loja aberta:</strong> a filial está ativa e o horário de funcionamento está certo?</li>
                            <li><strong>Cardápio disponível:</strong> os produtos estão ativos, com foto e com estoque?</li>
                            <li><strong>Link divulgado:</strong> o link da loja está na bio do Instagram e no WhatsApp?</li>
                            <li><strong>Um empurrãozinho:</strong> um cupom de desconto ou de frete grátis traz o cliente de volta.</li>
                        </ul>
                        HTML),
                    $this->button('Revisar minha loja'),
                    $this->text(<<<'HTML'
                        <p>Se algo não estiver funcionando como deveria, responda este e-mail que a gente olha com você.</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Dicas para vender mais',
                'subject' => '5 ideias para a {{empresa}} vender mais pelo Veddi',
                'preheader' => 'Fotos, cupons, adicionais e relatórios que ajudam no dia a dia.',
                'blocks' => [
                    $this->header('Ideias para aumentar suas vendas'),
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}! Separamos ações simples que fazem diferença no número de pedidos:</p>
                        <h3>1. Capriche nas fotos</h3>
                        <p>Produto com foto boa vende mais. Use luz natural, fundo limpo e mostre o produto de perto.</p>
                        <h3>2. Crie um cupom de primeira compra</h3>
                        <p>Desconto em valor ou percentual, frete grátis ou um produto de brinde. Você define validade, limite de usos e valor mínimo do pedido.</p>
                        <h3>3. Ofereça adicionais</h3>
                        <p>Grupos de opções como tamanho, adicionais e ponto da carne deixam o cliente montar o pedido do jeito dele e aumentam o ticket médio.</p>
                        <h3>4. Divulgue o link em todo lugar</h3>
                        <p>Bio do Instagram, status do WhatsApp, perfil do Google e um QR Code no balcão.</p>
                        <h3>5. Olhe os relatórios toda semana</h3>
                        <p>Receita, ticket médio e formas de pagamento por período e por filial mostram o que está funcionando.</p>
                        HTML),
                    $this->button('Criar um cupom', $this->link('admin.coupons.index')),
                    $this->text(<<<HTML
                        <p>Cada recurso tem o passo a passo no <a href="{$docs}">guia do Veddi</a>.</p>
                        <p>Bons pedidos!<br>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Upgrade do plano Grátis',
                'subject' => '{{primeiro_nome}}, a {{empresa}} está pronta para crescer?',
                'preheader' => 'Conheça o que muda nos planos Essencial e PRO.',
                'blocks' => [
                    $this->header('Hora de tirar os limites da sua loja'),
                    $this->text('<p>Hoje a {{empresa}} está no plano {{plano}}. Quando os pedidos aumentam, os planos Essencial e PRO dão mais espaço para crescer:</p>'
                        .'<ul>'.implode('', $this->upgradeBenefits()).'</ul>'),
                    $this->button('Ver os planos', $this->link('admin.billing')),
                    $this->text(<<<'HTML'
                        <p>A troca é feita no painel, em <strong>Assinatura</strong>, onde você também vê o valor de cada plano. Em dúvida sobre qual escolher? Responda este e-mail.</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Conheça o PDV',
                'subject' => '{{primeiro_nome}}, venda no balcão e nas mesas pelo Veddi',
                'preheader' => 'Caixa, comandas e pagamento em dinheiro, cartão ou PIX no mesmo painel dos pedidos online.',
                'blocks' => [
                    $this->header('O balcão da {{empresa}} no mesmo sistema'),
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}!</p>
                        <p>Com o <strong>PDV do Veddi</strong>, a venda presencial fica no mesmo painel dos pedidos online:</p>
                        <ul>
                            <li><strong>Balcão ou mesa/comanda:</strong> venda direta ou conta aberta, com itens adicionados até fechar;</li>
                            <li><strong>Carrinho rápido:</strong> catálogo, busca por nome ou leitor de código de barras;</li>
                            <li><strong>Pagamento:</strong> dinheiro com troco calculado, maquininha de cartão ou PIX conferido no balcão;</li>
                            <li><strong>Caixa organizado:</strong> abertura, suprimento, sangria e fechamento com o valor esperado, por operador e por filial;</li>
                            <li><strong>Recibo impresso</strong> e cada venda no histórico, junto com os pedidos do chat.</li>
                        </ul>
                        HTML),
                    $this->text('<p>O PDV é um módulo opcional de <strong>'.self::money(AddonModulePricing::price('pdv')).' por mês</strong>, somado à mensalidade do plano. Você ativa ou cancela quando quiser, em <strong>Assinatura</strong>.</p>'),
                    $this->button('Ativar o PDV', $this->link('admin.billing')),
                    $this->text(<<<HTML
                        <p>O passo a passo está no <a href="{$docs}#pdv">guia do Veddi</a>. Dúvidas? Responda este e-mail.</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Nota fiscal pelo Veddi',
                'subject' => 'Emita a NFC-e dos pedidos da {{empresa}} sem sair do Veddi',
                'preheader' => 'Nota na hora da venda no PDV ou em lote para os pedidos online.',
                'blocks' => [
                    $this->header('Nota fiscal sem trocar de sistema'),
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}!</p>
                        <p>Com o <strong>módulo Fiscal</strong>, a {{empresa}} emite a Nota Fiscal de Consumidor Eletrônica (NFC-e) direto do painel:</p>
                        <ul>
                            <li><strong>No PDV:</strong> marque “Emitir e imprimir nota fiscal” antes de confirmar o pagamento, com CPF ou CNPJ na nota se o cliente pedir;</li>
                            <li><strong>Pedidos online:</strong> os pedidos pagos do chat, do iFood e os agendados ficam no Controle de notas para emitir em lote;</li>
                            <li><strong>Consulta e cancelamento:</strong> busca pela chave de acesso ou pelo número do pedido, e cancelamento com justificativa.</li>
                        </ul>
                        HTML),
                    $this->text('<p>O módulo Fiscal custa <strong>'.self::money(AddonModulePricing::price('fiscal')).' por mês</strong>, somado à mensalidade do plano, e é ativado em <strong>Assinatura</strong>. Antes da primeira nota, preencha regime tributário, inscrição estadual e série da NFC-e nas configurações.</p>'),
                    $this->button('Ativar a nota fiscal', $this->link('admin.billing')),
                    $this->text(<<<HTML
                        <p>Veja os detalhes no <a href="{$docs}#fiscal">guia do Veddi</a>. Se precisar de ajuda com a configuração fiscal, responda este e-mail.</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Lançamento de novidade',
                'subject' => 'Novidade no Veddi: [EDITAR: nome da novidade]',
                'preheader' => '[EDITAR: uma frase sobre o que muda para o restaurante]',
                'blocks' => [
                    $this->header('Chegou: [EDITAR: nome da novidade]'),
                    ['type' => 'image', 'url' => '', 'alt' => '', 'link' => ''],
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}!</p>
                        <p>[EDITAR: explique em uma ou duas frases o que é a novidade e qual problema ela resolve.]</p>
                        <h3>O que muda para a {{empresa}}</h3>
                        <ul>
                            <li>[EDITAR: benefício 1]</li>
                            <li>[EDITAR: benefício 2]</li>
                            <li>[EDITAR: benefício 3]</li>
                        </ul>
                        <p><strong>Onde encontrar:</strong> [EDITAR: caminho no painel, por exemplo Relatórios → Itens vendidos].</p>
                        HTML),
                    $this->button('Experimentar agora'),
                    $this->text(<<<'HTML'
                        <p>Conta para a gente o que achou: é só responder este e-mail.</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Comunicado importante',
                'subject' => 'Aviso importante: [EDITAR: assunto do comunicado]',
                'preheader' => '[EDITAR: resumo do aviso em uma frase]',
                'blocks' => [
                    $this->header('[EDITAR: título do comunicado]'),
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}.</p>
                        <p>[EDITAR: o que vai acontecer ou o que mudou.]</p>
                        <p><strong>Quando:</strong> [EDITAR: data e horário de Brasília]<br><strong>O que muda para a {{empresa}}:</strong> [EDITAR: efeito nos pedidos, no painel ou nos pagamentos]<br><strong>Precisa fazer algo?</strong> [EDITAR: ação necessária, ou "Não, nada muda para você."]</p>
                        <p>Se tiver qualquer dúvida, responda este e-mail.</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
            [
                'name' => 'Pesquisa de satisfação',
                'subject' => '{{primeiro_nome}}, de 0 a 10, quanto você recomendaria o Veddi?',
                'preheader' => 'Leva 10 segundos: responda este e-mail com uma nota.',
                'blocks' => [
                    $this->header('Sua opinião melhora o Veddi'),
                    $this->text(<<<'HTML'
                        <p>Olá, {{primeiro_nome}}!</p>
                        <p>Queremos saber como está a experiência da {{empresa}} com o Veddi. Basta <strong>responder este e-mail</strong> com:</p>
                        <ol>
                            <li>uma nota de <strong>0 a 10</strong> para o quanto você recomendaria o Veddi a outro restaurante;</li>
                            <li>o que mais ajuda no seu dia a dia;</li>
                            <li>o que podemos melhorar.</li>
                        </ol>
                        <p>A equipe lê todas as respostas. Obrigado pelo seu tempo!</p>
                        <p>Equipe Veddi</p>
                        HTML),
                ],
            ],
        ];
    }

    /** @return list<string> itens <li> com os números atuais de config/plans.php */
    private function upgradeBenefits(): array
    {
        $freeLimit = Plan::Free->maxOrdersPerMonth();
        $freeFee = Plan::Free->feePercentage();
        $paidFee = min(Plan::Essencial->feePercentage(), Plan::Pro->feePercentage());
        $proBranches = Plan::Pro->maxBranches();

        return array_values(array_filter([
            $freeLimit !== null && Plan::Essencial->maxOrdersPerMonth() === null
                ? "<li><strong>Pedidos ilimitados:</strong> no Grátis, o limite é de {$freeLimit} pedidos por mês;</li>"
                : null,
            match (true) {
                $paidFee <= 0 && $freeFee > 0 => '<li><strong>Sem taxa da plataforma por pedido</strong> (no Grátis, ela é de '.self::percent($freeFee).');</li>',
                $paidFee < $freeFee => '<li><strong>Taxa por pedido menor:</strong> '.self::percent($paidFee).' em vez de '.self::percent($freeFee).';</li>',
                default => null,
            },
            '<li><strong>A cara da sua marca:</strong> logo e cores personalizadas no link da loja;</li>',
            $proBranches > Plan::Free->maxBranches()
                ? "<li><strong>Mais unidades:</strong> o PRO permite até {$proBranches} filiais.</li>"
                : null,
        ]));
    }

    private static function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    private static function percent(float $rate): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($rate * 100, 1, '.', ''), '0'), '.')).'%';
    }

    /** Links e logo ficam gravados no template, então saem do APP_URL (ver TemplateRenderer::publicUrl). */
    private function link(string $route): string
    {
        return TemplateRenderer::publicUrl(route($route, absolute: false));
    }

    private function header(string $title): array
    {
        return ['type' => 'header', 'title' => $title, 'logo_url' => TemplateRenderer::publicUrl('images/email/veddi-logo.png')];
    }

    private function text(string $html): array
    {
        return ['type' => 'text', 'html' => $html];
    }

    private function button(string $label, string $url = '{{link_painel}}'): array
    {
        return ['type' => 'button', 'label' => $label, 'url' => $url];
    }
}
