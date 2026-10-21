<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** V13 I04: authoritative company = Finance mapping; region = outlets.hr_region. */
final class HrV13I04OutletCompanyService
{
    public const REGIONS = ['Malang', 'Bali', 'Bandung', 'Borneo'];

    public function assertReady(): void
    {
        foreach (['outlets', 'finance_companies', 'finance_outlet_company_mappings', 'hr_v13_i04_pt_mapping_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                throw ValidationException::withMessages(['schema' => ["Table {$table} tidak tersedia. Jalankan migration."]]);
            }
        }
        if (! Schema::hasColumn('outlets', 'hr_region')) {
            throw ValidationException::withMessages(['schema' => ['Kolom outlets.hr_region tidak ditemukan. Jalankan migration.']]);
        }
    }

    public function companies(): array
    {
        $this->assertReady();
        return DB::table('finance_companies')->where('is_active', true)->orderBy('code')
            ->get(['code','name','legal_name'])->map(fn ($c) => (array) $c)->all();
    }

    public function companyCode(?string $outletId): ?string
    {
        if (! $outletId || ! Schema::hasTable('finance_outlet_company_mappings')) return null;
        $value = DB::table('finance_outlet_company_mappings')->where('outlet_id', $outletId)
            ->where('is_active', true)->value('company_code');
        return $value === null ? null : strtoupper((string) $value);
    }

    public function snapshot(): array
    {
        $this->assertReady();
        $outlets = DB::table('outlets as o')
            ->leftJoin('finance_outlet_company_mappings as m', function ($j): void {
                $j->on('m.outlet_id','=','o.id')->where('m.is_active', true);
            })
            ->select(['o.id','o.code','o.name','o.type','o.hr_region','m.company_code']);
        if (Schema::hasColumn('outlets','is_active')) $outlets->where('o.is_active',true);
        $rows = $outlets->orderBy('o.code')->get()->map(fn ($row) => (array) $row)->all();
        return [
            'companies' => $this->companies(), 'regions' => self::REGIONS,
            'items' => $rows, 'revision' => $this->revision($rows),
            'unmapped_count' => count(array_filter($rows, fn ($r) => empty($r['company_code']))),
        ];
    }

    /** Stable optimistic concurrency token over the whole active outlet-to-company set. */
    public function revision(array $rows): string
    {
        $state = array_map(fn ($r) => [(string) $r['id'], $r['company_code'] ?? null], $rows);
        usort($state, fn ($a, $b) => strcmp($a[0], $b[0]));
        return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    public function assertActiveCompany(?string $value): ?string
    {
        $value = trim(strtoupper((string) ($value ?? '')));
        if ($value === '') return null;
        if (! DB::table('finance_companies')->where('code', $value)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['company_code' => ["PT {$value} tidak ditemukan/aktif pada Finance."]]);
        }
        return $value;
    }

    public function normalizeRegion(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return null;
        foreach (self::REGIONS as $region) {
            if (strcasecmp($region, $value) === 0) return $region;
        }
        throw ValidationException::withMessages(['hr_region' => ['Wilayah harus Malang, Bali, Bandung, Borneo, atau kosong.']]);
    }

    /** Called inside DB transaction after locking outlet. Returns whether any change was made. */
    public function changeLocked(object $outlet, bool $changeRegion, mixed $newRegion,
        bool $changeCompany, mixed $newCompany, bool $allowReassignment,
        ?string $expectedCompany, ?string $actor, string $source, ?string $reason = null): bool
    {
        $oldRegion = $outlet->hr_region ?? null;
        $oldCompany = $this->companyCode((string) $outlet->id);
        if ($changeCompany && $expectedCompany !== $oldCompany) {
            throw ValidationException::withMessages(['company_code' => [
                'Mapping PT outlet berubah sejak halaman dimuat. Muat ulang data dan coba lagi.',
            ]]);
        }
        $region = $changeRegion ? $this->normalizeRegion($newRegion) : $oldRegion;
        $company = $changeCompany ? $this->assertActiveCompany($newCompany) : $oldCompany;
        if ($oldCompany !== null && $oldCompany !== $company && ! $allowReassignment) {
            throw ValidationException::withMessages(['company_code' => [
                'Outlet sudah terdaftar pada PT '.$oldCompany.'. Konfirmasi pemindahan/penghapusan mapping diperlukan.',
            ]]);
        }
        if ($oldCompany === $company && $oldRegion === $region) return false;
        if ($oldRegion !== $region) {
            DB::table('outlets')->where('id', $outlet->id)->update(['hr_region' => $region, 'updated_at' => now()]);
        }
        if ($oldCompany !== $company) {
            // Existing schema imposes UNIQUE(outlet_id); update in place and keep history in append-only HR audit.
            $existing = DB::table('finance_outlet_company_mappings')->where('outlet_id', $outlet->id)->lockForUpdate()->first();
            if ($company === null) {
                if ($existing) DB::table('finance_outlet_company_mappings')->where('outlet_id', $outlet->id)
                    ->update(['is_active' => false, 'effective_to' => now('Asia/Jakarta')->toDateString(),
                        'updated_at' => now(), 'updated_by_user_id' => $actor]);
            } else {
                DB::table('finance_outlet_company_mappings')->updateOrInsert(
                    ['outlet_id' => $outlet->id],
                    ['id' => (string) ($existing->id ?? Str::ulid()), 'company_code' => $company,
                        'is_active' => true, 'effective_from' => now('Asia/Jakarta')->toDateString(),
                        'effective_to' => null, 'updated_by_user_id' => $actor,
                        'created_at' => $existing->created_at ?? now(), 'updated_at' => now()]);
            }
        }
        DB::table('hr_v13_i04_pt_mapping_audits')->insert([
            'id' => (string) Str::ulid(), 'outlet_id' => $outlet->id,
            'outlet_code' => $outlet->code ?? null,
            'before_company' => $oldCompany, 'after_company' => $company,
            'before_region' => $oldRegion, 'after_region' => $region,
            'source' => $source, 'actor_user_id' => $actor, 'reason' => $reason,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return true;
    }

    /** One-outlet update; `expectedCompany` required for company writes, allowing NULL. */
    public function updateOutlet(string $id, bool $changeRegion, mixed $region, bool $changeCompany,
        mixed $company, bool $allow, ?string $expected, ?string $actor, string $source): bool
    {
        $this->assertReady();
        return DB::transaction(function () use ($id,$changeRegion,$region,$changeCompany,$company,$allow,$expected,$actor,$source) {
            $outlet = DB::table('outlets')->where('id', $id)->lockForUpdate()->first();
            if (! $outlet) throw ValidationException::withMessages(['outlet_id' => ['Outlet tidak ditemukan.']]);
            return $this->changeLocked($outlet, $changeRegion,$region,$changeCompany,$company,$allow,$expected,$actor,$source);
        }, 3);
    }

    /** Bulk checkbox: atomically compute additions, removals, conflicts, with optimistic revision. */
    public function assignCompany(string $code, array $ids, string $revision,
        bool $allowReassign, ?string $actor): array
    {
        $this->assertReady();
        $company = $this->assertActiveCompany($code);
        if ($company === null) throw ValidationException::withMessages(['company_code' => ['PT wajib dipilih.']]);
        $ids = array_values(array_unique(array_map('strval', $ids)));
        return DB::transaction(function () use ($company,$ids,$revision,$allowReassign,$actor) {
            // Global lock ordering serializes checklist updates; all outlets are few (< 100 typically).
            $rows = DB::table('outlets')->orderBy('id')->lockForUpdate()->get();
            $map = DB::table('finance_outlet_company_mappings')->where('is_active', true)->get(['outlet_id','company_code'])
                ->keyBy('outlet_id');
            $activeRows = $rows->filter(fn ($r) => ! property_exists($r,'is_active') || $r->is_active);
            $current = $activeRows->map(fn ($r) => [
                'id' => $r->id, 'company_code' => $map[$r->id]->company_code ?? null,
            ])->all();
            if (! hash_equals($this->revision($current), $revision)) {
                throw ValidationException::withMessages(['revision' => [
                    'Mapping PT telah berubah. Refresh daftar sebelum menyimpan ulang.',
                ]]);
            }
            $valid = array_fill_keys($activeRows->pluck('id')->map(fn ($id) => (string) $id)->all(), true);
            foreach ($ids as $id) {
                if (! isset($valid[$id])) throw ValidationException::withMessages(['outlet_ids' => ["Outlet {$id} tidak dikenal/nonaktif."]]);
            }
            $chosen = array_fill_keys($ids, true);
            $changes = 0;
            foreach ($activeRows as $row) {
                $before = $map[$row->id]->company_code ?? null;
                $next = isset($chosen[$row->id]) ? $company : ($before === $company ? null : $before);
                if ($before === $next) continue;
                if ($before && $before !== $next && ! $allowReassign) {
                    throw ValidationException::withMessages(['outlet_ids' => [
                        "{$row->code} terdaftar pada {$before}; aktifkan konfirmasi pemindahan/penghapusan terlebih dahulu.",
                    ]]);
                }
                if ($this->changeLocked($row, false, null, true, $next, true, $before, $actor, 'hr-pt-checkbox',
                    'Penyesuaian checklist Data PT')) $changes++;
            }
            return ['changed' => $changes, 'company_code' => $company];
        }, 3);
    }
}
