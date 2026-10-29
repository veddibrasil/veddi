<?php

namespace App\Services\Marketing;

use DOMAttr;
use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Sanitiza o HTML de bloco de texto do e-mail marketing no salvamento (allowlist de tag,
 * atributo e scheme via DOMDocument, como o DescriptionSanitizer) e aplica estilo inline,
 * porque Gmail e Outlook ignoram boa parte do CSS em <style>.
 *
 * As variáveis {{nome}} ficam protegidas durante o parse: o libxml codifica "{" e "}" em
 * href (%7B%7D) e o link {{link_painel}} deixaria de ser substituído.
 */
class EmailHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'span', 'small', 'h1', 'h2', 'h3', 'blockquote',
    ];

    private const DROPPED_WITH_CONTENT_TAGS = [
        'script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'form',
        'textarea', 'title', 'xmp', 'noembed', 'noframes', 'plaintext', 'head', 'link', 'meta', 'base',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
    ];

    private const INLINE_STYLES = [
        'p' => 'margin:0 0 16px;line-height:1.6;',
        'h1' => 'margin:0 0 16px;font-size:26px;line-height:1.3;font-weight:700;color:#18181b;',
        'h2' => 'margin:0 0 14px;font-size:21px;line-height:1.3;font-weight:700;color:#18181b;',
        'h3' => 'margin:0 0 12px;font-size:17px;line-height:1.4;font-weight:700;color:#18181b;',
        'a' => 'color:#7A00A3;text-decoration:underline;',
        'ul' => 'margin:0 0 16px;padding-left:22px;',
        'ol' => 'margin:0 0 16px;padding-left:22px;',
        'li' => 'margin:0 0 6px;line-height:1.6;',
        'blockquote' => 'margin:0 0 16px;padding:8px 16px;border-left:3px solid #e4e4e7;color:#52525b;',
    ];

    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitize(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        // Texto digitado sem tags (textarea) vira parágrafos.
        if (strip_tags($html) === $html) {
            $html = collect(preg_split('/\R{2,}/', trim($html)))
                ->map(fn (string $paragraph) => '<p>'.nl2br(e($paragraph), false).'</p>')
                ->implode('');
        }

        $html = Placeholders::protect($html);

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $container = $dom->getElementsByTagName('div')->item(0);

        if (! $container) {
            return '';
        }

        $this->sanitizeChildren($container);

        $clean = '';
        foreach (iterator_to_array($container->childNodes) as $child) {
            $clean .= $dom->saveHTML($child);
        }

        return Placeholders::restore($clean);
    }

    /** URL de botão/imagem: só http(s) absoluto ou uma variável de link ({{link_painel}}, {{link_cadastro}}). */
    public static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (preg_match('/^\{\{\s*([a-z_]+)\s*\}\}$/', $url, $match) && in_array($match[1], TemplateRenderer::LINK_VARIABLES, true)) {
            return '{{'.$match[1].'}}';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function sanitizeChildren(DOMNode $node): void
    {
        // Lista viva (firstChild/nextSibling): os filhos promovidos ao desembrulhar uma tag
        // proibida também passam pela allowlist (ver DescriptionSanitizer).
        $child = $node->firstChild;

        while ($child !== null) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROPPED_WITH_CONTENT_TAGS, true)) {
                    $node->removeChild($child);
                } elseif (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    $next = $child->firstChild ?? $next;

                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                } else {
                    $this->sanitizeAttributes($child, $tag);
                    $this->sanitizeChildren($child);
                }
            } elseif (! $child instanceof DOMText || $child instanceof DOMCdataSection) {
                $node->removeChild($child);
            }

            $child = $next;
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        /** @var DOMAttr $attr */
        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            $name = strtolower($attr->name);

            if (! in_array($name, $allowed, true) || ($name === 'href' && ! $this->isSafeHref($attr->value))) {
                $element->removeAttribute($attr->name);
            }
        }

        if (isset(self::INLINE_STYLES[$tag])) {
            $element->setAttribute('style', self::INLINE_STYLES[$tag]);
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('target', '_blank');
        }
    }

    /** Link de e-mail precisa ser absoluto: relativo ("/x", "#") não abre fora do painel. */
    private function isSafeHref(string $url): bool
    {
        $url = trim($url);

        // Variável como link inteiro só as de link: {{empresa}} etc. vêm do cadastro do restaurante.
        if (Placeholders::isProtected($url)) {
            return collect(TemplateRenderer::LINK_VARIABLES)->contains(fn (string $name) => $url === Placeholders::protect('{{'.$name.'}}'));
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), self::URL_SCHEMES, true);
    }
}
