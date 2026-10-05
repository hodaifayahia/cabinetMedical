<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Photos of a clinic's public listing (`cabinet_public_profiles.photos`).
 *
 * Uploaded photos are stored on the public disk under
 * `cabinet-photos/{cabinet}/…` and kept in the column as that relative path;
 * listings written before uploads existed may still hold a full web link.
 * Every API response goes through url(), so the app always gets a link it
 * can load, whichever kind was stored.
 *
 * An upload is re-encoded here: shrunk to at most MAX_SIDE pixels, turned
 * the right way up, saved as JPEG. Re-encoding also drops the EXIF block, so
 * the phone's GPS position never reaches the public listing.
 */
final class ClinicPhotos
{
    public const MAX_PHOTOS = 6;

    public const MAX_SIDE = 1600;

    public const JPEG_QUALITY = 82;

    private const DIRECTORY = 'cabinet-photos';

    /** The public link of a stored photo (an upload path or a legacy link). */
    public static function url(string $stored): string
    {
        return self::isLink($stored) ? $stored : Storage::disk('public')->url($stored);
    }

    /**
     * @param  array<int, string>|null  $stored
     * @return list<string>
     */
    public static function urls(?array $stored): array
    {
        return array_values(array_map(self::url(...), array_filter(
            $stored ?? [],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));
    }

    public static function isLink(string $stored): bool
    {
        return preg_match('#^https?://#i', $stored) === 1;
    }

    /** True for a path this class stored (and may therefore delete). */
    public static function isUpload(string $stored): bool
    {
        return ! self::isLink($stored) && str_starts_with($stored, self::DIRECTORY.'/');
    }

    /** Re-encode an uploaded image and store it; returns the path to keep in `photos`. */
    public static function store(UploadedFile $file, int $cabinetId): string
    {
        $bytes = self::reencode((string) file_get_contents($file->getRealPath()));
        $path = self::DIRECTORY.'/'.$cabinetId.'/'.Str::uuid()->toString().'.jpg';

        if (! Storage::disk('public')->put($path, $bytes)) {
            throw new RuntimeException('The clinic photo could not be stored.');
        }

        return $path;
    }

    /**
     * Delete the uploaded files among `$stored` (legacy links are left alone).
     *
     * @param  array<int, string>  $stored
     */
    public static function delete(array $stored): void
    {
        $paths = array_values(array_filter($stored, self::isUpload(...)));

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }

    private static function reencode(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('Unreadable image.');
        }

        $image = self::upright($image, $bytes);

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_SIDE / max($width, $height));

        if ($scale < 1) {
            $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            if ($resized !== false) {
                $image = $resized;
            }
        }

        // PNG/WebP transparency becomes white, not black, in the JPEG.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }

    /** Applies the EXIF orientation of a phone JPEG (GD ignores it). */
    private static function upright(\GdImage $image, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }
}
