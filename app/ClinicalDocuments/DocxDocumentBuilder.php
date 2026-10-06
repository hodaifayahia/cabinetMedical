<?php

namespace App\ClinicalDocuments;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

final class DocxDocumentBuilder
{
    /**
     * Build the Word file of a consultation document: letterhead, patient line,
     * title, then the template body with its {{variables}} substituted.
     *
     * @param  array<string, string>  $variables
     */
    public function build(
        string $absolutePath,
        string $title,
        string $body,
        array $variables,
        string $paperSize,
        string $bodyFormat = TemplateBody::FORMAT_TEXT,
    ): int {
        $logo = $this->resolveLogo($variables['cabinet.logo_path'] ?? null);
        $content = $this->bodyContent($body, $bodyFormat, $variables, $paperSize);
        $documentXml = $this->documentXml(
            $title,
            $content['xml'],
            $variables,
            $paperSize,
            $logo,
        );

        return $this->writePackage($absolutePath, $title, $documentXml, $variables, $logo, $content, true);
    }

    /**
     * Build a bare Word file of a template (no letterhead, no footer), with
     * its {{variables}} left in place so it can be edited in Word and
     * imported back into the template editor.
     */
    public function buildTemplate(
        string $absolutePath,
        string $title,
        string $body,
        string $bodyFormat,
        string $paperSize,
    ): int {
        $content = $this->bodyContent($body, $bodyFormat, [], $paperSize);
        [$width, $height, $margin] = $this->pageGeometry($paperSize);
        $documentXml = $this->wrapDocument($content['xml'], '', $width, $height, $margin, false);

        return $this->writePackage($absolutePath, $title, $documentXml, [], null, $content, false);
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array{xml: string, images: list<array{id: string, filename: string, bytes: string, extension: string, content_type: string}>, numbering: string|null}  $content
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}|null  $logo
     */
    private function writePackage(
        string $absolutePath,
        string $title,
        string $documentXml,
        array $variables,
        ?array $logo,
        array $content,
        bool $withFooter,
    ): int {
        $zip = new ZipArchive;
        $result = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException('The Word document could not be created.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml($logo, $content, $withFooter));
        $zip->addFromString('_rels/.rels', $this->packageRelationshipsXml());
        $zip->addFromString('docProps/core.xml', $this->corePropertiesXml($title, $variables));
        $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/styles.xml', $this->stylesXml());

        if ($withFooter) {
            $zip->addFromString('word/footer1.xml', $this->footerXml($variables));
        }

        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRelationshipsXml($logo, $content, $withFooter));

        if ($logo !== null) {
            $zip->addFromString('word/media/'.$logo['filename'], $logo['bytes']);
            $zip->addFromString('word/header1.xml', $this->watermarkHeaderXml($logo));
            $zip->addFromString('word/_rels/header1.xml.rels', $this->headerRelationshipsXml($logo));
        }

        foreach ($content['images'] as $image) {
            $zip->addFromString('word/media/'.$image['filename'], $image['bytes']);
        }

        if ($content['numbering'] !== null) {
            $zip->addFromString('word/numbering.xml', $content['numbering']);
        }

        $zip->close();

        $size = filesize($absolutePath);

        if ($size === false) {
            throw new RuntimeException('The generated Word document size could not be read.');
        }

        return $size;
    }

    /**
     * The body's WordprocessingML: legacy line-based text, or rich HTML from
     * the document editor (variables substituted with escaped values).
     *
     * @param  array<string, string>  $variables
     * @return array{xml: string, images: list<array{id: string, filename: string, bytes: string, extension: string, content_type: string}>, numbering: string|null}
     */
    private function bodyContent(string $body, string $bodyFormat, array $variables, string $paperSize): array
    {
        if (TemplateBody::normalizeFormat($bodyFormat) === TemplateBody::FORMAT_HTML) {
            [$width, , $margin] = $this->pageGeometry($paperSize);

            return (new HtmlToWordprocessingMl)->convert(
                TemplateBody::renderHtml($body, $variables),
                $width - 2 * $margin,
            );
        }

        $paragraphs = '';

        foreach (preg_split('/\R/u', $this->replaceVariables($body, $variables)) ?: [] as $line) {
            $heading = str_starts_with($line, '## ');
            $text = $heading ? substr($line, 3) : $line;
            $paragraphs .= $this->paragraph($text, bold: $heading, fontSize: $heading ? 23 : 22);
        }

        return ['xml' => $paragraphs, 'images' => [], 'numbering' => null];
    }

    /**
     * @return array{int, int, int} width, height and margin in twips
     */
    private function pageGeometry(string $paperSize): array
    {
        return strtoupper($paperSize) === 'A5'
            ? [8391, 11906, 720]
            : [11906, 16838, 1000];
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}|null  $logo
     */
    private function documentXml(
        string $title,
        string $bodyXml,
        array $variables,
        string $paperSize,
        ?array $logo,
    ): string {
        [$width, $height, $margin] = $this->pageGeometry($paperSize);

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

        $headerXml = $logo !== null ? '<w:headerReference w:type="default" r:id="rId4"/>' : '';

        return $this->wrapDocument(implode('', $paragraphs).$bodyXml, $headerXml, $width, $height, $margin, true);
    }

    private function wrapDocument(
        string $bodyXml,
        string $headerXml,
        int $width,
        int $height,
        int $margin,
        bool $withFooter,
    ): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<w:body>'.$bodyXml
            .'<w:sectPr>'
            .$headerXml
            .($withFooter ? '<w:footerReference w:type="default" r:id="rId2"/>' : '')
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
     * @param  array{xml: string, images: list<array{id: string, filename: string, bytes: string, extension: string, content_type: string}>, numbering: string|null}  $content
     */
    private function contentTypesXml(?array $logo, array $content, bool $withFooter): string
    {
        $defaults = [];

        if ($logo !== null) {
            $defaults[$logo['extension']] = $logo['content_type'];
        }

        foreach ($content['images'] as $image) {
            $defaults[$image['extension']] ??= $image['content_type'];
        }

        $defaultXml = '';

        foreach ($defaults as $extension => $type) {
            $defaultXml .= '<Default Extension="'.$extension.'" ContentType="'.$type.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .$defaultXml
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .($withFooter
                ? '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
                : '')
            .($logo !== null
                ? '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
                : '')
            .($content['numbering'] !== null
                ? '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
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
     * @param  array{xml: string, images: list<array{id: string, filename: string, bytes: string, extension: string, content_type: string}>, numbering: string|null}  $content
     */
    private function documentRelationshipsXml(?array $logo, array $content, bool $withFooter): string
    {
        $images = '';

        foreach ($content['images'] as $image) {
            $images .= '<Relationship Id="'.$image['id'].'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$image['filename'].'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .($withFooter
                ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
                : '')
            .($logo !== null
                ? '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$logo['filename'].'"/>'
                : '')
            .($logo !== null
                ? '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
                : '')
            .($content['numbering'] !== null
                ? '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
                : '')
            .$images
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
            .$this->headingStyleXml(1, 32)
            .$this->headingStyleXml(2, 28)
            .$this->headingStyleXml(3, 26)
            .$this->headingStyleXml(4, 24)
            .$this->headingStyleXml(5, 22)
            .$this->headingStyleXml(6, 22)
            .'</w:styles>';
    }

    /**
     * "heading N" styles, so Word's navigation pane (and a re-import into the
     * template editor) recognise the headings written by the editor.
     */
    private function headingStyleXml(int $level, int $size): string
    {
        return '<w:style w:type="paragraph" w:styleId="Heading'.$level.'">'
            .'<w:name w:val="heading '.$level.'"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            .'<w:pPr><w:keepNext/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="'.($level - 1).'"/></w:pPr>'
            .'<w:rPr><w:b/><w:bCs/><w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/></w:rPr>'
            .'</w:style>';
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
