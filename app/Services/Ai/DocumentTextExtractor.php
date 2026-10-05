<?php

namespace App\Services\Ai;

use ZipArchive;

/**
 * Best-effort text from an uploaded file, without a PDF library.
 *
 * - DOCX: the paragraphs of word/document.xml.
 * - PDF: the text operators of each (Flate-decoded) content stream. This reads
 *   lab reports exported by software; a scanned PDF has no text and returns
 *   an empty string, so the caller asks for a photo instead.
 */
final class DocumentTextExtractor
{
    public function extract(string $absolutePath, ?string $mimeType, ?string $filename): string
    {
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

        $text = match (true) {
            $extension === 'docx' || $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->docx($absolutePath),
            $extension === 'pdf' || $mimeType === 'application/pdf' => $this->pdf($absolutePath),
            $extension === 'txt' || str_starts_with((string) $mimeType, 'text/') => (string) @file_get_contents($absolutePath),
            default => '',
        };

        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim(mb_substr($text, 0, 12000));
    }

    private function docx(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1);
    }

    private function pdf(string $path): string
    {
        $raw = (string) @file_get_contents($path, length: 15 * 1024 * 1024);

        if ($raw === '' || ! preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams)) {
            return '';
        }

        $text = '';

        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            $decoded = $decoded === false ? @gzinflate($stream) : $decoded;
            $content = $decoded === false ? $stream : $decoded;

            if (! str_contains($content, 'BT')) {
                continue;
            }

            $text .= $this->pdfTextOperators($content)."\n";
        }

        // Keep only readable output; binary noise means the extraction failed.
        $printable = (string) preg_replace('/[^\p{L}\p{N}\p{P}\p{S}\s]/u', '', mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1'));

        return mb_strlen(trim($printable)) >= 40 ? $printable : '';
    }

    private function pdfTextOperators(string $content): string
    {
        $out = '';

        preg_match_all('/BT(.*?)ET/s', $content, $blocks);

        foreach ($blocks[1] as $block) {
            preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*(?:Tj|\'|")|\[(.*?)\]\s*TJ|(T\*|Td|TD|Tm)/s', $block, $ops, PREG_SET_ORDER);

            foreach ($ops as $op) {
                // Group 2 is the positioning operator (T*, Td, TD, Tm): a new
                // line for the vertical movers, a space otherwise.
                if (isset($op[2]) && $op[2] !== '') {
                    $out .= in_array($op[2], ['T*', 'Td', 'TD'], true) ? "\n" : ' ';

                    continue;
                }

                // Group 1 is the contents of a [...] TJ array: a run of string
                // fragments interleaved with numeric kerning adjustments.
                if (isset($op[1]) && $op[1] !== '') {
                    preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|(-?\d+(?:\.\d+)?)/', $op[1], $parts, PREG_SET_ORDER);

                    foreach ($parts as $part) {
                        if (isset($part[1]) && $part[1] !== '' && (float) $part[1] < -200) {
                            $out .= ' ';
                        } elseif (str_starts_with($part[0], '(')) {
                            $out .= $this->unescape(substr($part[0], 1, -1));
                        }
                    }

                    continue;
                }

                if (preg_match('/^\((.*)\)/s', $op[0], $literal)) {
                    $out .= $this->unescape($literal[1]);
                }
            }

            $out .= "\n";
        }

        return $out;
    }

    private function unescape(string $value): string
    {
        $value = (string) preg_replace_callback('/\\\\([0-7]{1,3})/', static fn (array $m): string => chr(octdec($m[1]) & 0xFF), $value);

        return strtr($value, ['\\n' => "\n", '\\r' => '', '\\t' => ' ', '\\(' => '(', '\\)' => ')', '\\\\' => '\\']);
    }
}
