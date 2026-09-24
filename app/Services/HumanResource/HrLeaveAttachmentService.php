<?php

namespace App\Services\HumanResource;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class HrLeaveAttachmentService
{
    private const TARGET_IMAGE_BYTES = 350 * 1024;
    private const MAX_IMAGE_DIMENSION = 1280;

    public function store(?UploadedFile $file): array
    {
        if (! $file) {
            return [
                'path' => null, 'original_name' => null, 'mime' => null,
                'size' => null, 'compression' => null,
            ];
        }

        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        if (str_starts_with($mime, 'image/')) {
            return $this->storeImage($file, $mime);
        }
        if ($mime === 'application/pdf' || strtolower($file->getClientOriginalExtension()) === 'pdf') {
            return $this->storePdf($file);
        }

        throw new RuntimeException('Format attachment tidak didukung.');
    }

    public function delete(?string $path): void
    {
        if ($path) Storage::disk('public')->delete($path);
    }

    private function storeImage(UploadedFile $file, string $mime): array
    {
        $directory = 'hr/leave-attachments/'.now()->format('Y/m');
        $name = (string) Str::ulid().'.jpg';
        $path = $directory.'/'.$name;

        $info = @getimagesize($file->getRealPath());
        if (is_array($info) && isset($info[0], $info[1]) && ((int) $info[0] * (int) $info[1]) > 40_000_000) {
            $fallbackExt = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $fallbackPath = $directory.'/'.Str::ulid().'.'.$fallbackExt;
            Storage::disk('public')->putFileAs($directory, $file, basename($fallbackPath));
            return $this->result($fallbackPath, $file, $mime, (int) Storage::disk('public')->size($fallbackPath), 'original_pixel_guard');
        }

        $source = $this->imageResource($file->getRealPath(), $mime);
        if (! $source || ! function_exists('imagejpeg')) {
            $fallbackExt = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $fallbackPath = $directory.'/'.Str::ulid().'.'.$fallbackExt;
            Storage::disk('public')->putFileAs($directory, $file, basename($fallbackPath));
            return $this->result($fallbackPath, $file, $mime, (int) Storage::disk('public')->size($fallbackPath), 'original_no_gd');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_IMAGE_DIMENSION / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        if (! $canvas) {
            imagedestroy($source);
            throw new RuntimeException('Gagal menyiapkan kompresi image attachment.');
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        $binary = null;
        $qualityUsed = 60;
        foreach ([60, 52, 44, 36] as $quality) {
            ob_start();
            imagejpeg($canvas, null, $quality);
            $candidate = (string) ob_get_clean();
            $binary = $candidate;
            $qualityUsed = $quality;
            if (strlen($candidate) <= self::TARGET_IMAGE_BYTES) break;
        }
        imagedestroy($canvas);

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Gagal menghasilkan image attachment terkompresi.');
        }

        Storage::disk('public')->put($path, $binary);
        return $this->result($path, $file, 'image/jpeg', strlen($binary), 'jpeg_q'.$qualityUsed.'_max1280');
    }

    private function storePdf(UploadedFile $file): array
    {
        $directory = 'hr/leave-attachments/'.now()->format('Y/m');
        $path = $directory.'/'.Str::ulid().'.pdf';
        $originalBytes = (string) file_get_contents($file->getRealPath());
        $storedBytes = $originalBytes;
        $mode = 'pdf_original';

        $gs = $this->ghostscriptExecutable();
        if ($gs) {
            $tmpDir = storage_path('app/hr_leave_tmp');
            if (! is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
            $input = $tmpDir.'/'.Str::ulid().'-in.pdf';
            $output = $tmpDir.'/'.Str::ulid().'-out.pdf';
            file_put_contents($input, $originalBytes);
            $command = escapeshellarg($gs)
                .' -sDEVICE=pdfwrite -dSAFER -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook'
                .' -dNOPAUSE -dQUIET -dBATCH -dDetectDuplicateImages=true -dCompressFonts=true'
                .' -sOutputFile='.escapeshellarg($output).' '.escapeshellarg($input);
            @exec($command, $unused, $exitCode);
            if ($exitCode === 0 && is_file($output)) {
                $candidate = (string) file_get_contents($output);
                if ($candidate !== '' && strlen($candidate) < strlen($originalBytes)) {
                    $storedBytes = $candidate;
                    $mode = 'ghostscript_ebook';
                } else {
                    $mode = 'pdf_original_not_smaller';
                }
            } else {
                $mode = 'pdf_original_gs_failed';
            }
            @unlink($input);
            @unlink($output);
        } else {
            $mode = 'pdf_original_no_gs';
        }

        Storage::disk('public')->put($path, $storedBytes);
        return $this->result($path, $file, 'application/pdf', strlen($storedBytes), $mode);
    }

    private function imageResource(string $path, string $mime): mixed
    {
        if (! function_exists('imagecreatefromjpeg')) return null;
        try {
            return match ($mime) {
                'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
                'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null,
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function ghostscriptExecutable(): ?string
    {
        if (! function_exists('exec')) return null;
        foreach (PHP_OS_FAMILY === 'Windows' ? ['gswin64c.exe', 'gswin32c.exe', 'gs.exe'] : ['gs'] as $candidate) {
            $output = [];
            $code = 1;
            @exec((PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ').escapeshellarg($candidate).' 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $output, $code);
            if ($code === 0 && ! empty($output[0])) return trim((string) $output[0]);
        }
        return null;
    }

    private function result(string $path, UploadedFile $file, string $mime, int $size, string $compression): array
    {
        return [
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime' => $mime,
            'size' => $size,
            'compression' => $compression,
        ];
    }
}
