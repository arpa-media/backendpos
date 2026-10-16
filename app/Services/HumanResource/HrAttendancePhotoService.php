<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class HrAttendancePhotoService
{
    private const MAX_INPUT_BYTES = 6_000_000;
    private const MAX_FALLBACK_BYTES = 1_500_000;
    private const MAX_DIMENSION = 960;
    private const JPEG_QUALITY = 62;
    private const MAX_FAST_JPEG_BYTES = 350_000;
    private const MAX_PIXELS = 16_000_000;

    public function storeBase64(?string $dataUri, string $prefix): ?string
    {
        $dataUri = trim((string) $dataUri);
        if ($dataUri === '') {
            return null;
        }

        if (! preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/i', $dataUri, $matches)) {
            throw new RuntimeException('Format foto absensi tidak valid.');
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Data foto absensi tidak dapat dibaca.');
        }
        if (strlen($binary) > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('Foto absensi terlalu besar. Maksimum input 6 MB sebelum kompresi.');
        }

        $info = @getimagesizefromstring($binary);
        if (! is_array($info) || empty($info[0]) || empty($info[1])) {
            throw new RuntimeException('File foto absensi bukan gambar yang valid.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width > self::MAX_PIXELS / $height) {
            throw new RuntimeException('Resolusi foto absensi terlalu besar untuk diproses.');
        }

        // Match decoded content type against the data URI, not only its label.
        $sourceType = strtolower((string) ($matches[1] ?? 'jpeg'));
        $expectedMime = match ($sourceType) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        if (strtolower((string) ($info['mime'] ?? '')) !== $expectedMime) {
            throw new RuntimeException('Jenis file foto absensi tidak sesuai dengan kontennya.');
        }

        $basePath = 'attendance_photos/'.$prefix.'_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(10));

        // Browser canvas.toBlob() emits already-compressed, metadata-free JPEG.
        // Keep small, correctly framed JPEGs as-is; avoid decoding and re-encoding
        // each photo in GD on the synchronous check-in/check-out request.
        if ($expectedMime === 'image/jpeg'
            && strlen($binary) <= self::MAX_FAST_JPEG_BYTES
            && max($width, $height) <= self::MAX_DIMENSION
            && str_starts_with($binary, "\xFF\xD8\xFF")
            && str_ends_with($binary, "\xFF\xD9")
            && ! str_contains($binary, "Exif\x00\x00")
        ) {
            $path = $basePath.'.jpg';
            $this->putOrFail($path, $binary);
            return $path;
        }

        $compressed = $this->compressWithGd($binary, $width, $height);

        if ($compressed !== null) {
            $path = $basePath.'.jpg';
            $this->putOrFail($path, $compressed);
            return $path;
        }

        if (strlen($binary) > self::MAX_FALLBACK_BYTES) {
            throw new RuntimeException('Server tidak memiliki GD untuk kompresi dan ukuran foto masih di atas 1.5 MB.');
        }

        // Browser Iterasi 02 sends JPEG after client compression, but the API also
        // accepts validated PNG/WebP. Preserve the real extension when GD is absent.
        $extension = match ($sourceType) {
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };
        $path = $basePath.'.'.$extension;
        $this->putOrFail($path, $binary);
        return $path;
    }

    private function putOrFail(string $path, string $contents): void
    {
        if (Storage::disk('public')->put($path, $contents) === false) {
            throw new RuntimeException('Gagal menyimpan foto absensi ke storage.');
        }
    }

    private function compressWithGd(string $binary, int $width, int $height): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if (! $source) {
            return null;
        }

        $ratio = min(1, self::MAX_DIMENSION / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, self::JPEG_QUALITY);
        $result = ob_get_clean();

        imagedestroy($target);
        imagedestroy($source);

        return is_string($result) && $result !== '' ? $result : null;
    }
}
