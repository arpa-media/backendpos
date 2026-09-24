<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\DocumentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class PurchasingDocumentAttachmentService
{
    public const FUND_REQUEST = 'FUND_REQUEST';
    public const PURCHASE_ORDER = 'PURCHASE_ORDER';
    public const SERVICE_ORDER = 'SERVICE_ORDER';
    public const REIMBURSE_ORDER = 'REIMBURSE_ORDER';
    public const SERVICE_ENTRY_SHEET = 'SERVICE_ENTRY_SHEET';
    public const GOODS_RECEIPT = 'GOODS_RECEIPT';
    public const SERVICE_ACCEPTANCE = 'SERVICE_ACCEPTANCE';
    public const REIMBURSE_PAYMENT = 'REIMBURSE_PAYMENT';

    public const MAX_FILES = 10;
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;
    public const MAX_IMAGE_EDGE = 2200;

    /** @return array<int, array<string, mixed>> */
    public function list(string $documentType, string $documentId): array
    {
        if (! Schema::hasTable('pur_document_attachments')) {
            return [];
        }

        return DocumentAttachment::query()
            ->where('document_type', $this->normalizeType($documentType))
            ->where('document_id', $documentId)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (DocumentAttachment $row): array => $this->serialize($row))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function summary(string $documentType, string $documentId, bool $required = false): array
    {
        $count = 0;
        if (Schema::hasTable('pur_document_attachments')) {
            $count = DocumentAttachment::query()
                ->where('document_type', $this->normalizeType($documentType))
                ->where('document_id', $documentId)
                ->count();
        }

        return [
            'count' => $count,
            'required' => $required,
            'satisfied' => ! $required || $count > 0,
            'allowed_mime_types' => [
                'image/jpeg',
                'image/png',
                'image/webp',
                'application/pdf',
            ],
            'max_files' => self::MAX_FILES,
            'max_file_size_mb' => (int) (self::MAX_FILE_SIZE / 1024 / 1024),
            'compression' => 'BEST_EFFORT',
        ];
    }

    public function hasAny(string $documentType, string $documentId): bool
    {
        if (! Schema::hasTable('pur_document_attachments')) {
            return false;
        }

        return DocumentAttachment::query()
            ->where('document_type', $this->normalizeType($documentType))
            ->where('document_id', $documentId)
            ->exists();
    }

    public function store(
        string $documentType,
        string $documentId,
        UploadedFile $file,
        ?User $actor = null,
        string $attachmentType = 'SUPPORTING_DOCUMENT',
    ): DocumentAttachment {
        if (! Schema::hasTable('pur_document_attachments')) {
            throw ValidationException::withMessages(['file' => 'Tabel attachment Purchasing belum tersedia. Jalankan migration Iterasi 04.']);
        }

        $type = $this->normalizeType($documentType);
        $count = DocumentAttachment::query()
            ->where('document_type', $type)
            ->where('document_id', $documentId)
            ->count();

        if ($count >= self::MAX_FILES) {
            throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_FILES.' lampiran untuk satu dokumen.']);
        }

        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['file' => 'Lampiran hanya boleh JPG, PNG, WEBP, atau PDF.']);
        }

        $originalSize = (int) $file->getSize();
        if ($originalSize <= 0 || $originalSize > self::MAX_FILE_SIZE) {
            throw ValidationException::withMessages(['file' => 'Ukuran lampiran maksimal '.(int) (self::MAX_FILE_SIZE / 1024 / 1024).' MB.']);
        }

        $id = (string) Str::ulid();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $storedName = $id.'.'.$extension;
        $directory = sprintf('purchasing/attachments/%s/%s', strtolower($type), $documentId);
        $targetPath = $directory.'/'.$storedName;
        [$sourcePath, $storedSize, $compressionStatus, $temporary] = $this->prepareCompressedFile($file, $mime);

        try {
            $stream = fopen($sourcePath, 'rb');
            if ($stream === false || ! Storage::disk('local')->put($targetPath, $stream)) {
                if (is_resource($stream)) fclose($stream);
                throw ValidationException::withMessages(['file' => 'Lampiran gagal disimpan.']);
            }
            if (is_resource($stream)) fclose($stream);

            return DocumentAttachment::query()->create([
                'id' => $id,
                'document_type' => $type,
                'document_id' => $documentId,
                'attachment_type' => strtoupper(trim($attachmentType)) ?: 'SUPPORTING_DOCUMENT',
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
                'stored_name' => $storedName,
                'mime_type' => $mime,
                'file_size' => $storedSize,
                'original_file_size' => $originalSize,
                'compression_status' => $compressionStatus,
                'sha256' => hash_file('sha256', $sourcePath) ?: null,
                'disk' => 'local',
                'path' => $targetPath,
                'sort_order' => $count + 1,
                'uploaded_by_user_id' => $actor?->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($targetPath);
            throw $e;
        } finally {
            if ($temporary && is_file($sourcePath)) @unlink($sourcePath);
        }
    }

    public function delete(DocumentAttachment $attachment): void
    {
        $disk = (string) ($attachment->disk ?: 'local');
        $path = (string) $attachment->path;

        $attachment->delete();
        if ($path !== '') {
            Storage::disk($disk)->delete($path);
        }
    }

    public function purgeDocument(string $documentType, string $documentId): void
    {
        if (! Schema::hasTable('pur_document_attachments')) {
            return;
        }

        $rows = DocumentAttachment::query()
            ->where('document_type', $this->normalizeType($documentType))
            ->where('document_id', $documentId)
            ->get();

        foreach ($rows as $row) {
            $this->delete($row);
        }
    }

    /** @return array<string, mixed> */
    public function serialize(DocumentAttachment $row): array
    {
        return [
            'id' => (string) $row->id,
            'document_type' => (string) $row->document_type,
            'document_id' => (string) $row->document_id,
            'attachment_type' => (string) $row->attachment_type,
            'original_name' => (string) $row->original_name,
            'mime_type' => (string) $row->mime_type,
            'file_size' => (int) $row->file_size,
            'original_file_size' => (int) ($row->original_file_size ?: $row->file_size),
            'compression_status' => (string) ($row->compression_status ?: 'ORIGINAL'),
            'saved_bytes' => max((int) ($row->original_file_size ?: $row->file_size) - (int) $row->file_size, 0),
            'is_image' => str_starts_with(strtolower((string) $row->mime_type), 'image/'),
            'is_pdf' => strtolower((string) $row->mime_type) === 'application/pdf',
            'content_path' => '/purchasing/document-attachments/'.(string) $row->id.'/content',
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /** @return array{0:string,1:int,2:string,3:bool} */
    private function prepareCompressedFile(UploadedFile $file, string $mime): array
    {
        $source = (string) $file->getRealPath();
        $size = (int) $file->getSize();

        if (str_starts_with($mime, 'image/')) {
            $compressed = $this->compressImage($source, $mime);
            if ($compressed !== null && is_file($compressed) && (int) filesize($compressed) > 0 && (int) filesize($compressed) < $size) {
                return [$compressed, (int) filesize($compressed), 'COMPRESSED_IMAGE', true];
            }
            if ($compressed !== null && is_file($compressed)) @unlink($compressed);
            return [$source, $size, 'ORIGINAL_IMAGE', false];
        }

        if ($mime === 'application/pdf') {
            $compressed = $this->compressPdf($source);
            if ($compressed !== null && is_file($compressed) && (int) filesize($compressed) > 0 && (int) filesize($compressed) < $size) {
                return [$compressed, (int) filesize($compressed), 'COMPRESSED_PDF', true];
            }
            if ($compressed !== null && is_file($compressed)) @unlink($compressed);
            return [$source, $size, 'PDF_COMPRESSOR_UNAVAILABLE_OR_NOT_SMALLER', false];
        }

        return [$source, $size, 'ORIGINAL', false];
    }

    private function compressImage(string $source, string $mime): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecreatetruecolor')) return null;
        $bytes = @file_get_contents($source);
        if ($bytes === false) return null;
        $image = @imagecreatefromstring($bytes);
        if (! $image) return null;

        $width = imagesx($image); $height = imagesy($image); $target = $image;
        $maxEdge = max($width, $height);
        if ($maxEdge > self::MAX_IMAGE_EDGE) {
            $scale = self::MAX_IMAGE_EDGE / $maxEdge;
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if (in_array($mime, ['image/png', 'image/webp'], true)) { imagealphablending($resized, false); imagesavealpha($resized, true); }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            $target = $resized;
        }

        $temp = tempnam(sys_get_temp_dir(), 'pur_att_img_');
        if ($temp === false) { if ($target !== $image) imagedestroy($target); imagedestroy($image); return null; }
        $ok = match ($mime) {
            'image/jpeg' => function_exists('imagejpeg') && imagejpeg($target, $temp, 82),
            'image/png' => function_exists('imagepng') && imagepng($target, $temp, 8),
            'image/webp' => function_exists('imagewebp') && imagewebp($target, $temp, 80),
            default => false,
        };
        if ($target !== $image) imagedestroy($target);
        imagedestroy($image);
        if (! $ok) { @unlink($temp); return null; }
        return $temp;
    }

    private function compressPdf(string $source): ?string
    {
        if (! class_exists(Process::class)) return null;
        $binary = $this->ghostscriptBinary();
        if ($binary === null) return null;
        $output = tempnam(sys_get_temp_dir(), 'pur_att_pdf_');
        if ($output === false) return null;
        try {
            $process = new Process([$binary, '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4', '-dPDFSETTINGS=/ebook', '-dNOPAUSE', '-dQUIET', '-dBATCH', '-dDetectDuplicateImages=true', '-dCompressFonts=true', '-sOutputFile='.$output, $source]);
            $process->setTimeout(45);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($output) || (int) filesize($output) <= 0) { @unlink($output); return null; }
            return $output;
        } catch (\Throwable) { @unlink($output); return null; }
    }

    private function ghostscriptBinary(): ?string
    {
        foreach (['gs', 'gswin64c', 'gswin32c'] as $candidate) {
            try {
                $process = new Process([$candidate, '--version']); $process->setTimeout(3); $process->run();
                if ($process->isSuccessful()) return $candidate;
            } catch (\Throwable) {}
        }
        return null;
    }

    public function normalizeType(string $documentType): string
    {
        $value = strtoupper(str_replace('-', '_', trim($documentType)));

        return match ($value) {
            'FUND_REQUEST', 'FUND_REQUESTS', 'REQUEST' => self::FUND_REQUEST,
            'PURCHASE_ORDER', 'PURCHASE_ORDERS', 'PO' => self::PURCHASE_ORDER,
            'SERVICE_ORDER', 'SERVICE_ORDERS', 'SO' => self::SERVICE_ORDER,
            'REIMBURSE_ORDER', 'REIMBURSE_ORDERS', 'RO' => self::REIMBURSE_ORDER,
            'SERVICE_ENTRY_SHEET', 'SERVICE_ENTRY_SHEETS', 'SES' => self::SERVICE_ENTRY_SHEET,
            'GOODS_RECEIPT', 'GOODS_RECEIPTS', 'GR' => self::GOODS_RECEIPT,
            'SERVICE_ACCEPTANCE', 'SERVICE_ACCEPTANCES', 'SA' => self::SERVICE_ACCEPTANCE,
            'REIMBURSE_PAYMENT', 'REIMBURSE_PAYMENTS', 'RP' => self::REIMBURSE_PAYMENT,
            default => throw ValidationException::withMessages([
                'document_type' => 'Jenis dokumen lampiran Purchasing belum didukung.',
            ]),
        };
    }
}
