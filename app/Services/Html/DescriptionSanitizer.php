<?php

namespace App\Services\Html;

class DescriptionSanitizer
{
    private const ALLOWED_TAGS = '<b><strong><i><em><u><br><p><ul><ol><li><span><small><a>';

    public static function sanitize(?string $html): string
    {
        if (! $html) {
            return '';
        }

        $clean = strip_tags($html, self::ALLOWED_TAGS);

        $clean = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $clean);
        $clean = preg_replace("/\s+on\w+\s*=\s*'[^']*'/i", '', $clean);
        $clean = preg_replace('/(href|src)\s*=\s*"javascript:[^"]*"/i', '$1="#"', $clean);
        $clean = preg_replace("/(href|src)\s*=\s*'javascript:[^']*'/i", "$1='#'", $clean);

        return nl2br($clean, false);
    }
}
