<?php

namespace App\ClinicalDocuments;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

final class ClinicalHtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_ELEMENTS = [
        'a', 'b', 'blockquote', 'br', 'code', 'div', 'em', 'font', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 's',
        'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot',
        'th', 'thead', 'tr', 'u', 'ul',
    ];

    /** @var list<string> */
    private const DROP_WITH_CONTENT = [
        'audio', 'base', 'button', 'canvas', 'embed', 'form', 'iframe', 'input',
        'link', 'math', 'meta', 'noscript', 'object', 'option', 'picture', 'script',
        'select', 'source', 'style', 'svg', 'template', 'textarea', 'track', 'video',
    ];

    /** @var list<string> */
    private const ALLOWED_STYLE_PROPERTIES = [
        'background-color', 'border', 'border-bottom', 'border-collapse',
        'border-left', 'border-right', 'border-top', 'color', 'font-family',
        'font-size', 'font-style', 'font-weight', 'margin', 'margin-bottom',
        'margin-left', 'margin-right', 'margin-top', 'padding', 'padding-bottom',
        'padding-left', 'padding-right', 'padding-top', 'text-align',
        'text-decoration', 'width',
    ];

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
                LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (! $loaded) {
            throw new RuntimeException('Clinical document HTML could not be parsed safely.');
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            throw new RuntimeException('Clinical document HTML has no safe body.');
        }

        $this->sanitizeChildren($body);
        $sanitized = '';

        foreach (iterator_to_array($body->childNodes) as $child) {
            $fragment = $document->saveHTML($child);

            if (is_string($fragment)) {
                $sanitized .= $fragment;
            }
        }

        $sanitized = trim($sanitized);

        return $sanitized === '' ? null : $sanitized;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        $child = $parent->firstChild;

        while ($child instanceof DOMNode) {
            $next = $child->nextSibling;

            if ($child->nodeType === XML_COMMENT_NODE
                || $child->nodeType === XML_PI_NODE) {
                $parent->removeChild($child);
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $parent->removeChild($child);
                } elseif (! in_array($tag, self::ALLOWED_ELEMENTS, true)) {
                    $this->sanitizeChildren($child);
                    $this->unwrap($child);
                } else {
                    $disposition = $this->sanitizeElement($child, $tag);

                    if ($disposition === 'remove') {
                        $parent->removeChild($child);
                    } else {
                        $this->sanitizeChildren($child);

                        if ($disposition === 'unwrap') {
                            $this->unwrap($child);
                        }
                    }
                }
            }

            $child = $next;
        }
    }

    /** @return 'keep'|'remove'|'unwrap' */
    private function sanitizeElement(DOMElement $element, string $tag): string
    {
        $attributes = [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attributes[strtolower($attribute->nodeName)] = $attribute->nodeValue ?? '';
        }

        foreach (array_keys($attributes) as $name) {
            $element->removeAttribute($name);
        }

        $direction = strtolower(trim($attributes['dir'] ?? ''));

        if (in_array($direction, ['ltr', 'rtl', 'auto'], true)) {
            $element->setAttribute('dir', $direction);
        }

        $style = $this->sanitizeStyle($attributes['style'] ?? '');

        if ($style !== '') {
            $element->setAttribute('style', $style);
        }

        if ($tag === 'a') {
            $href = $this->safeLink($attributes['href'] ?? '');

            if ($href === null) {
                return 'unwrap';
            }

            $element->setAttribute('href', $href);
            $element->setAttribute('rel', 'noopener noreferrer');
            $element->setAttribute('target', '_blank');
        }

        if ($tag === 'img') {
            $source = $this->safeEmbeddedImage($attributes['src'] ?? '');

            if ($source === null) {
                return 'remove';
            }

            $element->setAttribute('src', $source);
            $element->setAttribute('alt', mb_substr(strip_tags($attributes['alt'] ?? ''), 0, 200));
            $this->copyBoundedIntegerAttribute($element, $attributes, 'width', 1, 2000);
            $this->copyBoundedIntegerAttribute($element, $attributes, 'height', 1, 2000);
        }

        if ($tag === 'font') {
            $face = trim($attributes['face'] ?? '');

            if (in_array($face, ['Arial', 'Calibri', 'Georgia', 'Times New Roman'], true)) {
                $element->setAttribute('face', $face);
            }

            $this->copyBoundedIntegerAttribute($element, $attributes, 'size', 1, 7);
            $color = $this->safeColor($attributes['color'] ?? '');

            if ($color !== null) {
                $element->setAttribute('color', $color);
            }
        }

        if (in_array($tag, ['td', 'th'], true)) {
            $this->copyBoundedIntegerAttribute($element, $attributes, 'colspan', 1, 20);
            $this->copyBoundedIntegerAttribute($element, $attributes, 'rowspan', 1, 100);
        }

        return 'keep';
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /** @param array<string, string> $attributes */
    private function copyBoundedIntegerAttribute(
        DOMElement $element,
        array $attributes,
        string $name,
        int $minimum,
        int $maximum,
    ): void {
        $value = $attributes[$name] ?? '';

        if (preg_match('/\A[0-9]{1,4}\z/D', $value) !== 1) {
            return;
        }

        $number = (int) $value;

        if ($number >= $minimum && $number <= $maximum) {
            $element->setAttribute($name, (string) $number);
        }
    }

    private function safeLink(string $value): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($value === ''
            || strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! is_string($parts['scheme'] ?? null)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'mailto') {
            $address = substr($value, strlen('mailto:'));

            return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : 'mailto:'.$address;
        }

        if ($scheme !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }

        return $value;
    }

    private function safeEmbeddedImage(string $value): ?string
    {
        $value = trim($value);

        if (strlen($value) > 350_000
            || preg_match('#\Adata:image/(png|jpeg|webp);base64,([A-Za-z0-9+/]+={0,2})\z#Di', $value, $matches) !== 1) {
            return null;
        }

        $bytes = base64_decode($matches[2], true);

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 256 * 1024) {
            return null;
        }

        $image = @getimagesizefromstring($bytes);

        if (! is_array($image)) {
            return null;
        }

        $mime = $image['mime'];

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)
            || $image[0] < 1
            || $image[1] < 1
            || $image[0] > 2000
            || $image[1] > 2000) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function sanitizeStyle(string $style): string
    {
        $safe = [];

        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);

            if (! is_string($property) || ! is_string($value)) {
                continue;
            }

            $property = strtolower(trim($property));
            $value = trim($value);

            if (! in_array($property, self::ALLOWED_STYLE_PROPERTIES, true)
                || $value === ''
                || strlen($value) > 120
                || preg_match('/(?:url|expression|javascript|behavior|binding|@|\\\\|[\x00-\x1f\x7f])/i', $value) === 1
                || preg_match('/\A[-#(),.%\sa-zA-Z0-9\'\"]+\z/D', $value) !== 1) {
                continue;
            }

            $safe[] = $property.': '.$value;
        }

        return implode('; ', $safe);
    }

    private function safeColor(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/\A#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?\z/D', $value) === 1
            ? strtolower($value)
            : null;
    }
}
