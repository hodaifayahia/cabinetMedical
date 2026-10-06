<?php

namespace App\ClinicalDocuments;

use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

final class DocxDocumentBuilder
{
    /**
     * @param  array<string, string>  $variables
     */
    public function build(
        string $absolutePath,
        string $title,
        string $body,
        array $variables,
        string $paperSize,
        string $bodyFormat = 'text',
    ): int {
        $zip = new ZipArchive;
        $result = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException('The Word document could not be created.');
        }

        $logo = $this->resolveLogo($variables['cabinet.logo_path'] ?? null);
        $documentXml = $this->documentXml(
            $title,
            $bodyFormat === 'html' ? $body : $this->replaceVariables($body, $variables),
            $variables,
            $paperSize,
            $logo,
            $bodyFormat,
        );

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml($logo));
        $zip->addFromString('_rels/.rels', $this->packageRelationshipsXml());
        $zip->addFromString('docProps/core.xml', $this->corePropertiesXml($title, $variables));
        $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/styles.xml', $this->stylesXml());
        $zip->addFromString('word/footer1.xml', $this->footerXml($variables));
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRelationshipsXml($logo));

        if ($logo !== null) {
            $zip->addFromString('word/media/'.$logo['filename'], $logo['bytes']);
            $zip->addFromString('word/header1.xml', $this->watermarkHeaderXml($logo));
            $zip->addFromString('word/_rels/header1.xml.rels', $this->headerRelationshipsXml($logo));
        }

        $zip->close();

        $size = filesize($absolutePath);

        if ($size === false) {
            throw new RuntimeException('The generated Word document size could not be read.');
        }

        return $size;
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}|null  $logo
     */
    private function documentXml(
        string $title,
        string $body,
        array $variables,
        string $paperSize,
        ?array $logo,
        string $bodyFormat,
    ): string {
        [$width, $height, $margin] = strtoupper($paperSize) === 'A5'
            ? [8391, 11906, 720]
            : [11906, 16838, 1000];

        $doctor = trim($variables['doctor.name'] ?? '');
        $specialty = $variables['doctor.specialty'] ?? '';
        $orderNumber = $variables['doctor.order_number'] ?? '';
        $clinic = $variables['cabinet.name'] ?? '';
        $patient = $variables['patient.full_name'] ?? '';
        $date = $variables['document.date'] ?? '';

        $paragraphs = [];

        if ($logo !== null) {
            $paragraphs[] = $this->imageParagraph($logo);
        }

        foreach (array_filter([
            $clinic !== '' ? $this->paragraph($clinic, bold: true, centered: true, fontSize: 26) : null,
            $doctor !== '' ? $this->paragraph($doctor, bold: true, fontSize: 24) : null,
            $specialty !== '' ? $this->paragraph($specialty, fontSize: 20) : null,
            $orderNumber !== '' ? $this->paragraph('N° d’ordre : '.$orderNumber, fontSize: 18) : null,
        ]) as $paragraph) {
            $paragraphs[] = $paragraph;
        }

        array_push(
            $paragraphs,
            $this->paragraph('Patient : '.$patient.'    Date : '.$date, bold: true, borderBottom: true),
            $this->paragraph(''),
            $this->paragraph(mb_strtoupper($title), bold: true, centered: true, fontSize: 30),
            $this->paragraph(''),
        );

        if ($bodyFormat === 'html') {
            array_push($paragraphs, ...$this->richParagraphs($body, $variables));
        } else {
            foreach (preg_split('/\R/u', $body) ?: [] as $line) {
                $heading = str_starts_with($line, '## ');
                $text = $heading ? substr($line, 3) : $line;
                $paragraphs[] = $this->paragraph($text, bold: $heading, fontSize: $heading ? 23 : 22);
            }
        }

        $bodyXml = implode('', $paragraphs);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<w:body>'.$bodyXml
            .'<w:sectPr>'
            .($logo !== null ? '<w:headerReference w:type="default" r:id="rId4"/>' : '')
            .'<w:footerReference w:type="default" r:id="rId2"/>'
            .'<w:pgSz w:w="'.$width.'" w:h="'.$height.'"/>'
            .'<w:pgMar w:top="'.$margin.'" w:right="'.$margin.'" w:bottom="'.$margin.'" w:left="'.$margin.'" w:header="360" w:footer="360" w:gutter="0"/>'
            .'<w:cols w:space="708"/><w:docGrid w:linePitch="360"/>'
            .'</w:sectPr></w:body></w:document>';
    }

    private function paragraph(
        string $text,
        bool $bold = false,
        bool $centered = false,
        int $fontSize = 22,
        bool $borderBottom = false,
    ): string {
        $paragraphProperties = '';

        if ($centered || $borderBottom) {
            $paragraphProperties = '<w:pPr>'
                .($centered ? '<w:jc w:val="center"/>' : '')
                .($borderBottom ? '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="6" w:color="333333"/></w:pBdr>' : '')
                .'</w:pPr>';
        }

        if ($text === '') {
            return '<w:p>'.$paragraphProperties.'</w:p>';
        }

        $runProperties = '<w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Arial" w:cs="Arial"/>'
            .($bold ? '<w:b/><w:bCs/>' : '')
            .'<w:sz w:val="'.$fontSize.'"/><w:szCs w:val="'.$fontSize.'"/></w:rPr>';

        return '<w:p>'.$paragraphProperties.'<w:r>'.$runProperties
            .'<w:t xml:space="preserve">'.$this->xml($text).'</w:t></w:r></w:p>';
    }

    /** @param array<string, string> $variables
     *  @return list<string>
     */
    private function richParagraphs(string $html, array $variables): array
    {
        $body = app(DocumentTemplateContent::class)->body($html);
        $paragraphs = [];

        foreach ($body->childNodes as $node) {
            if ($node instanceof DOMElement && in_array($node->tagName, ['ul', 'ol'], true)) {
                $index = 1;

                foreach ($node->childNodes as $item) {
                    if ($item instanceof DOMElement && $item->tagName === 'li') {
                        $prefix = $node->tagName === 'ol' ? $index++.'. ' : '• ';
                        $paragraphs[] = $this->richParagraph($item, $variables, 'li', $prefix);
                    }
                }
            } elseif ($node instanceof DOMElement && in_array($node->tagName, ['p', 'h2', 'h3', 'li'], true)) {
                $paragraphs[] = $this->richParagraph($node, $variables, $node->tagName);
            } elseif (trim($node->textContent ?? '') !== '') {
                $paragraphs[] = '<w:p>'.$this->richRuns($node, $variables).'</w:p>';
            }
        }

        return $paragraphs;
    }

    /** @param array<string, string> $variables */
    private function richParagraph(DOMElement $node, array $variables, string $tag, string $prefix = ''): string
    {
        $heading = in_array($tag, ['h2', 'h3'], true);
        $size = $tag === 'h2' ? 26 : ($tag === 'h3' ? 23 : 22);
        $properties = $tag === 'li'
            ? '<w:pPr><w:ind w:left="540" w:hanging="260"/></w:pPr>'
            : ($heading ? '<w:pPr><w:spacing w:before="180" w:after="100"/></w:pPr>' : '');
        $content = $prefix !== '' ? $this->richTextRun($prefix, $variables, false, false, false, $size) : '';

        foreach ($node->childNodes as $child) {
            $content .= $this->richRuns($child, $variables, $heading, false, false, $size);
        }

        return '<w:p>'.$properties.$content.'</w:p>';
    }

    /** @param array<string, string> $variables */
    private function richRuns(
        DOMNode $node,
        array $variables,
        bool $bold = false,
        bool $italic = false,
        bool $underline = false,
        int $size = 22,
    ): string {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $this->richTextRun($node->nodeValue ?? '', $variables, $bold, $italic, $underline, $size);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        if ($node->tagName === 'br') {
            return '<w:r><w:br/></w:r>';
        }

        $bold = $bold || in_array($node->tagName, ['strong', 'b'], true);
        $italic = $italic || in_array($node->tagName, ['em', 'i'], true);
        $underline = $underline || $node->tagName === 'u';
        $xml = '';

        foreach ($node->childNodes as $child) {
            $xml .= $this->richRuns($child, $variables, $bold, $italic, $underline, $size);
        }

        return $xml;
    }

    /** @param array<string, string> $variables */
    private function richTextRun(
        string $text,
        array $variables,
        bool $bold,
        bool $italic,
        bool $underline,
        int $size,
    ): string {
        $text = $this->replaceVariables($text, $variables);

        if ($text === '') {
            return '';
        }

        $properties = '<w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/>'
            .($bold ? '<w:b/>' : '')
            .($italic ? '<w:i/>' : '')
            .($underline ? '<w:u w:val="single"/>' : '')
            .'<w:sz w:val="'.$size.'"/></w:rPr>';
        $parts = preg_split('/\R/u', $text) ?: [$text];
        $xml = '';

        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $xml .= '<w:r><w:br/></w:r>';
            }

            if ($part !== '') {
                $xml .= '<w:r>'.$properties.'<w:t xml:space="preserve">'.$this->xml($part).'</w:t></w:r>';
            }
        }

        return $xml;
    }

    /**
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}  $logo
     */
    private function imageParagraph(array $logo): string
    {
        $width = $logo['width_emu'];
        $height = $logo['height_emu'];

        return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:drawing>'
            .'<wp:inline distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$width.'" cy="'.$height.'"/>'
            .'<wp:docPr id="1" name="Clinic logo"/>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Clinic logo"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="rId3"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /**
     * Resolve the logo a document should carry: the cabinet's uploaded file
     * when it is readable, otherwise the packaged product mark. Public so the
     * refresher re-embeds a replaced logo at exactly the same scale the
     * original build would have produced.
     *
     * @return array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}|null
     */
    public function resolveLogo(?string $logoPath): ?array
    {
        $path = trim((string) $logoPath);
        $bytes = null;

        if ($path !== '') {
            try {
                $disk = Storage::disk('public');
                $bytes = $disk->exists($path) ? $disk->get($path) : null;
            } catch (Throwable) {
                $bytes = null;
            }
        }

        if (! is_string($bytes) || $bytes === '') {
            $fallback = @file_get_contents(public_path('brand/drclick-mark.png'));
            $bytes = is_string($fallback) ? $fallback : null;
        }

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            return null;
        }

        $image = @getimagesizefromstring($bytes);

        if (! is_array($image)) {
            return null;
        }

        $format = match ($image['mime']) {
            'image/jpeg' => ['jpg', 'image/jpeg'],
            'image/png' => ['png', 'image/png'],
            'image/webp' => ['webp', 'image/webp'],
            default => null,
        };

        if ($format === null) {
            return null;
        }

        $sourceWidth = max(1, (int) $image[0]);
        $sourceHeight = max(1, (int) $image[1]);
        $maximumWidth = 1_371_600;
        $maximumHeight = 685_800;
        $scale = min(
            $maximumWidth / ($sourceWidth * 9_525),
            $maximumHeight / ($sourceHeight * 9_525),
        );
        $width = max(1, (int) round($sourceWidth * 9_525 * $scale));
        $height = max(1, (int) round($sourceHeight * 9_525 * $scale));

        return [
            'filename' => 'clinic-logo.'.$format[0],
            'bytes' => $bytes,
            'extension' => $format[0],
            'content_type' => $format[1],
            'width_emu' => $width,
            'height_emu' => $height,
        ];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function replaceVariables(string $body, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{([a-z0-9_.]+)\}\}/i',
            static fn (array $match): string => array_key_exists($match[1], $variables)
                ? $variables[$match[1]]
                : $match[0],
            $body,
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function footerXml(array $variables): string
    {
        $parts = array_filter([
            $variables['cabinet.name'] ?? null,
            $variables['cabinet.footer'] ?? null,
        ]);

        if (! filled($variables['cabinet.footer'] ?? null)) {
            $parts = array_filter([
                $variables['cabinet.name'] ?? null,
                $variables['cabinet.phone'] ?? null,
                $variables['cabinet.email'] ?? null,
                $variables['cabinet.address'] ?? null,
            ]);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:p><w:pPr><w:jc w:val="center"/><w:pBdr><w:top w:val="dashed" w:sz="4" w:space="4" w:color="999999"/></w:pBdr></w:pPr>'
            .'<w:r><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Arial" w:cs="Arial"/><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="555555"/></w:rPr>'
            .'<w:t xml:space="preserve">'.$this->xml(implode(' · ', $parts)).'</w:t></w:r></w:p></w:ftr>';
    }

    /**
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}|null  $logo
     */
    private function contentTypesXml(?array $logo): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .($logo !== null
                ? '<Default Extension="'.$logo['extension'].'" ContentType="'.$logo['content_type'].'"/>'
                : '')
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            .($logo !== null
                ? '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
                : '')
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'</Types>';
    }

    private function packageRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}|null  $logo
     */
    private function documentRelationshipsXml(?array $logo): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
            .($logo !== null
                ? '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$logo['filename'].'"/>'
                : '')
            .($logo !== null
                ? '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
                : '')
            .'</Relationships>';
    }

    /**
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}  $logo
     */
    private function watermarkHeaderXml(array $logo): string
    {
        [$width, $height] = $this->watermarkExtent($logo);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<w:p><w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="0" behindDoc="1" locked="0" layoutInCell="1" allowOverlap="1">'
            .'<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="page"><wp:align>center</wp:align></wp:positionH>'
            .'<wp:positionV relativeFrom="page"><wp:align>center</wp:align></wp:positionV>'
            .'<wp:extent cx="'.$width.'" cy="'.$height.'"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>'
            .'<wp:docPr id="2" name="Clinic logo watermark"/><wp:cNvGraphicFramePr/>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Clinic logo watermark"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="rId1"><a:alphaModFix amt="6000"/></a:blip><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            .'</a:graphicData></a:graphic></wp:anchor></w:drawing></w:r></w:p></w:hdr>';
    }

    /**
     * The behind-text watermark is scaled to fit a 3.4M EMU square, keeping
     * the logo's aspect ratio.
     *
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}  $logo
     * @return array{int, int}
     */
    public function watermarkExtent(array $logo): array
    {
        $scale = min(
            3_400_000 / max(1, $logo['width_emu']),
            3_400_000 / max(1, $logo['height_emu']),
        );

        return [
            max(1, (int) round($logo['width_emu'] * $scale)),
            max(1, (int) round($logo['height_emu'] * $scale)),
        ];
    }

    /**
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}  $logo
     */
    private function headerRelationshipsXml(array $logo): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$logo['filename'].'"/>'
            .'</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Arial" w:cs="Arial"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="300" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            .'</w:styles>';
    }

    /** @param array<string, string> $variables */
    private function corePropertiesXml(string $title, array $variables): string
    {
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $creator = trim($variables['doctor.name'] ?? '')
            ?: trim($variables['cabinet.name'] ?? '')
            ?: 'Drclick';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$this->xml($title).'</dc:title><dc:creator>'.$this->xml($creator).'</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function appPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Drclick</Application></Properties>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
