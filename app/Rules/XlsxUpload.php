<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\UploadedFile;
use Throwable;
use ZipArchive;

final class XlsxUpload implements ValidationRule
{
    private const MAX_ARCHIVE_ENTRIES = 20000;
    private const MAX_UNCOMPRESSED_BYTES = 134217728; // 128 MiB defensive ceiling.

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('File XLSX gagal diupload atau tidak dapat dibaca.');
            return;
        }

        if (mb_strtolower((string) $value->getClientOriginalExtension()) !== 'xlsx') {
            $fail('File harus menggunakan ekstensi .xlsx.');
            return;
        }

        $path = $value->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            $fail('File XLSX tidak ditemukan pada temporary upload.');
            return;
        }

        $stream = @fopen($path, 'rb');
        $signature = is_resource($stream) ? (string) fread($stream, 4) : '';
        if (is_resource($stream)) fclose($stream);
        if (! str_starts_with($signature, "PK")) {
            $fail('File .xlsx tidak memiliki struktur OpenXML/ZIP yang valid.');
            return;
        }

        // The MIME detected by PHP/fileinfo is intentionally not used here.
        // Valid XLSX uploads are frequently reported as application/zip or
        // application/octet-stream on shared hosting. We verify the package
        // structure instead, then the spreadsheet reader performs full parse.
        if (! class_exists(ZipArchive::class)) {
            try {
                $worksheets = app(SimpleXlsxService::class)->readWorksheets($value);
                if ($worksheets === []) $fail('File .xlsx tidak memiliki worksheet yang dapat dibaca.');
            } catch (Throwable $e) {
                $fail('File .xlsx tidak valid/corrupt: '.$e->getMessage());
            }
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $fail('File .xlsx tidak dapat dibuka atau corrupt.');
            return;
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                $fail('Struktur file .xlsx tidak valid atau terlalu kompleks.');
                return;
            }

            if ($zip->locateName('[Content_Types].xml') === false
                || $zip->locateName('xl/workbook.xml') === false) {
                $fail('File .xlsx bukan workbook Excel OpenXML yang valid.');
                return;
            }
            $contentTypes = (string) $zip->getFromName('[Content_Types].xml');
            if (! str_contains($contentTypes, 'spreadsheetml.sheet.main+xml')) {
                $fail('File .xlsx tidak memiliki content type workbook Excel yang valid.');
                return;
            }

            $hasWorksheet = false;
            $uncompressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name)) $hasWorksheet = true;
                $stat = $zip->statIndex($i);
                if (is_array($stat)) {
                    $uncompressed += max(0, (int) ($stat['size'] ?? 0));
                    if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                        $fail('Ukuran isi workbook .xlsx terlalu besar setelah diekstrak.');
                        return;
                    }
                }
            }

            if (! $hasWorksheet) $fail('File .xlsx tidak memiliki worksheet yang valid.');
        } finally {
            $zip->close();
        }
    }
}
