<?php

namespace App\ClinicalDocuments;

/**
 * One place for the two shapes a document template body can take and for
 * the {{placeholder}} substitution shared by every rendering path.
 *
 * - 'text': the historical line-based body. A line starting with "## " is a
 *   heading, everything else is plain text.
 * - 'html': a body written in the Word-like editor (or imported from .docx),
 *   stored after ClinicalHtmlSanitizer.
 *
 * resources/js/lib/documentTemplateBody.ts mirrors these rules on the client
 * so the consultation preview and the Word export produce the same document.
 */
final class TemplateBody
{
    public const FORMAT_TEXT = 'text';

    public const FORMAT_HTML = 'html';

    /** Maximum stored body length: leaves room for a few inline images. */
    public const MAX_LENGTH = 2_000_000;

    public static function normalizeFormat(?string $format): string
    {
        return $format === self::FORMAT_HTML ? self::FORMAT_HTML : self::FORMAT_TEXT;
    }

    /**
     * Convert a legacy line-based body to HTML. Text is escaped; {{tokens}}
     * are kept verbatim so they can still be substituted afterwards.
     */
    public static function textToHtml(string $text): string
    {
        $html = '';
        $paragraph = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $heading = preg_match('/\A##\s+(.+)\z/u', $line, $matches) === 1;

            if ($heading || trim($line) === '') {
                $html .= self::paragraph($paragraph);
                $paragraph = [];
            }

            if ($heading) {
                $html .= '<h3>'.self::escape(trim($matches[1])).'</h3>';
            } elseif (trim($line) !== '') {
                $paragraph[] = self::escape($line);
            }
        }

        return $html.self::paragraph($paragraph);
    }

    /**
     * @param  list<string>  $lines  already escaped
     */
    private static function paragraph(array $lines): string
    {
        return $lines === [] ? '' : '<p>'.implode('<br>', $lines).'</p>';
    }

    /**
     * The body as HTML, whatever its stored format.
     */
    public static function toHtml(string $body, ?string $format): string
    {
        return self::normalizeFormat($format) === self::FORMAT_HTML
            ? $body
            : self::textToHtml($body);
    }

    /**
     * Substitute {{placeholders}} inside an HTML body. Values are escaped and
     * their line breaks become <br>; unknown placeholders are left untouched
     * when $keepUnknown is true, otherwise they render empty.
     *
     * @param  array<string, string>  $variables
     */
    public static function renderHtml(string $html, array $variables, bool $keepUnknown = true): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
            static function (array $match) use ($variables, $keepUnknown): string {
                if (! array_key_exists($match[1], $variables)) {
                    return $keepUnknown ? $match[0] : '';
                }

                return nl2br(self::escape($variables[$match[1]]), false);
            },
            $html,
        );
    }

    /**
     * Whether a body that arrived without an explicit format looks like HTML.
     */
    public static function looksLikeHtml(string $body): bool
    {
        return preg_match('/\A\s*<(?:p|h[1-6]|div|table|ul|ol|blockquote|hr|img|span|strong|em|b|i|u|br|figure)\b/i', $body) === 1;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
