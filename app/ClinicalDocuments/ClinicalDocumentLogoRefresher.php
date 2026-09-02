<?php

namespace App\ClinicalDocuments;

use App\Models\Document;
use App\Services\DocumentBrandingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Re-embeds the current clinic logo into an already generated Word document.
 *
 * A document keeps the wording the doctor typed in ONLYOFFICE — only the two
 * generated logo drawings (the letterhead image and the behind-text
 * watermark) are refreshed, so changing the logo in Configuration reaches
 * every sheet printed from then on.
 */
final class ClinicalDocumentLogoRefresher
{
    /** @var list<string> */
    private const MEDIA_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    private const LETTERHEAD_NAME = 'Clinic logo';

    private const WATERMARK_NAME = 'Clinic logo watermark';

    /** @var array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}|null|false */
    private array|null|false $currentLogo = false;

    public function __construct(
        private readonly DocxDocumentBuilder $builder,
        private readonly DocumentBrandingService $branding,
    ) {}

    /**
     * Bring the stored .docx up to date with the configured logo. Returns the
     * document untouched when it already carries that logo, so opening a
     * consultation does not rewrite files for nothing.
     */
    public function refresh(Document $document): Document
    {
        try {
            return $this->rewrite($document);
        } catch (Throwable $exception) {
            // A document that cannot be re-branded must still open and print.
            Log::warning('The clinical document logo could not be refreshed.', [
                'document_id' => $document->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return $document;
        }
    }

    private function rewrite(Document $document): Document
    {
        $path = $document->file_path;
        $logo = $this->currentLogo();

        if ($path === null || $logo === null || ! Storage::exists($path)) {
            return $document;
        }

        $zip = new ZipArchive;

        if ($zip->open(Storage::path($path)) !== true) {
            return $document;
        }

        $existing = $this->existingMediaName($zip);
        $target = 'word/media/'.$logo['filename'];

        // Documents built without a logo part, or whose media ONLYOFFICE has
        // renamed, are left exactly as they are.
        if ($existing === null) {
            $zip->close();

            return $document;
        }

        $stat = $zip->statName($existing);

        if ($existing === $target && is_array($stat) && ($stat['crc'] ?? null) === crc32($logo['bytes'])) {
            $zip->close();

            return $document;
        }

        if ($existing !== $target) {
            $zip->deleteName($existing);
        }

        $zip->addFromString($target, $logo['bytes']);
        $this->retarget($zip, 'word/_rels/document.xml.rels', $existing, $target);
        $this->retarget($zip, 'word/_rels/header1.xml.rels', $existing, $target);
        $this->declareContentType($zip, $logo);
        $this->resize($zip, 'word/document.xml', self::LETTERHEAD_NAME, $logo['width_emu'], $logo['height_emu']);
        [$watermarkWidth, $watermarkHeight] = $this->builder->watermarkExtent($logo);
        $this->resize($zip, 'word/header1.xml', self::WATERMARK_NAME, $watermarkWidth, $watermarkHeight);
        $zip->close();

        // ONLYOFFICE caches by a document key carrying the version, so the
        // bump is what makes the editor fetch the re-branded file.
        $document->update([
            'file_size' => Storage::size($path),
            'file_version' => $document->file_version + 1,
        ]);

        return $document->refresh();
    }

    private function existingMediaName(ZipArchive $zip): ?string
    {
        foreach (self::MEDIA_EXTENSIONS as $extension) {
            $name = 'word/media/clinic-logo.'.$extension;

            if ($zip->locateName($name) !== false) {
                return $name;
            }
        }

        return null;
    }

    private function retarget(ZipArchive $zip, string $part, string $from, string $to): void
    {
        $xml = $zip->getFromName($part);

        if (! is_string($xml) || $from === $to) {
            return;
        }

        $zip->addFromString($part, str_replace(
            'Target="'.str_replace('word/', '', $from).'"',
            'Target="'.str_replace('word/', '', $to).'"',
            $xml,
        ));
    }

    /**
     * @param  array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}  $logo
     */
    private function declareContentType(ZipArchive $zip, array $logo): void
    {
        $xml = $zip->getFromName('[Content_Types].xml');

        if (! is_string($xml) || str_contains($xml, 'Extension="'.$logo['extension'].'"')) {
            return;
        }

        // The previous extension keeps its Default: an unused declaration is
        // valid, and dropping it risks orphaning a part ONLYOFFICE added.
        $updated = preg_replace(
            '/(<Types\b[^>]*>)/u',
            '$1<Default Extension="'.$logo['extension'].'" ContentType="'.$logo['content_type'].'"/>',
            $xml,
            1,
        );

        if (is_string($updated)) {
            $zip->addFromString('[Content_Types].xml', $updated);
        }
    }

    private function resize(ZipArchive $zip, string $part, string $name, int $width, int $height): void
    {
        $xml = $zip->getFromName($part);

        if (! is_string($xml)) {
            return;
        }

        $updated = preg_replace_callback(
            '/<w:drawing>.*?<\/w:drawing>/su',
            static function (array $match) use ($name, $width, $height): string {
                // Anything the doctor inserted themselves carries a different
                // name and keeps the size they gave it.
                if (! str_contains($match[0], 'name="'.$name.'"')) {
                    return $match[0];
                }

                $drawing = preg_replace(
                    '/<wp:extent\s+cx="\d+"\s+cy="\d+"\s*\/>/u',
                    '<wp:extent cx="'.$width.'" cy="'.$height.'"/>',
                    $match[0],
                );

                return (string) preg_replace(
                    '/<a:ext\s+cx="\d+"\s+cy="\d+"\s*\/>/u',
                    '<a:ext cx="'.$width.'" cy="'.$height.'"/>',
                    is_string($drawing) ? $drawing : $match[0],
                );
            },
            $xml,
        );

        if (is_string($updated) && $updated !== $xml) {
            $zip->addFromString($part, $updated);
        }
    }

    /**
     * @return array{filename: string, bytes: string, extension: string, content_type: string, width_emu: int, height_emu: int}|null
     */
    private function currentLogo(): ?array
    {
        if ($this->currentLogo === false) {
            $this->currentLogo = $this->builder->resolveLogo(
                $this->branding->identity()['logo_path'],
            );
        }

        return $this->currentLogo;
    }
}
