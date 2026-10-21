<?php

namespace App\Services\Cogs\V13;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Read-only HPP estimator using I22 scoped snapshots and current outlet stock.
 * Does not modify live POS consumption resolver, COGS item sold, or GL.
 */
final class HppI23ReadService
{
    private const SCOPED = 'cogs_v13_i22_recipe_versions';
    private const LINES = 'cogs_v13_i22_recipe_items';

    public function __construct(private readonly OutletTransferHppPriceI07Service $transferPrices) {}

    public function prerequisites(): void
    {
        foreach ([self::SCOPED,self::LINES,'cogs_recipes','cogs_recipe_variant_links',
            'cogs_recipe_items','stk_inventory_balances','stk_skus','stk_uoms',
            'product_variants','products','outlet_product'] as $table) {
            if (! Schema::hasTable($table)) {
                throw ValidationException::withMessages(['schema' => ["Tabel {$table} belum tersedia. Hubungi administrator untuk memeriksa migrasi dan struktur database HPP/COGS."]]);
            }
        }
    }

    public function baseQuery(string $outletId)
    {
        return DB::table('product_variants as v')
            ->join('products as p','p.id','=','v.product_id')
            ->where('v.outlet_id',$outletId)
            ->where('v.is_active',true)->where('p.is_active',true)
            ->whereNull('v.deleted_at')
            ->whereNotExists(function ($q) use ($outletId) {
                $q->selectRaw('1')->from('outlet_product as op')
                    ->whereColumn('op.product_id','v.product_id')
                    ->where('op.outlet_id',$outletId)->where('op.is_active',false);
            });
    }

    public function overview(string $outlet, string $search, int $page, int $pageSize, string $date): array
    {
        $q=$this->baseQuery($outlet);
        if ($search !== '') {
            $like='%'.addcslashes($search,'%_\\').'%';
            $q->where(fn ($w) => $w->where('p.name','like',$like)
                ->orWhere('v.name','like',$like)->orWhere('v.sku','like',$like));
        }
        $p=$q->select(['v.id as variant_id','v.product_id','v.name as variant_name',
            'v.sku as variant_sku','p.name as product_name'])
            ->orderBy('p.name')->orderBy('v.name')->orderBy('v.id')
            ->paginate($pageSize,['*'],'page',$page);
        $variants=collect($p->items());
        $ids=$variants->pluck('variant_id')->all();
        $recipes=$this->batchRecipes($ids,$outlet,$date);
        $selected=[];
        foreach ($variants as $v) {
            $selected[$v->variant_id]=HppI23Policy::pick($recipes['scoped'][$v->variant_id]??[],null,null,
                $recipes['legacy'][$v->variant_id]??null);
        }
        $costs=$this->batchCosts($outlet,$this->neededSkuIds($selected,$recipes),$date);
        $items=$variants->map(fn ($v) => $this->calculate($v,$selected[$v->variant_id],$recipes,$costs,false))
            ->values()->all();
        return ['items'=>$items,'pagination'=>['current_page'=>$p->currentPage(),
            'last_page'=>$p->lastPage(),'total'=>$p->total(),'per_page'=>$p->perPage()],
            'summary'=>['shown'=>count($items),'estimated'=>count(array_filter($items,fn($r)=>$r['status']==='estimated')),
                'needs_review'=>count(array_filter($items,fn($r)=>$r['status']!=='estimated'))],
            'valuation_basis'=>'warehouse_transfer_price_with_explicit_direct_purchase_fallback','as_of_business_date'=>$date,
            'generated_at'=>now()->toIso8601String(),'disclaimer'=>'Estimasi HPP menggunakan master harga transfer Warehouse ke Outlet untuk outlet dengan chain supply aktif. Tanpa chain supply, fallback stok outlet diberi label eksplisit. Bukan COGS historis atau jurnal Finance.'];
    }

    public function detail(string $outlet,string $variantId,?string $modifierId,?string $noteId,string $date): array
    {
        $v=$this->baseQuery($outlet)->where('v.id',$variantId)
            ->first(['v.id as variant_id','v.product_id','v.name as variant_name',
                'v.sku as variant_sku','p.name as product_name']);
        if (! $v) throw ValidationException::withMessages(['product_variant_id'=>['Variant tidak aktif atau di luar outlet.']]);
        $mods=$this->modifiers($outlet,$v);
        if ($modifierId) {
            $found=collect($mods)->firstWhere('id',$modifierId);
            if (! $found) throw ValidationException::withMessages(['modifier_id'=>['Modifier tidak berlaku pada variant ini.']]);
            if ($noteId && ! collect($found['notes'])->contains('id',$noteId)) {
                throw ValidationException::withMessages(['modifier_note_id'=>['Pilihan modifier tidak berlaku.']]);
            }
        } elseif ($noteId) {
            throw ValidationException::withMessages(['modifier_note_id'=>['Modifier wajib dipilih sebelum pilihan.']]);
        }
        $recipes=$this->batchRecipes([$variantId],$outlet,$date);
        $selected=HppI23Policy::pick($recipes['scoped'][$variantId]??[],$modifierId,$noteId,
            $recipes['legacy'][$variantId]??null);
        $costs=$this->batchCosts($outlet,$this->neededSkuIds([$selected],$recipes),$date);
        return [...$this->calculate($v,$selected,$recipes,$costs,true),
            'modifiers'=>$mods,'selected_modifier_id'=>$modifierId,'selected_note_id'=>$noteId,
            'as_of_business_date'=>$date,'generated_at'=>now()->toIso8601String(),
            'valuation_basis'=>'warehouse_transfer_price_with_explicit_direct_purchase_fallback',
            'disclaimer'=>'Simulasi harga transfer aktif Warehouse → Outlet. Harga master bersifat saat ini (bukan snapshot saat DO/GR); histori HPP Item Sold dan jurnal Finance tidak berubah.'];
    }

    private function batchRecipes(array $variantIds,string $outlet,string $date): array
    {
        if (! $variantIds) return ['scoped'=>[],'legacy'=>[],'items'=>[],'legacy_items'=>[]];
        $scoped=[];
        $rows=DB::table(self::SCOPED)->where('outlet_id',$outlet)
            ->whereIn('product_variant_id',$variantIds)->where('status','published')
            ->where('effective_from','<=',$date)
            ->where(fn($q)=>$q->whereNull('effective_to')->orWhere('effective_to','>=',$date))
            ->orderByDesc('effective_from')->orderByDesc('version_no')->orderByDesc('id')->get();
        foreach ($rows as $r) {
            if (! isset($scoped[$r->product_variant_id][$r->modifier_key])) {
                $scoped[$r->product_variant_id][$r->modifier_key]=$r;
            }
        }
        $legacy=[];
        $old=DB::table('cogs_recipe_variant_links as l')
            ->join('cogs_recipes as r','r.id','=','l.recipe_id')
            ->whereIn('l.product_variant_id',$variantIds)
            ->where('r.status','published')->where('r.is_active',true)
            ->whereNull('r.deleted_at')->where('r.effective_from','<=',$date)
            ->where(fn($q)=>$q->whereNull('r.effective_to')->orWhere('r.effective_to','>=',$date))
            ->orderByDesc('r.effective_from')->orderByDesc('r.version_no')->orderByDesc('r.id')
            ->get(['l.product_variant_id','r.id','r.parent_recipe_id','r.recipe_mode',
                'r.yield_quantity','r.version_no','r.variant_name']);
        foreach ($old as $r) if (! isset($legacy[$r->product_variant_id])) $legacy[$r->product_variant_id]=$r;
        $scopedIds=[];
        foreach ($scoped as $byKey) foreach ($byKey as $r) $scopedIds[$r->id]=true;
        $scopedItems=[];
        if ($scopedIds) {
            foreach (DB::table(self::LINES)->whereIn('recipe_version_id',array_keys($scopedIds))
                ->orderBy('sort_order')->get() as $line) $scopedItems[$line->recipe_version_id][]=$line;
        }
        $legacyIds=[];
        foreach ($legacy as $r) {
            $legacyIds[$this->legacyItemsId($r)]=true;
        }
        $legacyItems=[];
        if ($legacyIds) {
            foreach (DB::table('cogs_recipe_items')->whereIn('recipe_id',array_keys($legacyIds))
                ->orderBy('sort_order')->get() as $line) $legacyItems[$line->recipe_id][]=$line;
        }
        return ['scoped'=>$scoped,'legacy'=>$legacy,'items'=>$scopedItems,'legacy_items'=>$legacyItems];
    }

    private function legacyItemsId(object $recipe): string
    {
        return (string)(($recipe->recipe_mode==='inherit' && $recipe->parent_recipe_id)
            ? $recipe->parent_recipe_id : $recipe->id);
    }

    private function neededSkuIds(array $selected,array $recipes): array
    {
        $ids=[];
        foreach ($selected as $one) {
            if (! $one) continue;
            $r=$one['row'];
            $lines=$one['kind']==='scoped' ? ($recipes['items'][$r->id]??[])
                : ($recipes['legacy_items'][$this->legacyItemsId($r)]??[]);
            foreach ($lines as $line) $ids[$line->sku_id]=true;
        }
        return array_keys($ids);
    }

    private function calculate(object $v,?array $selected,array $recipes,array $costs,bool $detail): array
    {
        $base=['variant_id'=>$v->variant_id,'variant_sku'=>$v->variant_sku,
            'product_name'=>$v->product_name,'variant_name'=>$v->variant_name,
            'source'=>'none','recipe_code'=>null,'recipe_id'=>null,'yield_quantity'=>null,
            'hpp_per_unit'=>null,'priced_subtotal'=>0,'unpriced_count'=>0,
            'no_stock_count'=>0,'invalid_count'=>0,'item_count'=>0,'status'=>'missing_recipe'];
        if (! $selected) return $detail ? [...$base,'lines'=>[]] : $base;
        $r=$selected['row'];$isScoped=$selected['kind']==='scoped';
        $lines=$isScoped ? ($recipes['items'][$r->id]??[])
            : ($recipes['legacy_items'][$this->legacyItemsId($r)]??[]);
        $yield=(float)$r->yield_quantity;
        $subtotal=0;$badCost=0;$invalid=0;$noStock=0;$output=[];
        foreach ($lines as $line) {
            $cost=$costs[$line->sku_id]??null;
            $qty=(float)$line->consumption_base_quantity;
            $valid=$qty>0 && $cost && (string)$cost->base_uom_id===(string)$line->base_uom_id;
            $available=$valid && (float)($cost->on_hand_qty??0)>0;
            // Price is independent of current physical stock. Never interpret missing
            // warehouse transfer price as the cheaper Warehouse acquisition cost.
            $price=$valid && (float)($cost->pricing_unit_cost??0)>0 ? (float)$cost->pricing_unit_cost : null;
            $lineAmount=($valid && $price!==null) ? $qty*$price : null;
            $linePerUnit=$lineAmount!==null && $yield>0 ? $lineAmount/$yield : null;
            if (! $valid) $invalid++;
            elseif ($price===null) $badCost++;
            if ($valid && ! $available) $noStock++;
            if ($lineAmount!==null) $subtotal += $lineAmount;
            if ($detail) $output[]=[
                'sku_id'=>$line->sku_id,'sku_code'=>$cost->sku_code??null,
                'sku_name'=>$cost->name??null,'base_uom'=>$cost->uom_code??null,
                'qty_per_batch'=>round($qty,8), 'qty_per_unit'=>$yield>0 ? round($qty/$yield,8) : null,
                'current_on_hand'=>$cost?->on_hand_qty===null?null:(float)$cost->on_hand_qty,
                // Keep avg_unit_cost for existing clients; it now means the unit cost
                // used in THIS estimate, and price_source identifies its origin.
                'avg_unit_cost'=>$price, 'line_cost'=>$linePerUnit===null?null:round($linePerUnit,4),
                'batch_line_cost'=>$lineAmount===null?null:round($lineAmount,4),
                'outlet_stock_average_unit_cost'=>$cost?->average_unit_cost===null?null:(float)$cost->average_unit_cost,
                'price_source'=>$cost->price_source??'unavailable',
                'price_issue'=>$cost->price_issue??null,
                'warehouse_id'=>$cost->warehouse_id??null,
                'warehouse_code'=>$cost->warehouse_code??null,
                'price_policy_id'=>$cost->price_policy_id??null,
                'price_uom_code'=>$cost->price_uom_code??null,
                'price_conversion_factor'=>$cost->price_conversion_factor??null,
                'master_unit_price'=>$cost->master_unit_price??null,
                'status'=>!$valid?'invalid_sku_or_uom':($price===null?'missing_cost':(!$available?'no_stock':'priced')),
            ];
        }
        $status=HppI23Policy::status(true,count($lines),$badCost,$invalid+($yield>0?0:1),$noStock);
        // Valid pricing can be estimated even when physical stock is currently empty.
        $allPriced=$badCost===0 && $invalid===0 && count($lines)>0 && $yield>0;
        return [...$base,'source'=>$isScoped ? ($selected['key']==='BASE'?'scoped_base':'modifier_override'):'legacy_global',
            'recipe_code'=>$isScoped?$r->code:null,'recipe_id'=>$r->id,'yield_quantity'=>$yield,
            'hpp_per_unit'=>$allPriced ? round($subtotal/$yield,4):null,
            'priced_subtotal'=>round($subtotal,4),'unpriced_count'=>$badCost,
            'no_stock_count'=>$noStock,'invalid_count'=>$invalid,'item_count'=>count($lines),
            'status'=>$status, ...($detail?['lines'=>$output]:[])];
    }

    private function batchCosts(string $outlet,array $skuIds,string $date): array
    {
        if (! $skuIds) return [];
        $rows=DB::table('stk_skus as s')->leftJoin('stk_uoms as u','u.id','=','s.base_uom_id')
            ->leftJoin('stk_inventory_balances as b',function ($join) use($outlet) {
                $join->on('b.sku_id','=','s.id')->where('b.outlet_id','=',$outlet);
            })
            ->whereIn('s.id',$skuIds)
            ->get(['s.id','s.sku_code','s.name','s.base_uom_id','u.code as uom_code',
                'b.on_hand_qty','b.average_unit_cost','b.inventory_value']);
        $transfer=$this->transferPrices->forOutlet($outlet,$skuIds,$date);
        $result=[];
        foreach ($rows as $row) {
            $skuId=(string)$row->id;
            $selection=$transfer[$skuId]??null;
            if (!$selection) {
                // No active chain supply: allow direct purchases only as a visible,
                // explicit fallback; never silently call this Warehouse transfer pricing.
                $average=(float)($row->average_unit_cost??0);
                $selection=['pricing_unit_cost'=>$average>0?$average:null,
                    'price_source'=>'outlet_inventory_average_fallback',
                    'price_issue'=>$average>0?null:'missing_outlet_cost',
                    'warehouse_id'=>null,'warehouse_code'=>null,'price_policy_id'=>null,'price_uom_code'=>$row->uom_code,
                    'master_unit_price'=>null,'price_conversion_factor'=>1];
            }
            $result[$skuId]=(object)array_merge((array)$row,$selection);
        }
        return $result;
    }

    public function modifiers(string $outlet,object $v): array
    {
        $mods=DB::table('pos_modifiers')->where('outlet_id',$outlet)
            ->where('is_active',true)->whereNull('deleted_at')
            ->orderBy('name')->get(['id','name']);
        if ($mods->isEmpty()) return [];
        $assignments=DB::table('pos_modifier_assignments')->whereIn('modifier_id',$mods->pluck('id'))
            ->get(['modifier_id','assignable_type','assignable_id'])->groupBy('modifier_id');
        $notes=DB::table('pos_modifier_notes')->whereIn('modifier_id',$mods->pluck('id'))
            ->orderBy('sort_order')->get(['id','modifier_id','note'])->groupBy('modifier_id');
        $categoryId=DB::table('products')->where('id',$v->product_id)->value('category_id');
        return $mods->filter(function ($m) use ($assignments,$v,$categoryId) {
            $a=$assignments->get($m->id,collect());
            return $a->isEmpty() || $a->contains(fn ($x)=>
                ($x->assignable_type==='product' && $x->assignable_id===$v->product_id)
                ||($x->assignable_type==='category' && $x->assignable_id===$categoryId));
        })->map(fn($m)=>['id'=>$m->id,'name'=>$m->name,
            'notes'=>$notes->get($m->id,collect())->map(fn($n)=>['id'=>$n->id,'name'=>$n->note])->values()->all()])
            ->values()->all();
    }
}
