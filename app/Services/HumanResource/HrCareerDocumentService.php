<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrCareerAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrCareerDocumentService
{
    public function storeCv(HrCareerAccount $account, UploadedFile $file): array
    {
        if (! function_exists('gzencode') || ! function_exists('gzdecode')) {
            throw ValidationException::withMessages(['cv' => ['PHP zlib wajib aktif untuk kompresi CV.']]);
        }
        $size = (int) $file->getSize();
        if ($size < 20 || $size > 10 * 1024 * 1024) throw ValidationException::withMessages(['cv' => ['CV PDF maksimal 10 MB.']]);
        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF-')) {
            throw ValidationException::withMessages(['cv' => ['File CV wajib PDF valid.']]);
        }
        $compressed = gzencode($bytes, 9, ZLIB_ENCODING_GZIP);
        if ($compressed === false) throw new RuntimeException('Kompresi CV gagal.');

        return DB::transaction(function () use ($account, $file, $bytes, $compressed, $size): array {
            $current = DB::table('HR_career_documents')->where('career_account_id', $account->id)->where('document_type', 'cv')->where('is_current', true)->lockForUpdate()->get();
            $version = (int) DB::table('HR_career_documents')->where('career_account_id', $account->id)->where('document_type', 'cv')->max('version') + 1;
            foreach ($current as $old) {
                if (! empty($old->storage_path)) Storage::disk((string) ($old->storage_disk ?: 'local'))->delete((string) $old->storage_path);
                DB::table('HR_career_documents')->where('id', $old->id)->update(['is_current' => false, 'purged_at' => now(), 'updated_at' => now()]);
            }
            $id = (string) Str::ulid();
            $path = 'hr/career/cv/'.substr((string) $account->id, 0, 2).'/'.$account->id.'/'.$id.'.pdf.gz';
            if (! Storage::disk('local')->put($path, $compressed)) throw new RuntimeException('Penyimpanan CV gagal.');
            DB::table('HR_career_documents')->insert([
                'id' => $id, 'career_account_id' => $account->id, 'document_type' => 'cv', 'version' => $version,
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255), 'mime_type' => 'application/pdf',
                'original_size' => $size, 'stored_size' => strlen($compressed), 'sha256' => hash('sha256', $bytes),
                'storage_disk' => 'local', 'storage_path' => $path, 'compression_method' => 'gzip', 'is_current' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->metadata((object) DB::table('HR_career_documents')->where('id', $id)->first());
        });
    }

    public function currentCv(HrCareerAccount|string $account): ?object
    {
        $id = $account instanceof HrCareerAccount ? $account->id : $account;
        return DB::table('HR_career_documents')->where('career_account_id', $id)->where('document_type', 'cv')->where('is_current', true)->whereNull('purged_at')->orderByDesc('version')->first();
    }

    public function downloadForAccount(HrCareerAccount|string $account): StreamedResponse
    {
        $row = $this->currentCv($account);
        abort_unless($row, 404, 'CV belum tersedia.');
        return $this->downloadRow($row);
    }

    public function downloadForAdmin(string $documentId): StreamedResponse
    {
        $row = DB::table('HR_career_documents')->where('id', $documentId)->whereNull('purged_at')->first();
        abort_unless($row, 404, 'Dokumen tidak ditemukan.');
        return $this->downloadRow($row);
    }

    public function metadata(object $row): array
    {
        return [
            'id' => (string) $row->id, 'document_type' => (string) $row->document_type, 'version' => (int) $row->version,
            'original_name' => (string) $row->original_name, 'mime_type' => (string) $row->mime_type,
            'original_size' => (int) $row->original_size, 'stored_size' => (int) $row->stored_size,
            'compression_ratio' => (int) $row->original_size > 0 ? round(((int) $row->stored_size / (int) $row->original_size) * 100, 1) : null,
            'created_at' => $row->created_at,
        ];
    }

    private function downloadRow(object $row): StreamedResponse
    {
        $binary = Storage::disk((string) ($row->storage_disk ?: 'local'))->get((string) $row->storage_path);
        $payload = (string) $row->compression_method === 'gzip' ? gzdecode($binary) : $binary;
        if (! is_string($payload) || hash('sha256', $payload) !== (string) $row->sha256) abort(500, 'Integritas file CV gagal diverifikasi.');
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $row->original_name) ?: 'CV.pdf';
        return response()->streamDownload(fn () => print($payload), $name, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }
}
