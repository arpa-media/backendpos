<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['finance_companies','finance_chart_of_accounts','finance_purchasing_posting_mappings','finance_purchasing_payment_mappings','finance_purchasing_postings','finance_purchasing_posting_journals','pur_finance_posting_outbox','finance_general_postings','finance_general_posting_journals'] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException("ERP-V5 I05 membutuhkan tabel {$table}.");
        }

        // I04 adds APB; I05 keeps itself safe/idempotent if migration order was interrupted.
        $now = now();
        $apb = DB::table('finance_companies')->where('code', 'APB')->first();
        if (! $apb) {
            $payload = ['code'=>'APB','name'=>'PT APB','is_active'=>true,'created_at'=>$now,'updated_at'=>$now];
            if (Schema::hasColumn('finance_companies', 'id')) $payload['id'] = (string) Str::ulid();
            if (Schema::hasColumn('finance_companies', 'legal_name')) $payload['legal_name'] = 'PT APB';
            DB::table('finance_companies')->insert($payload);
        } else {
            DB::table('finance_companies')->where('code','APB')->update(['is_active'=>true,'updated_at'=>$now]);
        }

        Schema::table('pur_finance_posting_outbox', function (Blueprint $t): void {
            if (! Schema::hasColumn('pur_finance_posting_outbox', 'source_fingerprint')) $t->char('source_fingerprint', 64)->nullable()->after('payload');
            if (! Schema::hasColumn('pur_finance_posting_outbox', 'general_posting_id')) $t->char('general_posting_id', 26)->nullable()->after('last_error');
        });
        Schema::table('finance_purchasing_postings', function (Blueprint $t): void {
            if (! Schema::hasColumn('finance_purchasing_postings', 'marking')) $t->string('marking', 16)->default('MARKING')->after('outlet_id');
        });

        // Corporate Fund Request scope has no outlet. Old Finance schema incorrectly forced one.
        if (! $this->columnNullable('finance_purchasing_postings', 'outlet_id')) {
            Schema::table('finance_purchasing_postings', function (Blueprint $t): void {
                $t->char('outlet_id', 26)->nullable()->change();
            });
        }

        $this->ensureIndex('pur_finance_posting_outbox', ['source_fingerprint'], 'pur_fin_outbox_fp_idx');
        $this->ensureIndex('pur_finance_posting_outbox', ['general_posting_id'], 'pur_fin_outbox_gp_idx');
        $this->ensureIndex('finance_purchasing_postings', ['marking'], 'fin_pur_post_mark_idx');
        $this->seedMappings();
        $this->backfillUnpostedAssetInvoiceKind();
        $this->backfillOutboxFingerprint();
        $this->backfillGeneralPostingLinks();
    }

    public function down(): void
    {
        // Audit-safe: do not delete mappings/fingerprints or reintroduce NOT NULL outlet_id.
    }

    private function seedMappings(): void
    {
        $companies = DB::table('finance_companies')->where('is_active', true)->pluck('code')->map(fn ($v) => strtoupper((string) $v))->filter()->values()->all();
        $coa = fn (string $code): ?string => DB::table('finance_chart_of_accounts')->where('code',$code)->where('is_active',true)->where('is_postable',true)->value('id');
        $ap = $coa('2-20100');
        $tax = $coa('1-10500');
        if (! $ap || ! $tax) throw new RuntimeException('ERP-V5 I05 membutuhkan COA Hutang Usaha 2-20100 dan PPN Masukan 1-10500.');

        $debits = [
            'SERVICE_ACCEPTANCE' => $coa('6-60300'),
            'REIMBURSE_PAYMENT' => $coa('6-60300'),
            'ASSET_RECEIPT' => $coa('1-10705'),
            'GOODS_RECEIPT' => $coa('1-10200'),
        ];
        foreach ($debits as $kind => $id) if (! $id) throw new RuntimeException("COA default {$kind} belum tersedia.");

        foreach ($companies as $company) {
            foreach ($debits as $kind => $debitId) {
                $key = "{$company}:ALL:{$kind}";
                if (DB::table('finance_purchasing_posting_mappings')->where('mapping_key',$key)->exists()) continue;
                DB::table('finance_purchasing_posting_mappings')->insert([
                    'id'=>(string)Str::ulid(),'mapping_key'=>$key,'company_code'=>$company,'outlet_id'=>null,'source_document_kind'=>$kind,
                    'default_debit_account_id'=>$debitId,'ap_account_id'=>$ap,'tax_account_id'=>$tax,'default_marking'=>'MARKING','is_active'=>true,
                    'notes'=>'ERP-V5 I05 default global mapping. Review/override per PT/outlet bila diperlukan. Asset default memakai Aset Tetap - Peralatan Kantor.',
                    'created_by_user_id'=>null,'updated_by_user_id'=>null,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }

        $cash = $coa('1-10001');
        $bank = $coa('1-10002');
        if (! $cash || ! $bank) throw new RuntimeException('ERP-V5 I05 membutuhkan COA Kas 1-10001 dan Rekening Bank 1-10002.');
        foreach ($companies as $company) {
            foreach (['CASH','PETTY_CASH','BANK_TRANSFER','GIRO','VIRTUAL_ACCOUNT','OTHER'] as $method) {
                $key = "{$company}:ALL:{$method}";
                if (DB::table('finance_purchasing_payment_mappings')->where('mapping_key',$key)->exists()) continue;
                DB::table('finance_purchasing_payment_mappings')->insert([
                    'id'=>(string)Str::ulid(),'mapping_key'=>$key,'company_code'=>$company,'outlet_id'=>null,'payment_method'=>$method,
                    'cash_account_id'=>in_array($method,['CASH','PETTY_CASH'],true)?$cash:$bank,'is_active'=>true,
                    'notes'=>'ERP-V5 I05 default global payment mapping. Wajib override ke rekening PT/outlet yang benar bila tersedia.',
                    'created_by_user_id'=>null,'updated_by_user_id'=>null,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }
    }

    private function backfillUnpostedAssetInvoiceKind(): void
    {
        if (! Schema::hasTable('pur_invoices') || ! Schema::hasTable('pur_purchase_orders')) return;
        $rows = DB::table('pur_invoices as i')
            ->join('pur_purchase_orders as po', 'po.id', '=', 'i.source_document_id')
            ->where('i.direction', 'INCOMING')
            ->whereRaw("UPPER(COALESCE(po.order_type,'')) = 'ASSET'")
            ->whereRaw("UPPER(COALESCE(i.source_document_kind,'')) = 'GOODS_RECEIPT'")
            ->whereRaw("UPPER(COALESCE(i.journal_status,'NOT_POSTED')) <> 'POSTED'")
            ->get(['i.id','po.id as order_id']);

        foreach ($rows as $row) {
            $update = ['source_document_kind' => 'ASSET_RECEIPT', 'updated_at' => now()];
            if (Schema::hasColumn('pur_invoices', 'ap_order_kind')) $update['ap_order_kind'] = 'PURCHASE_ORDER';
            if (Schema::hasColumn('pur_invoices', 'ap_order_id')) $update['ap_order_id'] = (string) $row->order_id;
            if (Schema::hasColumn('pur_invoices', 'ap_order_subtype')) $update['ap_order_subtype'] = 'ASSET';
            DB::table('pur_invoices')->where('id', $row->id)->update($update);
        }
    }

    private function backfillOutboxFingerprint(): void
    {
        DB::table('pur_finance_posting_outbox')->whereNull('source_fingerprint')->orderBy('id')->chunkById(250, function ($rows): void {
            foreach ($rows as $row) {
                $payload = json_decode((string) $row->payload, true);
                $fingerprint = hash('sha256', json_encode([
                    'event_key'=>(string)$row->event_key,
                    'event_type'=>strtoupper((string)$row->event_type),
                    'aggregate_type'=>strtoupper((string)$row->aggregate_type),
                    'aggregate_id'=>(string)$row->aggregate_id,
                    'payload'=>$this->normalize(is_array($payload) ? $payload : (string) $row->payload),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                DB::table('pur_finance_posting_outbox')->where('id',$row->id)->update(['source_fingerprint'=>$fingerprint,'updated_at'=>now()]);
            }
        }, 'id');
    }

    private function backfillGeneralPostingLinks(): void
    {
        $rows = DB::table('pur_finance_posting_outbox as x')
            ->join('finance_purchasing_postings as p','p.outbox_id','=','x.id')
            ->join('finance_purchasing_posting_journals as pj', function ($j): void {
                $j->on('pj.purchasing_posting_id','=','p.id')->on('pj.posting_version','=','p.posting_version');
            })
            ->join('finance_general_posting_journals as gj','gj.journal_entry_id','=','pj.journal_entry_id')
            ->where('p.status','POSTED')->whereNull('x.general_posting_id')
            ->get(['x.id','gj.general_posting_id']);
        foreach ($rows as $row) DB::table('pur_finance_posting_outbox')->where('id',$row->id)->update(['general_posting_id'=>$row->general_posting_id,'updated_at'=>now()]);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->normalize($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->normalize($item);
        return $value;
    }

    private function columnNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $col) {
            if (($col['name'] ?? null) === $column) return ! empty($col['nullable']);
        }
        return false;
    }

    /** @param array<int,string> $columns */
    private function ensureIndex(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $idx) if (($idx['name'] ?? null) === $name) return;
        Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
    }
};
