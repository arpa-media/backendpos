<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['wh_v4_finance_coa','wh_v4_finance_posting_templates','wh_v4_finance_posting_template_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v4 Iterasi 05 membutuhkan fondasi Finance Iterasi 04: {$table}.");
            }
        }

        $this->coa('2290', 'Production Cost Clearing', 'LIABILITY', 'CREDIT', 2290,
            'Clearing/accrual labor dan overhead yang dikapitalisasi ke WIP produksi.');

        $this->template('PURCHASE_ORDER_ADJUST_UP', 'PO Actual Cost Adjustment Up', 'PURCHASE_ORDER',
            'Menaikkan PO clearing dan hutang ketika actual supplier total lebih besar dari nilai approval.', [
                ['1240','DEBIT','adjustment',1,'Tambah PO clearing {{reference_no}}'],
                ['2100','CREDIT','adjustment',1,'Tambah hutang supplier {{reference_no}}'],
            ]);
        $this->template('PURCHASE_ORDER_ADJUST_DOWN', 'PO Actual Cost Adjustment Down', 'PURCHASE_ORDER',
            'Menurunkan hutang dan PO clearing ketika actual supplier total lebih kecil dari nilai approval.', [
                ['2100','DEBIT','adjustment',1,'Kurangi hutang supplier {{reference_no}}'],
                ['1240','CREDIT','adjustment',1,'Kurangi PO clearing {{reference_no}}'],
            ]);
        $this->template('PRODUCTION_COST_ABSORPTION', 'Production Labor & Overhead Capitalization', 'PRODUCTION_COST',
            'Mengkapitalisasi labor dan overhead yang dikonfigurasi pada Production Order ke WIP.', [
                ['1230','DEBIT','cost',1,'Kapitalisasi biaya produksi {{reference_no}}'],
                ['2290','CREDIT','cost',1,'Production cost clearing {{reference_no}}'],
            ]);
        $this->template('PRODUCTION_VARIANCE_LOSS', 'Production Variance Loss', 'PRODUCTION_VARIANCE',
            'Menutup sisa WIP ketika nilai finished goods lebih kecil dari cost pool.', [
                ['5200','DEBIT','variance',1,'Selisih produksi loss {{reference_no}}'],
                ['1230','CREDIT','variance',1,'Tutup WIP {{reference_no}}'],
            ]);
        $this->template('PRODUCTION_VARIANCE_GAIN', 'Production Variance Gain', 'PRODUCTION_VARIANCE',
            'Menutup WIP negatif ketika nilai finished goods lebih besar dari cost pool.', [
                ['1230','DEBIT','variance',1,'Tutup WIP negatif {{reference_no}}'],
                ['5200','CREDIT','variance',1,'Selisih produksi gain {{reference_no}}'],
            ]);
    }

    public function down(): void
    {
        // Additive accounting seed: jangan hapus template/COA yang mungkin sudah dipakai jurnal.
    }

    private function coa(string $code, string $name, string $type, string $normal, int $sort, string $description): void
    {
        if (DB::table('wh_v4_finance_coa')->where('code',$code)->exists()) return;
        DB::table('wh_v4_finance_coa')->insert([
            'id'=>(string)Str::ulid(),'code'=>$code,'name'=>$name,'account_type'=>$type,'normal_balance'=>$normal,
            'is_header'=>false,'is_postable'=>true,'is_system'=>true,'is_active'=>true,'sort_order'=>$sort,
            'description'=>$description,'created_by_user_id'=>null,'updated_by_user_id'=>null,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function template(string $code, string $name, string $sourceType, string $description, array $lines): void
    {
        $existing = DB::table('wh_v4_finance_posting_templates')->where('code',$code)->first();
        $templateId = $existing?->id ?: (string)Str::ulid();
        if (! $existing) {
            DB::table('wh_v4_finance_posting_templates')->insert([
                'id'=>$templateId,'code'=>$code,'name'=>$name,'source_type'=>$sourceType,'description'=>$description,
                'is_system'=>true,'is_active'=>true,'created_by_user_id'=>null,'updated_by_user_id'=>null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        if (DB::table('wh_v4_finance_posting_template_lines')->where('template_id',$templateId)->exists()) return;
        foreach ($lines as $i => [$accountCode,$side,$amountKey,$multiplier,$memo]) {
            $accountId = DB::table('wh_v4_finance_coa')->where('code',$accountCode)->value('id');
            if (! $accountId) throw new RuntimeException("COA {$accountCode} tidak ditemukan untuk template {$code}.");
            DB::table('wh_v4_finance_posting_template_lines')->insert([
                'id'=>(string)Str::ulid(),'template_id'=>$templateId,'sort_order'=>$i+1,'account_id'=>$accountId,
                'side'=>$side,'amount_key'=>$amountKey,'multiplier'=>$multiplier,'memo_template'=>$memo,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    }
};
