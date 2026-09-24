<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PurchasingDocumentNumberAllocator
{
    /**
     * Allocate a collision-safe daily document number.
     * The sequence floor is synchronized against real documents so Reset Transaksi,
     * sequence cleanup, and partial historical data can never reuse an existing number.
     */
    public function next(
        string $documentType,
        string $prefix,
        string $table,
        string $numberColumn,
        ?string $businessDate = null,
    ): string {
        if (! Schema::hasTable('pur_document_sequences')) {
            throw new InvalidArgumentException('pur_document_sequences belum tersedia.');
        }
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $numberColumn)) {
            throw new InvalidArgumentException("Target nomor dokumen {$table}.{$numberColumn} tidak tersedia.");
        }

        $date = $businessDate ?: now('Asia/Jakarta')->toDateString();
        $dateKey = str_replace('-', '', substr($date, 0, 10));
        $periodKey = $dateKey; // Post-F04: daily sequence matches YYYYMMDD document prefix.
        $documentType = strtoupper(trim($documentType));
        $prefix = strtoupper(trim($prefix));
        $like = $prefix.'-'.$dateKey.'-%';

        DB::table('pur_document_sequences')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'document_type' => $documentType,
            'period_key' => $periodKey,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('pur_document_sequences')
            ->where('document_type', $documentType)
            ->where('period_key', $periodKey)
            ->lockForUpdate()
            ->first();
        if (! $sequence) {
            throw new InvalidArgumentException('Sequence dokumen Purchasing gagal dikunci.');
        }

        $existingNumbers = DB::table($table)
            ->where($numberColumn, 'like', $like)
            ->lockForUpdate()
            ->pluck($numberColumn);

        $maxExisting = 0;
        foreach ($existingNumbers as $number) {
            if (preg_match('/-(\d+)$/', (string) $number, $matches)) {
                $maxExisting = max($maxExisting, (int) $matches[1]);
            }
        }

        $next = max((int) ($sequence->last_number ?? 0), $maxExisting) + 1;
        do {
            $candidate = sprintf('%s-%s-%04d', $prefix, $dateKey, $next);
            $exists = DB::table($table)->where($numberColumn, $candidate)->exists();
            if ($exists) $next++;
        } while ($exists);

        DB::table('pur_document_sequences')
            ->where('id', $sequence->id)
            ->update(['last_number' => $next, 'updated_at' => now()]);

        return $candidate;
    }

    /** Synchronize sequence floor without generating a document. */
    public function syncFloor(string $documentType, string $prefix, string $table, string $numberColumn, ?string $businessDate = null): array
    {
        $date = $businessDate ?: now('Asia/Jakarta')->toDateString();
        $dateKey = str_replace('-', '', substr($date, 0, 10));
        $periodKey = $dateKey;
        $like = strtoupper($prefix).'-'.$dateKey.'-%';

        DB::table('pur_document_sequences')->insertOrIgnore([
            'id'=>(string)Str::ulid(),'document_type'=>strtoupper($documentType),'period_key'=>$periodKey,
            'last_number'=>0,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $row=DB::table('pur_document_sequences')->where('document_type',strtoupper($documentType))->where('period_key',$periodKey)->lockForUpdate()->first();
        $max=0;
        foreach(DB::table($table)->where($numberColumn,'like',$like)->pluck($numberColumn) as $number){
            if(preg_match('/-(\d+)$/',(string)$number,$m))$max=max($max,(int)$m[1]);
        }
        $before=(int)($row->last_number??0);$after=max($before,$max);
        if($after!==$before)DB::table('pur_document_sequences')->where('id',$row->id)->update(['last_number'=>$after,'updated_at'=>now()]);
        return ['document_type'=>strtoupper($documentType),'period_key'=>$periodKey,'before'=>$before,'max_existing'=>$max,'after'=>$after];
    }
}
