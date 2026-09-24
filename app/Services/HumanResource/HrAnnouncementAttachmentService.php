<?php

namespace App\Services\HumanResource;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class HrAnnouncementAttachmentService
{
    public const MAX_FILE_BYTES = 12 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 40 * 1024 * 1024;
    public const MAX_ATTACHMENTS = 10;

    private const ALLOWED_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv',
    ];

    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
        'text/plain', 'text/csv', 'application/csv',
    ];

    /** @param array<int,UploadedFile> $files */
    public function storeMany(string $announcementId, array $files, ?string $userId): array
    {
        $files = array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));
        if ($files === []) {
            return [];
        }

        $existing = DB::table('HR_announcement_attachments')
            ->where('announcement_id', $announcementId)
            ->whereNull('deleted_at')
            ->count();
        if ($existing + count($files) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'attachments' => ['Maksimal '.self::MAX_ATTACHMENTS.' attachment aktif per announcement.'],
            ]);
        }

        $total = array_sum(array_map(fn (UploadedFile $file) => max(0, (int) $file->getSize()), $files));
        if ($total > self::MAX_TOTAL_BYTES) {
            throw ValidationException::withMessages([
                'attachments' => ['Total ukuran attachment per upload maksimal 40 MB.'],
            ]);
        }

        $stored = [];
        foreach ($files as $index => $file) {
            try {
                $stored[] = $this->storeOne($announcementId, $file, $userId);
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages([
                    "attachments.$index" => ['Attachment gagal diproses: '.$exception->getMessage()],
                ]);
            }
        }

        return $stored;
    }

    public function storeOne(string $announcementId, UploadedFile $file, ?string $userId): array
    {
        if (! function_exists('gzencode') || ! function_exists('gzdecode')) {
            throw ValidationException::withMessages([
                'attachments' => ['PHP extension zlib wajib aktif untuk kompresi attachment announcement.'],
            ]);
        }

        if (! $file->isValid()) {
            throw ValidationException::withMessages(['attachments' => ['File upload tidak valid.']]);
        }

        $sourceSize = max(0, (int) $file->getSize());
        if ($sourceSize <= 0 || $sourceSize > self::MAX_FILE_BYTES) {
            throw ValidationException::withMessages([
                'attachments' => ['Ukuran setiap attachment harus lebih dari 0 dan maksimal 12 MB.'],
            ]);
        }

        $originalName = $this->safeOriginalName($file->getClientOriginalName());
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'attachments' => ['Format file tidak diizinkan. Gunakan PDF, image, Office, TXT, atau CSV.'],
            ]);
        }

        $mime = strtolower(trim((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream')));
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'attachments' => ["MIME file {$mime} tidak diizinkan."],
            ]);
        }

        $realPath = (string) $file->getRealPath();
        $source = file_get_contents($realPath);
        if ($source === false) {
            throw new RuntimeException('File upload tidak dapat dibaca.');
        }
        $this->assertContentSignature($source, $extension, $realPath);
        $mime = $this->normalizeDetectedMime($mime, $extension);

        [$normalized, $normalizedMime] = $this->normalizePayload($source, $mime, $extension);
        $compressed = gzencode($normalized, 9, ZLIB_ENCODING_GZIP);
        if ($compressed === false) {
            throw new RuntimeException('Kompresi gzip gagal.');
        }

        $attachmentId = (string) Str::ulid();
        $path = "hr/announcements/{$announcementId}/{$attachmentId}.payload.gz";
        $disk = 'local';
        if (! Storage::disk($disk)->put($path, $compressed)) {
            throw new RuntimeException('File terkompresi gagal disimpan ke private storage.');
        }

        $now = now();
        DB::table('HR_announcement_attachments')->insert([
            'id' => $attachmentId,
            'announcement_id' => $announcementId,
            'original_name' => $originalName,
            'mime_type' => $normalizedMime,
            'extension' => $extension ?: null,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'compression_method' => 'gzip',
            'source_size_bytes' => $sourceSize,
            'normalized_size_bytes' => strlen($normalized),
            'compressed_size_bytes' => strlen($compressed),
            'sha256' => hash('sha256', $normalized),
            'uploaded_by_user_id' => $userId,
            'purged_at' => null,
            'purge_reason' => null,
            'stored_bytes_before_purge' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        return $this->present(DB::table('HR_announcement_attachments')->where('id', $attachmentId)->first());
    }

    public function readPayload(object $attachment): string
    {
        if (! empty($attachment->purged_at)) {
            throw new RuntimeException('ATTACHMENT_PURGED');
        }

        $disk = (string) ($attachment->storage_disk ?: 'local');
        $path = (string) $attachment->storage_path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('ATTACHMENT_NOT_FOUND');
        }

        $compressed = Storage::disk($disk)->get($path);
        $payload = ($attachment->compression_method ?? '') === 'gzip' ? gzdecode($compressed) : $compressed;
        if ($payload === false) {
            throw new RuntimeException('ATTACHMENT_DECOMPRESSION_FAILED');
        }

        if (! hash_equals((string) $attachment->sha256, hash('sha256', $payload))) {
            throw new RuntimeException('ATTACHMENT_CHECKSUM_MISMATCH');
        }

        return $payload;
    }

    public function purge(string $attachmentId, string $reason = 'manual_delete', bool $softDelete = false): bool
    {
        $row = DB::table('HR_announcement_attachments')->where('id', $attachmentId)->first();
        if (! $row) {
            return false;
        }

        $bytes = (int) ($row->compressed_size_bytes ?? 0);
        if (empty($row->purged_at)) {
            $disk = (string) ($row->storage_disk ?: 'local');
            $path = (string) ($row->storage_path ?? '');
            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                try {
                    $bytes = (int) Storage::disk($disk)->size($path);
                } catch (\Throwable) {
                    // Keep recorded compressed size when filesystem size cannot be resolved.
                }
                Storage::disk($disk)->delete($path);
            }
        }

        $update = [
            'purged_at' => $row->purged_at ?: now(),
            'purge_reason' => $reason,
            'stored_bytes_before_purge' => $bytes,
            'updated_at' => now(),
        ];
        if ($softDelete) {
            $update['deleted_at'] = now();
        }
        DB::table('HR_announcement_attachments')->where('id', $attachmentId)->update($update);
        return true;
    }

    public function purgeAnnouncement(string $announcementId, string $reason = 'announcement_expired'): int
    {
        $ids = DB::table('HR_announcement_attachments')
            ->where('announcement_id', $announcementId)
            ->whereNull('purged_at')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $count = 0;
        foreach ($ids as $id) {
            if ($this->purge($id, $reason, false)) {
                $count++;
            }
        }
        return $count;
    }

    public function present(?object $row): ?array
    {
        if (! $row) return null;
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->original_name,
            'mime_type' => (string) $row->mime_type,
            'extension' => $row->extension ? (string) $row->extension : null,
            'source_size_bytes' => (int) $row->source_size_bytes,
            'compressed_size_bytes' => (int) $row->compressed_size_bytes,
            'compression_method' => (string) $row->compression_method,
            'purged_at' => $row->purged_at ? (string) $row->purged_at : null,
            'purge_reason' => $row->purge_reason ? (string) $row->purge_reason : null,
            'is_available' => empty($row->purged_at) && empty($row->deleted_at),
        ];
    }

    private function safeOriginalName(string $name): string
    {
        $name = trim(str_replace(["\0", "\r", "\n", '/', '\\'], ['', '', '', '_', '_'], $name));
        $name = preg_replace('/\s+/u', ' ', $name) ?: 'attachment';
        return mb_substr($name !== '' ? $name : 'attachment', 0, 240);
    }

    private function assertContentSignature(string $source, string $extension, string $realPath): void
    {
        $invalid = fn () => ValidationException::withMessages(['attachments' => ["Isi file tidak sesuai ekstensi .{$extension}."]]);

        if ($extension === 'pdf' && strpos(substr($source, 0, 1024), '%PDF-') === false) throw $invalid();
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            if (! function_exists('getimagesizefromstring') || @getimagesizefromstring($source) === false) throw $invalid();
        }
        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            if (! str_starts_with($source, "PK")) throw $invalid();
            if (class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive();
                if ($zip->open($realPath) !== true) throw $invalid();
                $required = match ($extension) { 'docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml' };
                $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($required) !== false;
                $zip->close();
                if (! $valid) throw $invalid();
            }
        }
        if (in_array($extension, ['doc', 'xls', 'ppt'], true) && ! str_starts_with($source, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) throw $invalid();
        if (in_array($extension, ['txt', 'csv'], true) && str_contains($source, "\0")) throw $invalid();
    }


    private function normalizeDetectedMime(string $mime, string $extension): string
    {
        $byExtension = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
            'txt' => 'text/plain', 'csv' => 'text/csv',
        ];

        if (in_array($mime, ['application/octet-stream', 'application/zip', 'application/x-zip-compressed'], true)) {
            return $byExtension[$extension] ?? $mime;
        }
        return $mime;
    }

    /** @return array{0:string,1:string} */
    private function normalizePayload(string $source, string $mime, string $extension): array
    {
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ! function_exists('imagecreatefromstring')) {
            return [$source, $mime];
        }

        $image = @imagecreatefromstring($source);
        if (! $image) {
            return [$source, $mime];
        }

        ob_start();
        $ok = match ($mime) {
            'image/jpeg' => function_exists('imagejpeg') ? imagejpeg($image, null, 78) : false,
            'image/png' => function_exists('imagepng') ? imagepng($image, null, 9) : false,
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, null, 78) : false,
            default => false,
        };
        $encoded = ob_get_clean();
        imagedestroy($image);

        if (! $ok || ! is_string($encoded) || $encoded === '') {
            return [$source, $mime];
        }

        return [$encoded, $mime];
    }
}
