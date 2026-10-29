<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Marketing\EmailHtmlSanitizer;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => marketingSetup());

test('variáveis são substituídas e escapadas', function () {
    $campaign = EmailCampaign::factory()->sending()->create();
    $recipient = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->create([
        'variables' => TemplateRenderer::variablesFor('Zé <script>alert(1)</script>', 'Coxinhas & Cia', 'pro'),
    ]);

    $email = app(TemplateRenderer::class)->render($campaign, $recipient);

    expect($email->html)
        ->toContain('Coxinhas &amp; Cia')
        ->toContain('Oi, Zé')
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('{{empresa}}')
        ->and($email->subject)->toBe('Novidade para Coxinhas & Cia');
});

test('{{ }} com código PHP ou Blade não executa', function () {
    $renderer = app(TemplateRenderer::class);
    $blocks = [
        ['type' => 'text', 'html' => '{{ phpinfo() }} {{ $user->password }} @php echo "x"; @endphp {!! "raw" !!} <?php echo "php"; ?>'],
    ];

    $html = $renderer->preview($renderer->blocksToHtml($blocks), null, $renderer->sampleVariables());

    expect($html)
        ->not->toContain('PHP Version')
        ->toContain('{{ phpinfo() }}')
        ->not->toContain('<?php');
});

test('HTML com script, onerror e javascript: é removido no salvamento', function () {
    $clean = app(EmailHtmlSanitizer::class)->sanitize(
        '<p onclick="x()">Oi</p><script>alert(1)</script><img src=x onerror=alert(1)>'
        .'<a href="javascript:alert(1)">mal</a><a href="https://veddi.com.br" onmouseover="x">bom</a>'
        .'<font><img src=x onerror=alert(2)></font><style>body{}</style><iframe src="https://x"></iframe>'
    );

    expect($clean)
        ->not->toContain('script')
        ->not->toContain('onerror')
        ->not->toContain('onclick')
        ->not->toContain('onmouseover')
        ->not->toContain('javascript:')
        ->not->toContain('<img')
        ->not->toContain('<iframe')
        ->not->toContain('<style')
        ->toContain('href="https://veddi.com.br"')
        ->toContain('<p style="margin:0 0 16px;line-height:1.6;">Oi</p>');
});

test('link {{link_painel}} sobrevive à sanitização e vira a URL do painel', function () {
    $renderer = app(TemplateRenderer::class);
    $blocks = $renderer->normalizeBlocks([
        ['type' => 'text', 'html' => '<a href="{{link_painel}}">Entrar</a>'],
        ['type' => 'button', 'label' => 'Painel', 'url' => '{{ link_painel }}'],
    ]);

    $body = $renderer->blocksToHtml($blocks);
    $html = $renderer->preview($body, null, $renderer->sampleVariables());

    expect($body)->toContain('href="{{link_painel}}"')->not->toContain('%7B')
        ->and($html)->toContain('href="'.route('login').'"');
});

test('URL de botão e imagem só aceita http(s) ou {{link_painel}}', function () {
    $blocks = app(TemplateRenderer::class)->normalizeBlocks([
        ['type' => 'button', 'label' => 'X', 'url' => 'javascript:alert(1)'],
        ['type' => 'image', 'url' => 'data:image/png;base64,AAA', 'link' => 'vbscript:x'],
        ['type' => 'evil', 'html' => '<script>'],
    ]);

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]['url'])->toBe('')
        ->and($blocks[1]['url'])->toBe('')
        ->and($blocks[1]['link'])->toBe('');
});

test('e-mail final tem descadastro, pixel, links rastreados e cabeçalhos One-Click', function () {
    $campaign = EmailCampaign::factory()->sending()->create();
    $recipient = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->create();

    $email = app(TemplateRenderer::class)->render($campaign, $recipient);

    expect($email->headers['List-Unsubscribe'])->toStartWith('<'.url('/email/u/'.$recipient->id))
        ->and($email->headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($email->html)->toContain('/email/o/'.$recipient->id)
        ->and($email->html)->toContain('/email/c/'.$recipient->id)
        ->and($email->html)->not->toContain('href="https://veddi.com.br/novidades"')
        ->and($email->html)->toContain('Veddi Tecnologia LTDA')
        ->and($email->html)->not->toContain(TemplateRenderer::UNSUBSCRIBE_TOKEN)
        ->and($email->tags)->toBe(['campaign_id' => (string) $campaign->id, 'recipient_id' => (string) $recipient->id])
        ->and($email->text)->toContain('Ver novidade');
});

test('variável desconhecida é detectada', function () {
    expect(app(TemplateRenderer::class)->unknownVariables('Oi {{nome}}', '{{cupom}} e {{ empresa }}'))->toBe(['cupom']);
});

test('só {{link_painel}} vale como link inteiro (variáveis do cadastro não viram href)', function () {
    $clean = app(EmailHtmlSanitizer::class)->sanitize('<a href="{{empresa}}">x</a><a href="{{link_painel}}">y</a>');

    expect($clean)->toContain('<a style="color:#7A00A3;text-decoration:underline;">x</a>')
        ->not->toContain('{{empresa}}')
        ->toContain('href="{{link_painel}}"');
});
