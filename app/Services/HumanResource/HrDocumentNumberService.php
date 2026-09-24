<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HrDocumentNumberService
{
    /**
     * Canonical format follows the provided HR letter samples:
     * JAYA/{MM}/HR/{LETTER_CODE}/{COMPANY}/{NNN}/{YYYY}
     *
     * Example: JAYA/08/HR/SPN/BKJB/001/2026.
     */
    public function allocate(string $letterCode, string $companyCode, mixed $issueDate = null): string
    {
        $letterCode = $this->token($letterCode, 'letter_code');
        $companyCode = $this->token($companyCode, 'company_code');
        $date = $this->date($issueDate);
        $year = (int) $date->format('Y');

        if (! Schema::hasTable('HR_document_number_sequences')) {
            throw ValidationException::withMessages([
                'document_no' => ['Sequence nomor dokumen HR belum tersedia. Jalankan migration Iterasi 02.'],
            ]);
        }

        return DB::transaction(function () use ($letterCode, $companyCode, $date, $year): string {
            $existing = DB::table('HR_document_number_sequences')
                ->where('letter_code', $letterCode)
                ->where('company_code', $companyCode)
                ->where('year', $year)
                ->first();

            if (! $existing) {
                DB::table('HR_document_number_sequences')->insertOrIgnore([
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'letter_code' => $letterCode,
                    'company_code' => $companyCode,
                    'year' => $year,
                    'last_sequence' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $row = DB::table('HR_document_number_sequences')
                ->where('letter_code', $letterCode)
                ->where('company_code', $companyCode)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw ValidationException::withMessages(['document_no' => ['Sequence nomor dokumen HR gagal dikunci.']]);
            }

            $sequence = ((int) $row->last_sequence) + 1;
            DB::table('HR_document_number_sequences')->where('id', $row->id)->update([
                'last_sequence' => $sequence,
                'updated_at' => now(),
            ]);

            return $this->format($letterCode, $companyCode, $sequence, $date);
        }, 3);
    }

    public function example(string $letterCode, string $companyCode, mixed $issueDate = null): string
    {
        return $this->format(
            $this->token($letterCode, 'letter_code'),
            $this->token($companyCode, 'company_code'),
            1,
            $this->date($issueDate),
        );
    }

    private function format(string $letterCode, string $companyCode, int $sequence, Carbon $date): string
    {
        $month = $date->format('m');
        $running = str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
        $year = $date->format('Y');

        return "JAYA/{$month}/HR/{$letterCode}/{$companyCode}/{$running}/{$year}";
    }

    private function token(string $value, string $field): string
    {
        $token = strtoupper(trim($value));
        if ($token === '' || ! preg_match('/^[A-Z0-9_-]{1,16}$/', $token)) {
            throw ValidationException::withMessages([$field => ['Token nomor dokumen HR tidak valid.']]);
        }

        return $token;
    }

    private function date(mixed $value): Carbon
    {
        try {
            return Carbon::parse($value ?: now());
        } catch (\Throwable) {
            return now();
        }
    }
}
