<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CogsV13I22RecipeAuditCommand extends Command
{
    protected $signature = 'cogs:v13-i22:check {--outlet= : Outlet code} {--date= : Business date YYYY-MM-DD}';
    protected $description = 'Read-only scoped Recipe V13 I22 consistency check (no stock/COGS writes).';

    public function handle(): int
    {
        if (! Schema::hasTable('cogs_v13_i22_recipe_versions') || ! Schema::hasTable('cogs_v13_i22_recipe_items')) {
            $this->error('Migration I22 belum terpasang.');
            return self::FAILURE;
        }
        $date=(string) ($this->option('date') ?: now('Asia/Makassar')->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
            $this->error('Tanggal harus YYYY-MM-DD.'); return self::FAILURE;
        }
        $code=$this->option('outlet');
        $outletId=null;
        if ($code) {
            $outletId=DB::table('outlets')->where('code',$code)->value('id');
            if (! $outletId) { $this->error('Outlet tidak ditemukan: '.$code); return self::FAILURE; }
        }
        $q=DB::table('cogs_v13_i22_recipe_versions as v')
            ->when($outletId,fn ($q)=>$q->where('v.outlet_id',$outletId));
        $count=(clone $q)->count();
        $published=(clone $q)->where('v.status','published')->count();
        $draft=(clone $q)->where('v.status','draft')->count();
        $empty=(clone $q)->where('v.status','published')->whereNotExists(fn ($s)=>$s
            ->selectRaw('1')->from('cogs_v13_i22_recipe_items as i')->whereColumn('i.recipe_version_id','v.id'))->count();
        $badScope=(clone $q)->join('product_variants as pv','pv.id','=','v.product_variant_id')
            ->whereColumn('pv.outlet_id','!=','v.outlet_id')->count();
        $expired=(clone $q)->where('v.status','published')->whereNotNull('v.effective_to')
            ->where('v.effective_to','<',$date)->count();
        $this->table(['Pemeriksaan','Jumlah'], [
            ['Versi (semua)', $count],['Draft', $draft],['Published', $published],
            ['Published tanpa bahan (BLOCKER)', $empty],['Variant di luar outlet (BLOCKER)', $badScope],
            ['Published expired pada '.$date, $expired],
        ]);
        if ($empty || $badScope) {
            $this->error('BLOCKED: periksa data mapping sebelum integrasi COGS.');
            return self::FAILURE;
        }
        $this->info('PASS: pemeriksaan metadata I22. Belum membuktikan integrasi COGS sales.');
        return self::SUCCESS;
    }
}
