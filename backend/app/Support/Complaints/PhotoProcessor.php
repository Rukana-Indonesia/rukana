<?php

namespace App\Support\Complaints;

use GdImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Kompresi foto laporan di server: sisi terpanjang maks. 1600px, JPEG kualitas 80.
 * Gambar disusun ulang dari piksel, jadi metadata EXIF (termasuk koordinat GPS
 * dan model HP) tidak ikut tersimpan.
 */
class PhotoProcessor
{
    public const MAX_SIDE = 1600;

    private const QUALITY = 80;

    /**
     * @return array{contents: string, mime: string, width: int, height: int}
     *
     * @throws RuntimeException bila berkas tidak bisa dibaca sebagai gambar
     */
    public function process(UploadedFile $file): array
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Foto tidak bisa dibaca.');
        }

        $image = $this->orient($image, $file);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // PNG transparan diberi latar putih supaya tidak menjadi hitam di JPEG.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $contents = (string) ob_get_clean();

        return ['contents' => $contents, 'mime' => 'image/jpeg', 'width' => imagesx($canvas), 'height' => imagesy($canvas)];
    }

    /** Foto HP sering disimpan miring dengan tanda orientasi di EXIF; putar sesuai tanda itu. */
    private function orient(GdImage $image, UploadedFile $file): GdImage
    {
        if (! function_exists('exif_read_data') || $file->getMimeType() !== 'image/jpeg') {
            return $image;
        }

        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
