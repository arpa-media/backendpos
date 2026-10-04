<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Services\GeneralAffair\GeneralAffairMediaUrlService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class GeneralAffairMediaController extends Controller
{
    public function __construct(private readonly GeneralAffairMediaUrlService $media)
    {
    }

    public function __invoke(string $token): StreamedResponse
    {
        try {
            $target = $this->media->decode($token);
        } catch (\InvalidArgumentException) {
            abort(404, 'Media General Affair tidak ditemukan.');
        }

        try {
            $disk = Storage::disk($target['disk']);
            if (! $disk->exists($target['path'])) abort(404, 'Media General Affair tidak ditemukan.');

            $mime = '';
            try { $mime = strtolower((string) ($disk->mimeType($target['path']) ?: '')); } catch (Throwable) {}
            $mime = $this->safeImageMime($mime, $target['path']);
            if ($mime === null) abort(415, 'Tipe media General Affair tidak didukung.');

            $size = null;
            try { $size = $disk->size($target['path']); } catch (Throwable) {}
            $stream = $disk->readStream($target['path']);
            if (! is_resource($stream)) abort(404, 'Media General Affair tidak dapat dibaca.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            abort(404, 'Media General Affair tidak dapat dibaca.');
        }

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=300, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (is_int($size) && $size >= 0) $headers['Content-Length'] = (string) $size;

        return response()->stream(function () use ($stream): void {
            try { fpassthru($stream); }
            finally { if (is_resource($stream)) fclose($stream); }
        }, 200, $headers);
    }

    private function safeImageMime(string $detected, string $path): ?string
    {
        if (in_array($detected, ['image/jpeg', 'image/png', 'image/webp'], true)) return $detected;

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
