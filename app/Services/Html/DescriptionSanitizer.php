<?php

namespace App\Services\Html;

use DOMAttr;
use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class DescriptionSanitizer
{
    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 'br', 'p', 'ul', 'ol', 'li', 'span', 'small', 'a'];

    /**
     * Tags descartadas junto com o conteúdo: o texto delas é código ou
     * markup bruto (o navegador não o trata como texto comum), então
     * promovê-lo ao desembrulhar só exibiria lixo ou reabriria o vetor.
     */
    private const DROPPED_WITH_CONTENT_TAGS = [
        'script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed',
        'textarea', 'title', 'xmp', 'noembed', 'noframes', 'plaintext', 'head',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
    ];

    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Sanitiza descrição de produto vinda do admin antes de renderizar sem
     * escape (`{!! !!}`) no cardápio público. Usa DOMDocument (allowlist real
     * de tag/atributo/scheme) em vez de regex sobre string — regex não dá
     * conta de variações de aspas/entidades em atributo de evento ou scheme
     * ofuscado, um parser sim.
     */
    public static function sanitize(?string $html): string
    {
        if (! $html) {
            return '';
        }

        $html = nl2br($html, false);

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $container = $dom->getElementsByTagName('div')->item(0);

        if (! $container) {
            return '';
        }

        self::sanitizeChildren($container);

        $clean = '';
        foreach (iterator_to_array($container->childNodes) as $child) {
            $clean .= $dom->saveHTML($child);
        }

        return $clean;
    }

    private static function sanitizeChildren(DOMNode $node): void
    {
        // Percorre a lista viva de filhos (firstChild/nextSibling) em vez de
        // um snapshot: ao desembrulhar uma tag não permitida, os filhos
        // promovidos passam a ser os próximos visitados e também são
        // sanitizados. Com o snapshot, `<font><img onerror=...></font>`
        // promovia o <img> sem nunca passar pela allowlist.
        $child = $node->firstChild;

        while ($child !== null) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROPPED_WITH_CONTENT_TAGS, true)) {
                    $node->removeChild($child);
                } elseif (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Remove a tag mas preserva o conteúdo (mesmo comportamento
                    // do strip_tags original); o laço continua pelo primeiro
                    // filho promovido.
                    $next = $child->firstChild ?? $next;

                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                } else {
                    self::sanitizeAttributes($child, $tag);
                    self::sanitizeChildren($child);
                }
            } elseif (! $child instanceof DOMText || $child instanceof DOMCdataSection) {
                // Comentário, CDATA, processing instruction etc.: o
                // serializador devolve esses nós sem escape.
                $node->removeChild($child);
            }

            $child = $next;
        }
    }

    private static function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        /** @var DOMAttr $attr */
        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            $name = strtolower($attr->name);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attr->name);

                continue;
            }

            if ($name === 'href' && ! self::isSafeUrl($attr->value)) {
                $element->removeAttribute($attr->name);
            }
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === null) {
            return true;
        }

        return in_array(strtolower($scheme), self::ALLOWED_URL_SCHEMES, true);
    }
}
