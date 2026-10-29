<?php

namespace App\Services\Marketing;

use App\DTOs\RenderedEmailData;
use App\Enums\Plan;
use App\Models\Company;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Support\Str;

/**
 * Blocos → HTML do e-mail marketing. O conteúdo do usuário nunca é compilado como Blade:
 * blocos viram HTML escapado/sanitizado, e as variáveis de uma lista fechada são trocadas por
 * substituição simples (com escape). Só o layout (arquivo do repositório) é Blade.
 */
class TemplateRenderer
{
    public const VARIABLES = [
        'nome' => 'Nome do usuário',
        'primeiro_nome' => 'Primeiro nome',
        'empresa' => 'Nome da empresa',
        'plano' => 'Plano da empresa',
        'link_painel' => 'Link para entrar no painel',
        'link_cadastro' => 'Link para criar a conta (possíveis clientes)',
    ];

    // Variáveis que podem ser o link inteiro de um botão, imagem ou <a> (as demais vêm do cadastro).
    public const LINK_VARIABLES = ['link_painel', 'link_cadastro'];

    // Só fazem sentido para quem já tem conta: contato externo não tem plano nem painel.
    public const CUSTOMER_ONLY_VARIABLES = ['plano', 'link_painel'];

    public const BLOCK_TYPES = [
        'header' => 'Cabeçalho',
        'text' => 'Texto',
        'image' => 'Imagem',
        'button' => 'Botão',
        'divider' => 'Divisor',
        'footer' => 'Rodapé',
    ];

    // Marcadores do layout trocados por destinatário (alfanuméricos: sobrevivem a qualquer parse).
    public const UNSUBSCRIBE_TOKEN = 'VDDINT_UNSUBSCRIBE_URL';

    public const PIXEL_TOKEN = 'VDDINT_PIXEL_TAG';

    private const DEFAULT_BUTTON_COLOR = '#7A00A3';

    public function __construct(
        private readonly EmailHtmlSanitizer $sanitizer,
        private readonly TrackingService $tracking,
    ) {}

    public static function newBlock(string $type): array
    {
        $block = match ($type) {
            'header' => ['title' => '', 'logo_url' => ''],
            'text' => ['html' => ''],
            'image' => ['url' => '', 'alt' => '', 'link' => ''],
            'button' => ['label' => 'Acessar o painel', 'url' => '{{link_painel}}', 'color' => self::DEFAULT_BUTTON_COLOR],
            'footer' => ['text' => ''],
            default => [],
        };

        return ['key' => Str::random(8), 'type' => $type] + $block;
    }

    /** Mantém só tipos e campos conhecidos; texto sanitizado, URLs só http(s) ou variável de link. */
    public function normalizeBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (! is_string($type) || ! array_key_exists($type, self::BLOCK_TYPES)) {
                continue;
            }

            $text = fn (string $field, int $limit) => Str::limit(trim((string) ($block[$field] ?? '')), $limit, '');

            $normalized[] = ['key' => preg_replace('/[^A-Za-z0-9]/', '', (string) ($block['key'] ?? '')) ?: Str::random(8), 'type' => $type] + match ($type) {
                'header' => ['title' => $text('title', 200), 'logo_url' => EmailHtmlSanitizer::safeUrl($block['logo_url'] ?? null) ?? ''],
                'text' => ['html' => $this->sanitizer->sanitize((string) ($block['html'] ?? ''))],
                'image' => [
                    'url' => EmailHtmlSanitizer::safeUrl($block['url'] ?? null) ?? '',
                    'alt' => $text('alt', 200),
                    'link' => EmailHtmlSanitizer::safeUrl($block['link'] ?? null) ?? '',
                ],
                'button' => [
                    'label' => $text('label', 80),
                    'url' => EmailHtmlSanitizer::safeUrl($block['url'] ?? null) ?? '',
                    'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($block['color'] ?? '')) ? $block['color'] : self::DEFAULT_BUTTON_COLOR,
                ],
                'footer' => ['text' => $text('text', 1000)],
                default => [],
            };
        }

        return $normalized;
    }

    /** Corpo do e-mail (sem layout), com as variáveis ainda por substituir. */
    public function blocksToHtml(array $blocks): string
    {
        return collect($this->normalizeBlocks($blocks))
            ->map(fn (array $block) => $this->renderBlock($block))
            ->filter()
            ->implode("\n");
    }

    /** Layout + corpo, com marcadores no lugar dos links por destinatário. Congelado no disparo. */
    public function snapshot(EmailCampaign $campaign): string
    {
        return $this->layout(
            body: $campaign->html ?: $this->blocksToHtml($campaign->blocks ?? []),
            preheader: $campaign->preheader,
            unsubscribeUrl: self::UNSUBSCRIBE_TOKEN,
            pixelTag: self::PIXEL_TOKEN,
        );
    }

    public function render(EmailCampaign $campaign, EmailCampaignRecipient $recipient): RenderedEmailData
    {
        $variables = $recipient->variables ?? [];
        $unsubscribeUrl = $this->tracking->unsubscribeUrl($recipient);

        $html = Placeholders::replace($campaign->html_snapshot ?: $this->snapshot($campaign), $variables);
        $html = $this->tracking->rewriteLinks($html, $recipient);
        $html = strtr($html, [
            self::UNSUBSCRIBE_TOKEN => e($unsubscribeUrl),
            self::PIXEL_TOKEN => '<img src="'.e($this->tracking->openPixelUrl($recipient)).'" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;" />',
        ]);

        return new RenderedEmailData(
            toEmail: $recipient->email,
            toName: $recipient->name,
            fromEmail: $this->fromEmail($campaign),
            fromName: $this->fromName($campaign),
            replyTo: $campaign->reply_to ?: config('marketing.reply_to'),
            subject: $this->subject($campaign->subjectForVariant($recipient->variant), $variables),
            html: $html,
            text: $this->toText($html),
            headers: [
                'List-Unsubscribe' => '<'.$unsubscribeUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
            tags: [
                'campaign_id' => (string) $campaign->id,
                'recipient_id' => (string) $recipient->id,
            ],
            idempotencyKey: "campaign-{$campaign->id}-recipient-{$recipient->id}",
        );
    }

    /** Envio de teste: sem rastreio nem destinatário gravado; assunto com [Teste]. */
    public function renderTest(EmailCampaign $campaign, string $toEmail, array $variables, ?string $variant = null): RenderedEmailData
    {
        $html = $this->preview(
            body: $campaign->html ?: $this->blocksToHtml($campaign->blocks ?? []),
            preheader: $campaign->preheader,
            variables: $variables,
        );

        return new RenderedEmailData(
            toEmail: $toEmail,
            toName: null,
            fromEmail: $this->fromEmail($campaign),
            fromName: $this->fromName($campaign),
            replyTo: $campaign->reply_to ?: config('marketing.reply_to'),
            subject: '[Teste] '.$this->subject($campaign->subjectForVariant($variant ?? 'a'), $variables),
            html: $html,
            text: $this->toText($html),
            tags: ['campaign_id' => (string) $campaign->id, 'test' => '1'],
        );
    }

    /** HTML completo para o preview do painel (iframe sandbox) e para o envio de teste. */
    public function preview(string $body, ?string $preheader, array $variables): string
    {
        $html = $this->layout($body, $preheader, self::UNSUBSCRIBE_TOKEN, '');

        return strtr(Placeholders::replace($html, $variables), [self::UNSUBSCRIBE_TOKEN => '#']);
    }

    /**
     * Destinos que o redirect de clique aceita para este destinatário: os links que estão no
     * HTML da campanha (com as variáveis dele). Evita redirect aberto.
     *
     * @return list<string>
     */
    public function trackableLinks(EmailCampaign $campaign, EmailCampaignRecipient $recipient): array
    {
        $html = Placeholders::replace($campaign->html_snapshot ?: $this->snapshot($campaign), $recipient->variables ?? []);

        preg_match_all('/<a\b[^>]*?\shref="(https?:[^"]+)"/i', $html, $matches);

        return array_values(array_unique(array_map(
            fn (string $url) => html_entity_decode($url, ENT_QUOTES | ENT_HTML5),
            $matches[1],
        )));
    }

    /** @return list<string> variáveis usadas que não existem na lista fechada */
    public function unknownVariables(?string ...$texts): array
    {
        $used = collect($texts)->filter()->flatMap(fn (string $text) => Placeholders::names($text));

        return $used->reject(fn (string $name) => array_key_exists($name, self::VARIABLES))->unique()->values()->all();
    }

    /** @return list<string> variáveis usadas que só existem para quem já é cliente */
    public function customerOnlyVariables(?string ...$texts): array
    {
        $used = collect($texts)->filter()->flatMap(fn (string $text) => Placeholders::names($text));

        return $used->intersect(self::CUSTOMER_ONLY_VARIABLES)->unique()->values()->all();
    }

    /** @return list<string> trechos "[EDITAR: ...]" dos modelos padrão que ainda não foram trocados */
    public function pendingEdits(?string ...$texts): array
    {
        preg_match_all('/\[\s*EDITAR\s*:[^\]]*\]/iu', implode("\n", array_filter($texts)), $matches);

        return array_values(array_unique($matches[0]));
    }

    /** Variáveis de um destinatário (congeladas em email_campaign_recipients.variables). */
    public static function variablesFor(?string $userName, ?string $companyName, mixed $plan): array
    {
        $name = trim((string) $userName);
        $planLabel = $plan instanceof Plan ? $plan->label() : (Plan::tryFrom((string) $plan)?->label() ?? '');

        return [
            'nome' => $name,
            'primeiro_nome' => Str::before($name, ' ') ?: $name,
            'empresa' => (string) $companyName,
            'plano' => $planLabel,
            'link_painel' => self::publicUrl(route('login', absolute: false)),
            'link_cadastro' => self::publicUrl(route('register.create', absolute: false)),
        ];
    }

    /**
     * URL absoluta pelo APP_URL. Link que vai dentro do e-mail não pode sair de route()/asset()
     * absolutos: numa requisição eles usam o host de quem está no painel (ex.: localhost).
     */
    public static function publicUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /** Variáveis de exemplo para preview/teste: dados de uma empresa real quando informada. */
    public function sampleVariables(?int $companyId = null): array
    {
        $company = $companyId ? Company::withoutGlobalScopes()->find($companyId) : null;

        if (! $company) {
            return self::variablesFor('Maria Souza', 'Coxinharia Exemplo', Plan::Pro);
        }

        $admin = $company->users()->wherePivot('role', 'company_admin')->orderBy('users.id')->first();

        return self::variablesFor($admin?->name ?? 'Maria Souza', $company->name, $company->plan);
    }

    public function toText(string $html): string
    {
        $text = preg_replace('#<(head|style|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<span[^>]*display:none[^>]*>.*?</span>#is', '', $text) ?? $text;
        $text = preg_replace_callback('#<a\b[^>]*?\shref="([^"]+)"[^>]*>(.*?)</a>#is', function (array $match) {
            $label = trim(strip_tags($match[2]));
            $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);

            return $label !== '' && $label !== $url ? "{$label} ({$url})" : $url;
        }, $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|h[1-6]|li|tr|div|blockquote)>#i', "\n\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n[ \t]+/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private function subject(string $subject, array $variables): string
    {
        return trim(str_replace(["\r", "\n"], ' ', Placeholders::replace($subject, $variables, escape: false)));
    }

    private function fromEmail(EmailCampaign $campaign): string
    {
        return $campaign->from_email ?: (string) config('marketing.from.address');
    }

    private function fromName(EmailCampaign $campaign): string
    {
        return $campaign->from_name ?: (string) config('marketing.from.name');
    }

    private function layout(string $body, ?string $preheader, string $unsubscribeUrl, string $pixelTag): string
    {
        return view('emails.marketing.layout', [
            'body' => $body,
            'preheader' => $preheader,
            'unsubscribeUrl' => $unsubscribeUrl,
            'pixelTag' => $pixelTag,
            'legal' => config('marketing.legal'),
            'appName' => config('app.name'),
        ])->render();
    }

    private function renderBlock(array $block): string
    {
        return match ($block['type']) {
            'header' => $this->headerBlock($block),
            'text' => $block['html'] === '' ? '' : '<tr><td style="padding:0 32px 8px;font-size:15px;color:#3f3f46;">'.$block['html'].'</td></tr>',
            'image' => $this->imageBlock($block),
            'button' => $this->buttonBlock($block),
            'divider' => '<tr><td style="padding:8px 32px 24px;"><div style="border-top:1px solid #e4e4e7;font-size:0;line-height:0;">&nbsp;</div></td></tr>',
            'footer' => $block['text'] === '' ? '' : '<tr><td style="padding:8px 32px 24px;font-size:13px;line-height:1.6;color:#71717a;">'.nl2br(e($block['text']), false).'</td></tr>',
            default => '',
        };
    }

    private function headerBlock(array $block): string
    {
        $logo = $block['logo_url'] !== ''
            ? '<img src="'.e($block['logo_url']).'" alt="" height="40" style="display:block;margin:0 0 16px;border:0;height:40px;width:auto;" />'
            : '';
        $title = $block['title'] !== ''
            ? '<h1 style="margin:0;font-size:24px;line-height:1.3;font-weight:700;color:#18181b;">'.e($block['title']).'</h1>'
            : '';

        return $logo.$title === '' ? '' : '<tr><td style="padding:32px 32px 16px;">'.$logo.$title.'</td></tr>';
    }

    private function imageBlock(array $block): string
    {
        if ($block['url'] === '') {
            return '';
        }

        $image = '<img src="'.e($block['url']).'" alt="'.e($block['alt']).'" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:8px;" />';

        if ($block['link'] !== '') {
            $image = '<a href="'.e($block['link']).'" target="_blank" style="text-decoration:none;">'.$image.'</a>';
        }

        return '<tr><td style="padding:8px 32px 16px;">'.$image.'</td></tr>';
    }

    private function buttonBlock(array $block): string
    {
        if ($block['label'] === '' || $block['url'] === '') {
            return '';
        }

        $color = e($block['color']);

        return '<tr><td style="padding:8px 32px 24px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td style="border-radius:8px;background:'.$color.';">'
            .'<a href="'.e($block['url']).'" target="_blank" style="display:inline-block;padding:12px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">'.e($block['label']).'</a>'
            .'</td></tr></table></td></tr>';
    }
}
