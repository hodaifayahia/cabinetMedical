<?php

namespace App\ClinicalDocuments;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Convert the (sanitized) HTML written in the document editor into the
 * WordprocessingML body of a .docx: paragraphs, headings, inline formatting,
 * alignment, indentation, bullet / numbered lists, tables, horizontal rules,
 * manual page breaks and embedded raster images.
 *
 * The converter is deliberately small and offline: it only understands the
 * HTML subset ClinicalHtmlSanitizer lets through. One instance converts one
 * document (it collects the images and list definitions it meets).
 */
final class HtmlToWordprocessingMl
{
    private const BLOCK_TAGS = [
        'blockquote', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'li',
        'ol', 'p', 'pre', 'table', 'ul', 'figure', 'caption',
    ];

    /** Heading font sizes, in half-points. */
    private const HEADING_SIZES = ['h1' => 32, 'h2' => 28, 'h3' => 26, 'h4' => 24, 'h5' => 22, 'h6' => 22];

    private const NAMED_COLORS = [
        'black' => '000000', 'white' => 'FFFFFF', 'red' => 'FF0000', 'green' => '008000',
        'blue' => '0000FF', 'yellow' => 'FFFF00', 'orange' => 'FFA500', 'gray' => '808080',
        'grey' => '808080', 'purple' => '800080', 'navy' => '000080', 'maroon' => '800000',
    ];

    /** @var list<array{id: string, filename: string, bytes: string, extension: string, content_type: string}> */
    private array $images = [];

    /** @var array<int, array{abstract: int, start: int}> */
    private array $lists = [];

    private int $drawingId = 100;

    private int $contentWidthTwips = 9906;

    /**
     * @return array{
     *     xml: string,
     *     images: list<array{id: string, filename: string, bytes: string, extension: string, content_type: string}>,
     *     numbering: string|null
     * }
     */
    public function convert(string $html, int $contentWidthTwips): array
    {
        $this->images = [];
        $this->lists = [];
        $this->contentWidthTwips = max(1440, $contentWidthTwips);

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
                LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $xml = $body instanceof DOMElement ? $this->blocks($body, [], []) : '';

        return [
            'xml' => $xml === '' ? '<w:p/>' : $xml,
            'images' => $this->images,
            'numbering' => $this->lists === [] ? null : $this->numberingXml(),
        ];
    }

    /**
     * Render the children of a block container. Impure: it records the
     * images and lists it meets on the converter.
     *
     * @phpstan-impure
     *
     * @param  array<string, mixed>  $paragraph  inherited paragraph properties
     * @param  array<string, mixed>  $run  inherited run properties
     * @param  array{numId: int, ilvl: int, used: bool}|null  $listItem
     */
    private function blocks(DOMNode $parent, array $paragraph, array $run, ?array &$listItem = null): string
    {
        $xml = '';
        $inline = [];

        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), self::BLOCK_TAGS, true)) {
                $xml .= $this->inlineParagraph($inline, $paragraph, $run, $listItem);
                $inline = [];
                $xml .= $this->block($child, $paragraph, $run, $listItem);
            } elseif ($child instanceof DOMElement || $child instanceof DOMText) {
                $inline[] = $child;
            }
        }

        return $xml.$this->inlineParagraph($inline, $paragraph, $run, $listItem);
    }

    /**
     * Wrap loose inline content (text, spans, images…) in a paragraph.
     *
     * @param  list<DOMNode>  $nodes
     * @param  array<string, mixed>  $paragraph
     * @param  array<string, mixed>  $run
     * @param  array{numId: int, ilvl: int, used: bool}|null  $listItem
     */
    private function inlineParagraph(array $nodes, array $paragraph, array $run, ?array &$listItem): string
    {
        $runs = '';

        foreach ($nodes as $node) {
            $runs .= $this->inline($node, $run, false);
        }

        if (trim(strip_tags($runs)) === '' && ! str_contains($runs, '<w:drawing') && ! str_contains($runs, '<w:br')) {
            return '';
        }

        return $this->paragraphXml($paragraph, $runs, $listItem);
    }

    /**
     * @param  array<string, mixed>  $paragraph
     * @param  array<string, mixed>  $run
     * @param  array{numId: int, ilvl: int, used: bool}|null  $listItem
     */
    private function block(DOMElement $element, array $paragraph, array $run, ?array &$listItem): string
    {
        $tag = strtolower($element->tagName);
        $style = $this->styles($element);

        if ($tag === 'hr') {
            return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="999999"/></w:pBdr></w:pPr></w:p>';
        }

        if ($tag === 'div' && in_array('page-break', preg_split('/\s+/', (string) $element->getAttribute('class')) ?: [], true)) {
            return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }

        if ($tag === 'table') {
            return $this->table($element, $run);
        }

        if ($tag === 'ul' || $tag === 'ol') {
            return $this->listXml($element, $paragraph, $run, $listItem);
        }

        if ($tag === 'li') {
            // A stray <li> outside a list renders as a plain paragraph.
            return $this->blocks($element, $paragraph, $run, $listItem);
        }

        $paragraph = $this->paragraphProperties($paragraph, $style);
        $run = $this->runProperties($run, $style);

        if (isset(self::HEADING_SIZES[$tag])) {
            // Bold and size come from the "heading N" style in styles.xml.
            $paragraph['style'] = 'Heading'.substr($tag, 1);
        }

        if ($tag === 'blockquote') {
            $paragraph['ind'] = (int) ($paragraph['ind'] ?? 0) + 567;
            $paragraph['quote'] = true;
        }

        if ($tag === 'pre') {
            $run['font'] = 'Courier New';
            $run['pre'] = true;
        }

        if ($tag === 'caption') {
            $paragraph['jc'] ??= 'center';
            $run['i'] = true;
        }

        $xml = $this->blocks($element, $paragraph, $run, $listItem);

        // An empty paragraph is a deliberate blank line in the editor.
        if ($xml === '' && ($tag === 'p' || $tag === 'pre' || isset(self::HEADING_SIZES[$tag]))) {
            $xml = $this->paragraphXml($paragraph, '', $listItem);
        }

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $paragraph
     * @param  array<string, mixed>  $run
     * @param  array{numId: int, ilvl: int, used: bool}|null  $parentItem
     */
    private function listXml(DOMElement $list, array $paragraph, array $run, ?array $parentItem): string
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $start = max(1, (int) ($list->getAttribute('start') ?: 1));
        $numId = count($this->lists) + 1;
        $this->lists[$numId] = ['abstract' => $ordered ? 2 : 1, 'start' => $start];
        $level = $parentItem === null ? 0 : min(8, $parentItem['ilvl'] + 1);
        $xml = '';

        foreach (iterator_to_array($list->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $item = ['numId' => $numId, 'ilvl' => $level, 'used' => false];

            if (strtolower($child->tagName) === 'li') {
                $itemXml = $this->blocks($child, $this->paragraphProperties($paragraph, $this->styles($child)), $run, $item);

                if (! $item['used']) {
                    // An empty item still shows its bullet.
                    $itemXml = $this->paragraphXml($paragraph, '', $item).$itemXml;
                }

                $xml .= $itemXml;
            } elseif (in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                $xml .= $this->listXml($child, $paragraph, $run, $item);
            }
        }

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function table(DOMElement $table, array $run): string
    {
        $rows = [];

        foreach (iterator_to_array($table->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if ($tag === 'tr') {
                $rows[] = $child;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                foreach (iterator_to_array($child->childNodes) as $row) {
                    if ($row instanceof DOMElement && strtolower($row->tagName) === 'tr') {
                        $rows[] = $row;
                    }
                }
            }
        }

        if ($rows === []) {
            return '';
        }

        // Lay the cells out on a grid so row spans become vertical merges.
        /** @var array<int, array<int, array{type: 'cell'|'merge'|'covered', cell: DOMElement, colspan: int, rowspan: int}>> $grid */
        $grid = [];
        $columnWidths = [];

        foreach ($rows as $rowIndex => $row) {
            $column = 0;

            foreach (iterator_to_array($row->childNodes) as $cell) {
                if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }

                while (isset($grid[$rowIndex][$column])) {
                    $column++;
                }

                $colspan = max(1, min(20, (int) ($cell->getAttribute('colspan') ?: 1)));
                $rowspan = max(1, min(100, (int) ($cell->getAttribute('rowspan') ?: 1)));
                $widths = array_map('intval', array_filter(explode(',', $cell->getAttribute('colwidth'))));

                foreach ($widths as $offset => $width) {
                    if ($width > 0) {
                        $columnWidths[$column + $offset] ??= $width;
                    }
                }

                for ($r = 0; $r < $rowspan && $rowIndex + $r < count($rows); $r++) {
                    for ($c = 0; $c < $colspan; $c++) {
                        $grid[$rowIndex + $r][$column + $c] = [
                            'type' => $r === 0 && $c === 0 ? 'cell' : ($c === 0 ? 'merge' : 'covered'),
                            'cell' => $cell,
                            'colspan' => $colspan,
                            'rowspan' => $rowspan,
                        ];
                    }
                }

                $column += $colspan;
            }
        }

        $columns = 1;

        foreach ($grid as $cells) {
            $columns = max($columns, $cells === [] ? 1 : max(array_keys($cells)) + 1);
        }

        $widths = $this->gridWidths($columns, $columnWidths);
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="'.array_sum($widths).'" w:type="dxa"/>'
            .'<w:tblBorders>'
            .'<w:top w:val="single" w:sz="4" w:space="0" w:color="666666"/>'
            .'<w:left w:val="single" w:sz="4" w:space="0" w:color="666666"/>'
            .'<w:bottom w:val="single" w:sz="4" w:space="0" w:color="666666"/>'
            .'<w:right w:val="single" w:sz="4" w:space="0" w:color="666666"/>'
            .'<w:insideH w:val="single" w:sz="4" w:space="0" w:color="666666"/>'
            .'<w:insideV w:val="single" w:sz="4" w:space="0" w:color="666666"/>'
            .'</w:tblBorders><w:tblLayout w:type="fixed"/>'
            .'<w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar>'
            .'</w:tblPr><w:tblGrid>';

        foreach ($widths as $width) {
            $xml .= '<w:gridCol w:w="'.$width.'"/>';
        }

        $xml .= '</w:tblGrid>';

        foreach (array_keys($rows) as $rowIndex) {
            $xml .= '<w:tr>';

            for ($column = 0; $column < $columns; $column++) {
                $slot = $grid[$rowIndex][$column] ?? null;

                if ($slot === null) {
                    $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$widths[$column].'" w:type="dxa"/></w:tcPr><w:p/></w:tc>';

                    continue;
                }

                if ($slot['type'] === 'covered') {
                    continue;
                }

                if ($slot['type'] === 'merge') {
                    $span = $slot['colspan'];
                    $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.array_sum(array_slice($widths, $column, $span)).'" w:type="dxa"/>'
                        .($span > 1 ? '<w:gridSpan w:val="'.$span.'"/>' : '')
                        .'<w:vMerge/></w:tcPr><w:p/></w:tc>';

                    continue;
                }

                $cell = $slot['cell'];
                $span = $slot['colspan'];
                $style = $this->styles($cell);
                $cellRun = $this->runProperties($run, $style);

                if (strtolower($cell->tagName) === 'th') {
                    $cellRun['b'] = true;
                }

                $fill = $this->color($style['background-color'] ?? '');
                $content = $this->blocks($cell, $this->paragraphProperties([], $style), $cellRun);

                if ($content === '' || ! str_ends_with($content, '</w:p>') && ! str_ends_with($content, '<w:p/>')) {
                    $content .= '<w:p/>';
                }

                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.array_sum(array_slice($widths, $column, $span)).'" w:type="dxa"/>'
                    .($span > 1 ? '<w:gridSpan w:val="'.$span.'"/>' : '')
                    .($slot['rowspan'] > 1 ? '<w:vMerge w:val="restart"/>' : '')
                    .($fill !== null ? '<w:shd w:val="clear" w:color="auto" w:fill="'.$fill.'"/>' : '')
                    .'</w:tcPr>'.$content.'</w:tc>';
            }

            $xml .= '</w:tr>';
        }

        // Word needs a paragraph between two consecutive tables.
        return $xml.'</w:tbl><w:p><w:pPr><w:spacing w:after="0"/></w:pPr></w:p>';
    }

    /**
     * @param  array<int, int>  $pixelWidths
     * @return list<int>
     */
    private function gridWidths(int $columns, array $pixelWidths): array
    {
        $total = $this->contentWidthTwips;
        $known = [];

        for ($column = 0; $column < $columns; $column++) {
            if (isset($pixelWidths[$column])) {
                $known[$column] = $pixelWidths[$column] * 15;
            }
        }

        $knownSum = array_sum($known);
        $unknownCount = $columns - count($known);

        if ($knownSum > $total || ($unknownCount === 0 && $knownSum > 0)) {
            $scale = $total / max(1, $knownSum + ($unknownCount > 0 ? $unknownCount * 1000 : 0));
            $widths = [];

            for ($column = 0; $column < $columns; $column++) {
                $widths[] = max(200, (int) round(($known[$column] ?? 1000) * $scale));
            }

            return $widths;
        }

        $remaining = max(200 * max(1, $unknownCount), $total - $knownSum);
        $widths = [];

        for ($column = 0; $column < $columns; $column++) {
            $widths[] = $known[$column] ?? max(200, intdiv($remaining, max(1, $unknownCount)));
        }

        return $widths;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function inline(DOMNode $node, array $run, bool $insideLink): string
    {
        if ($node instanceof DOMText) {
            $text = $node->wholeText;

            if (! ($run['pre'] ?? false)) {
                $text = (string) preg_replace('/\s+/u', ' ', $text);
            }

            if ($text === '') {
                return '';
            }

            if ($run['pre'] ?? false) {
                $parts = preg_split('/\R/u', $text) ?: [];
                $xml = '';

                foreach ($parts as $index => $part) {
                    if ($index > 0) {
                        $xml .= '<w:r>'.$this->runPropertiesXml($run).'<w:br/></w:r>';
                    }

                    $xml .= $this->textRun($part, $run);
                }

                return $xml;
            }

            return $this->textRun($text, $run);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'br') {
            return '<w:r><w:br/></w:r>';
        }

        if ($tag === 'img') {
            return $this->imageRun($node);
        }

        $run = $this->runProperties($run, $this->styles($node));

        switch ($tag) {
            case 'b':
            case 'strong':
                $run['b'] = true;
                break;
            case 'i':
            case 'em':
                $run['i'] = true;
                break;
            case 'u':
                $run['u'] = true;
                break;
            case 's':
            case 'strike':
            case 'del':
                $run['strike'] = true;
                break;
            case 'sub':
                $run['vert'] = 'subscript';
                break;
            case 'sup':
                $run['vert'] = 'superscript';
                break;
            case 'code':
                $run['font'] = 'Courier New';
                break;
            case 'mark':
                $run['fill'] ??= $this->color($node->getAttribute('data-color')) ?? 'FFF2A8';
                break;
            case 'a':
                $run['u'] = true;
                $run['color'] ??= '1F4E9D';
                break;
            case 'font':
                if ($node->getAttribute('face') !== '') {
                    $run['font'] = $node->getAttribute('face');
                }

                if (($color = $this->color($node->getAttribute('color'))) !== null) {
                    $run['color'] = $color;
                }

                break;
        }

        $xml = '';

        foreach (iterator_to_array($node->childNodes) as $child) {
            $xml .= $this->inline($child, $run, $insideLink || $tag === 'a');
        }

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function textRun(string $text, array $run): string
    {
        return '<w:r>'.$this->runPropertiesXml($run)
            .'<w:t xml:space="preserve">'.$this->xml($text).'</w:t></w:r>';
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function runPropertiesXml(array $run): string
    {
        $xml = '';

        if (is_string($run['font'] ?? null) && $run['font'] !== '') {
            $font = $this->xml($run['font']);
            $xml .= '<w:rFonts w:ascii="'.$font.'" w:hAnsi="'.$font.'" w:cs="'.$font.'"/>';
        }

        if ($run['b'] ?? false) {
            $xml .= '<w:b/><w:bCs/>';
        }

        if ($run['i'] ?? false) {
            $xml .= '<w:i/><w:iCs/>';
        }

        if ($run['strike'] ?? false) {
            $xml .= '<w:strike/>';
        }

        if (is_string($run['color'] ?? null)) {
            $xml .= '<w:color w:val="'.$run['color'].'"/>';
        }

        if (is_int($run['sz'] ?? null)) {
            $xml .= '<w:sz w:val="'.$run['sz'].'"/><w:szCs w:val="'.$run['sz'].'"/>';
        }

        if ($run['u'] ?? false) {
            $xml .= '<w:u w:val="single"/>';
        }

        if (is_string($run['fill'] ?? null)) {
            $xml .= '<w:shd w:val="clear" w:color="auto" w:fill="'.$run['fill'].'"/>';
        }

        if (is_string($run['vert'] ?? null)) {
            $xml .= '<w:vertAlign w:val="'.$run['vert'].'"/>';
        }

        return $xml === '' ? '' : '<w:rPr>'.$xml.'</w:rPr>';
    }

    /**
     * @param  array<string, mixed>  $paragraph
     * @param  array{numId: int, ilvl: int, used: bool}|null  $listItem
     */
    private function paragraphXml(array $paragraph, string $runs, ?array &$listItem): string
    {
        $properties = '';

        if (is_string($paragraph['style'] ?? null)) {
            $properties .= '<w:pStyle w:val="'.$paragraph['style'].'"/>';
        }

        if ($listItem !== null && ! $listItem['used']) {
            $properties .= '<w:numPr><w:ilvl w:val="'.$listItem['ilvl'].'"/><w:numId w:val="'.$listItem['numId'].'"/></w:numPr>';
            $listItem['used'] = true;
        } elseif ($listItem !== null) {
            $paragraph['ind'] = (int) ($paragraph['ind'] ?? 0) + 720 * ($listItem['ilvl'] + 1);
        }

        if ($paragraph['quote'] ?? false) {
            $properties .= '<w:pBdr><w:left w:val="single" w:sz="12" w:space="8" w:color="BBBBBB"/></w:pBdr>';
        }

        if (is_int($paragraph['line'] ?? null)) {
            $properties .= '<w:spacing w:line="'.$paragraph['line'].'" w:lineRule="auto"/>';
        }

        $left = (int) ($paragraph['ind'] ?? 0);
        $first = (int) ($paragraph['firstLine'] ?? 0);

        if ($left > 0 || $first !== 0) {
            $properties .= '<w:ind w:left="'.max(0, $left).'"'
                .($first > 0 ? ' w:firstLine="'.$first.'"' : '')
                .($first < 0 ? ' w:hanging="'.abs($first).'"' : '')
                .'/>';
        }

        if (is_string($paragraph['jc'] ?? null)) {
            $properties .= '<w:jc w:val="'.$paragraph['jc'].'"/>';
        }

        return '<w:p>'.($properties === '' ? '' : '<w:pPr>'.$properties.'</w:pPr>').$runs.'</w:p>';
    }

    /**
     * @param  array<string, mixed>  $paragraph
     * @param  array<string, string>  $style
     * @return array<string, mixed>
     */
    private function paragraphProperties(array $paragraph, array $style): array
    {
        $paragraph['jc'] = match (strtolower($style['text-align'] ?? '')) {
            'center' => 'center',
            'right', 'end' => 'right',
            'justify' => 'both',
            'left', 'start' => 'left',
            default => $paragraph['jc'] ?? null,
        };

        if (isset($style['margin-left']) && ($twips = $this->twips($style['margin-left'])) !== null && $twips > 0) {
            $paragraph['ind'] = (int) ($paragraph['ind'] ?? 0) + $twips;
        }

        if (isset($style['padding-left']) && ($twips = $this->twips($style['padding-left'])) !== null && $twips > 0) {
            $paragraph['ind'] = (int) ($paragraph['ind'] ?? 0) + $twips;
        }

        if (isset($style['text-indent']) && ($twips = $this->twips($style['text-indent'])) !== null) {
            $paragraph['firstLine'] = $twips;
        }

        if (isset($style['line-height']) && preg_match('/\A([0-9]*\.?[0-9]+)\z/', trim($style['line-height']), $matches) === 1) {
            $paragraph['line'] = max(120, min(1200, (int) round((float) $matches[1] * 240)));
        }

        return $paragraph;
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, string>  $style
     * @return array<string, mixed>
     */
    private function runProperties(array $run, array $style): array
    {
        if (($color = $this->color($style['color'] ?? '')) !== null) {
            $run['color'] = $color;
        }

        if (($fill = $this->color($style['background-color'] ?? '')) !== null) {
            $run['fill'] = $fill;
        }

        if (isset($style['font-family'])) {
            $family = trim(explode(',', $style['font-family'])[0], " \t\"'");

            if ($family !== '' && preg_match('/\A[\pL0-9 \-]{1,60}\z/u', $family) === 1
                && ! in_array(strtolower($family), ['serif', 'sans-serif', 'monospace', 'inherit', 'initial'], true)) {
                $run['font'] = $family;
            }
        }

        if (isset($style['font-size']) && ($size = $this->halfPoints($style['font-size'])) !== null) {
            $run['sz'] = $size;
        }

        $weight = strtolower($style['font-weight'] ?? '');

        if ($weight === 'bold' || $weight === 'bolder' || (ctype_digit($weight) && (int) $weight >= 600)) {
            $run['b'] = true;
        } elseif ($weight === 'normal' || (ctype_digit($weight) && (int) $weight < 600)) {
            $run['b'] = false;
        }

        if (strtolower($style['font-style'] ?? '') === 'italic') {
            $run['i'] = true;
        }

        $decoration = strtolower($style['text-decoration'] ?? '');

        if (str_contains($decoration, 'underline')) {
            $run['u'] = true;
        }

        if (str_contains($decoration, 'line-through')) {
            $run['strike'] = true;
        }

        return $run;
    }

    private function imageRun(DOMElement $image): string
    {
        $source = trim($image->getAttribute('src'));

        if (preg_match('#\Adata:image/(png|jpeg|jpg|gif|webp);base64,([A-Za-z0-9+/]+={0,2})\z#Di', $source, $matches) !== 1) {
            return '';
        }

        $bytes = base64_decode($matches[2], true);

        if (! is_string($bytes) || $bytes === '') {
            return '';
        }

        $info = @getimagesizefromstring($bytes);

        if (! is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return '';
        }

        $format = match ($info['mime']) {
            'image/png' => ['png', 'image/png'],
            'image/jpeg' => ['jpeg', 'image/jpeg'],
            'image/gif' => ['gif', 'image/gif'],
            default => null,
        };

        if ($format === null && $info['mime'] === 'image/webp' && function_exists('imagecreatefromstring')) {
            $resource = @imagecreatefromstring($bytes);

            if ($resource !== false) {
                ob_start();
                imagepng($resource);
                $converted = ob_get_clean();
                $bytes = is_string($converted) ? $converted : '';
                $format = $bytes === '' ? null : ['png', 'image/png'];
            }
        }

        if ($format === null) {
            return '';
        }

        [$naturalWidth, $naturalHeight] = [(int) $info[0], (int) $info[1]];
        $style = $this->styles($image);
        $width = $this->pixels($style['width'] ?? '') ?? (ctype_digit($image->getAttribute('width')) ? (int) $image->getAttribute('width') : null);
        $height = $this->pixels($style['height'] ?? '') ?? (ctype_digit($image->getAttribute('height')) ? (int) $image->getAttribute('height') : null);

        if ($width === null && $height === null) {
            [$width, $height] = [$naturalWidth, $naturalHeight];
        } elseif ($width === null) {
            $width = (int) round($height * $naturalWidth / $naturalHeight);
        } elseif ($height === null) {
            $height = (int) round($width * $naturalHeight / $naturalWidth);
        }

        $widthEmu = max(1, $width * 9525);
        $heightEmu = max(1, (int) $height * 9525);
        $maximumWidth = $this->contentWidthTwips * 635;

        if ($widthEmu > $maximumWidth) {
            $heightEmu = (int) round($heightEmu * $maximumWidth / $widthEmu);
            $widthEmu = $maximumWidth;
        }

        $index = count($this->images) + 1;
        $id = 'rIdImg'.$index;
        $this->images[] = [
            'id' => $id,
            'filename' => 'image'.$index.'.'.$format[0],
            'bytes' => $bytes,
            'extension' => $format[0],
            'content_type' => $format[1],
        ];
        $docPrId = $this->drawingId++;
        $name = $this->xml(mb_substr($image->getAttribute('alt') ?: 'Image '.$index, 0, 120));

        return '<w:r><w:drawing>'
            .'<wp:inline distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$widthEmu.'" cy="'.$heightEmu.'"/>'
            .'<wp:docPr id="'.$docPrId.'" name="'.$name.'"/>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic><pic:nvPicPr><pic:cNvPr id="'.$docPrId.'" name="'.$name.'"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="'.$id.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$widthEmu.'" cy="'.$heightEmu.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    private function numberingXml(): string
    {
        $bullets = ['•', '◦', '▪'];
        $formats = [['decimal', '%s.'], ['lowerLetter', '%s.'], ['lowerRoman', '%s.']];
        $bulletLevels = '';
        $numberLevels = '';

        for ($level = 0; $level < 9; $level++) {
            $indent = 'w:left="'.(720 * ($level + 1)).'" w:hanging="360"';
            $bulletLevels .= '<w:lvl w:ilvl="'.$level.'"><w:start w:val="1"/><w:numFmt w:val="bullet"/>'
                .'<w:lvlText w:val="'.$bullets[$level % 3].'"/><w:lvlJc w:val="left"/>'
                .'<w:pPr><w:ind '.$indent.'/></w:pPr></w:lvl>';
            [$format, $text] = $formats[$level % 3];
            $numberLevels .= '<w:lvl w:ilvl="'.$level.'"><w:start w:val="1"/><w:numFmt w:val="'.$format.'"/>'
                .'<w:lvlText w:val="'.sprintf($text, '%'.($level + 1)).'"/><w:lvlJc w:val="left"/>'
                .'<w:pPr><w:ind '.$indent.'/></w:pPr></w:lvl>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="hybridMultilevel"/>'.$bulletLevels.'</w:abstractNum>'
            .'<w:abstractNum w:abstractNumId="2"><w:multiLevelType w:val="hybridMultilevel"/>'.$numberLevels.'</w:abstractNum>';

        foreach ($this->lists as $numId => $list) {
            $xml .= '<w:num w:numId="'.$numId.'"><w:abstractNumId w:val="'.$list['abstract'].'"/>';

            if ($list['abstract'] === 2) {
                for ($level = 0; $level < 9; $level++) {
                    $xml .= '<w:lvlOverride w:ilvl="'.$level.'"><w:startOverride w:val="'.($level === 0 ? $list['start'] : 1).'"/></w:lvlOverride>';
                }
            }

            $xml .= '</w:num>';
        }

        return $xml.'</w:numbering>';
    }

    /**
     * @return array<string, string>
     */
    private function styles(DOMElement $element): array
    {
        $styles = [];

        foreach (explode(';', $element->getAttribute('style')) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);

            if (is_string($property) && is_string($value) && trim($value) !== '') {
                $styles[strtolower(trim($property))] = trim($value);
            }
        }

        return $styles;
    }

    private function color(string $value): ?string
    {
        $value = strtolower(trim($value));

        if (preg_match('/\A#([0-9a-f]{3})\z/', $value, $matches) === 1) {
            return strtoupper(implode('', array_map(static fn (string $c): string => $c.$c, str_split($matches[1]))));
        }

        if (preg_match('/\A#([0-9a-f]{6})\z/', $value, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/\Argba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([0-9.]+)\s*)?\)\z/', $value, $matches) === 1) {
            if (isset($matches[4]) && (float) $matches[4] === 0.0) {
                return null;
            }

            return sprintf('%02X%02X%02X', min(255, (int) $matches[1]), min(255, (int) $matches[2]), min(255, (int) $matches[3]));
        }

        return self::NAMED_COLORS[$value] ?? null;
    }

    private function halfPoints(string $value): ?int
    {
        if (preg_match('/\A([0-9]*\.?[0-9]+)\s*(pt|px|em|rem)\z/i', trim($value), $matches) !== 1) {
            return null;
        }

        $points = match (strtolower($matches[2])) {
            'pt' => (float) $matches[1],
            'px' => (float) $matches[1] * 0.75,
            default => (float) $matches[1] * 11,
        };

        return max(8, min(144, (int) round($points * 2)));
    }

    private function twips(string $value): ?int
    {
        if (preg_match('/\A(-?[0-9]*\.?[0-9]+)\s*(pt|px|em|rem|cm|mm|in)?\z/i', trim($value), $matches) !== 1) {
            return null;
        }

        $number = (float) $matches[1];
        $twips = match (strtolower($matches[2] ?? 'px')) {
            'pt' => $number * 20,
            'em', 'rem' => $number * 240,
            'cm' => $number * 567,
            'mm' => $number * 56.7,
            'in' => $number * 1440,
            default => $number * 15,
        };

        return max(-5670, min(11340, (int) round($twips)));
    }

    private function pixels(string $value): ?int
    {
        if (preg_match('/\A([0-9]*\.?[0-9]+)\s*(px)?\z/i', trim($value), $matches) !== 1) {
            return null;
        }

        $pixels = (int) round((float) $matches[1]);

        return $pixels > 0 ? min(4000, $pixels) : null;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
