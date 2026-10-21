<?php

namespace App\Services\Cogs\V13;

use App\Models\Cogs\IngredientRecipe;
use App\Models\ProductVariant;
use App\Models\PosModifier;
use App\Models\StockInventory\StockSku;
use App\Services\Cogs\UomConversionGraphService;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * V13 I22 catalog + scoped recipe AUTHORING. The existing POS sales consumption
 * resolver is deliberately not changed. I22 versions are NOT live COGS input
 * until an explicit integration/cutover and reconciliation are implemented.
 */
class ScopedRecipeV13I22Service
{
    private const VERSION_TABLE = 'cogs_v13_i22_recipe_versions';
    private const ITEM_TABLE = 'cogs_v13_i22_recipe_items';

    public function __construct(private readonly UomConversionGraphService $graph) {}

    public function authorizedOutlet(Request $request, ?string $selectedId): ?string
    {
        $locked = OutletScope::isLocked($request);
        $scoped = OutletScope::id($request);
        if (OutletScope::mode($request) === 'NONE') {
            throw ValidationException::withMessages(['outlet_id'=>['Akun belum memiliki scope outlet.']]);
        }
        if ($locked && ! $scoped) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet tidak tersedia pada scope akun.']]);
        }
        if ($locked && $selectedId && $selectedId !== $scoped) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet di luar scope akun.']]);
        }
        if ($scoped && $selectedId && $scoped !== $selectedId && ! OutletScope::isAll($request)) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet di luar scope yang dipilih.']]);
        }
        return $scoped ?: $selectedId;
    }

    public function options(Request $request): array
    {
        $lockedId = OutletScope::isLocked($request) ? OutletScope::id($request) : null;
        if (OutletScope::mode($request) === 'NONE') {
            return ['outlets'=>[], 'skus'=>[], 'uoms'=>[], 'note'=>'Akun belum memiliki scope outlet.'];
        }
        $outlets = DB::table('outlets')->when($lockedId, fn ($q) => $q->where('id', $lockedId))
            ->orderBy('code')->get(['id','code','name']);
        $skus = collect();
        if ($request->boolean('with_skus')) {
            $term = trim((string) $request->query('sku_q', ''));
            $skus = DB::table('stk_skus as s')
                ->leftJoin('stk_uoms as u', 'u.id', '=', 's.base_uom_id')
                ->where('s.is_active', true)
                ->when($term !== '', fn ($q) => $q->where(fn ($sub) => $sub
                    ->where('s.name', 'like', '%'.addcslashes($term,'%_\\').'%')
                    ->orWhere('s.sku_code', 'like', '%'.addcslashes($term,'%_\\').'%')))
                ->orderBy('s.name')->limit(150)
                ->get(['s.id','s.sku_code','s.name','s.base_uom_id','u.name as uom_name', 'u.code as uom_code']);
        }
        $uoms = DB::table('stk_uoms')->where('is_active',true)->orderBy('name')
            ->get(['id','code','name','decimal_places']);
        return ['outlets'=>$outlets, 'skus'=>$skus, 'uoms'=>$uoms, 'note'=>'Publikasi resep tidak otomatis mengubah perhitungan HPP transaksi POS.'];
    }

    private function activeVariants(string $outletId, string $search, int $perPage)
    {
        $q = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('v.outlet_id', $outletId)
            ->where('v.is_active', true)->where('p.is_active', true)
            ->whereNull('v.deleted_at')
            ->where(function ($q) use ($outletId) {
                // Only products offered in the selected outlet. In legacy data,
                // physical variant outlet_id remains canonical if no pivot exists.
                $q->whereNotExists(function ($sq) use ($outletId) {
                    $sq->selectRaw('1')->from('outlet_product as op')
                        ->whereColumn('op.product_id', 'v.product_id')
                        ->where('op.outlet_id', $outletId)->where('op.is_active', false);
                });
            });
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($w) => $w->where('p.name', 'like', $term)
                ->orWhere('v.name', 'like', $term)->orWhere('v.sku', 'like', $term));
        }
        return $q->select(['v.id as variant_id','v.outlet_id','v.product_id','v.name as variant_name',
            'v.sku','p.name as product_name','p.category_id','c.name as category_name'])
            ->orderBy('p.name')->orderBy('v.name')->orderBy('v.id')->paginate($perPage);
    }

    private function modifiersForRows(string $outletId, $variants): array
    {
        $mods = DB::table('pos_modifiers')->where('outlet_id', $outletId)
            ->where('is_active', true)->whereNull('deleted_at')->get(['id','name']);
        if ($mods->isEmpty()) return [];
        $assignments = DB::table('pos_modifier_assignments')->whereIn('modifier_id', $mods->pluck('id'))
            ->get(['modifier_id','assignable_type','assignable_id'])->groupBy('modifier_id');
        $notes = DB::table('pos_modifier_notes')->whereIn('modifier_id', $mods->pluck('id'))
            ->orderBy('sort_order')->get(['id','modifier_id','note'])->groupBy('modifier_id');
        $result=[];
        foreach ($variants as $v) {
            $result[$v->variant_id] = $mods->filter(function ($mod) use ($assignments, $v) {
                $ass = $assignments->get($mod->id, collect());
                if ($ass->isEmpty()) return true;
                return $ass->contains(fn ($a) => ($a->assignable_type === 'product' && $a->assignable_id === $v->product_id)
                    || ($a->assignable_type === 'category' && $a->assignable_id === $v->category_id));
            })->map(fn ($m) => ['id'=>$m->id,'name'=>$m->name,
                'notes'=>($notes->get($m->id,collect()))->map(fn ($n)=>['id'=>$n->id,'name'=>$n->note])->all()])->values()->all();
        }
        return $result;
    }

    public function coverage(string $outletId, string $search, string $date, int $pageSize): array
    {
        $paginator = $this->activeVariants($outletId, $search, $pageSize);
        $variants = collect($paginator->items());
        $ids = $variants->pluck('variant_id')->all();
        if (! $ids) return ['items'=>[], 'pagination'=>$this->pages($paginator), 'summary'=>['on_page'=>0,'uncovered'=>0]];

        $versions = DB::table(self::VERSION_TABLE)->whereIn('product_variant_id', $ids)->get()
            ->groupBy(fn ($v) => $v->product_variant_id.'|'.$v->modifier_key);
        $global = DB::table('cogs_recipe_variant_links as l')
            ->join('cogs_recipes as r', 'r.id','=','l.recipe_id')
            ->whereIn('l.product_variant_id', $ids)
            ->where('r.status','published')->where('r.is_active',true)
            ->whereNull('r.deleted_at')->whereDate('r.effective_from','<=',$date)
            ->where(fn ($q) => $q->whereNull('r.effective_to')->orWhereDate('r.effective_to','>=',$date))
            ->orderByDesc('r.effective_from')->orderByDesc('r.version_no')
            ->get(['l.product_variant_id','r.id','r.version_no'])
            ->groupBy('product_variant_id');
        $modsByVariant = $this->modifiersForRows($outletId, $variants);
        $rows = $variants->map(function ($v) use ($modsByVariant, $versions, $global, $date) {
            $base = $this->scopeState($versions->get($v->variant_id.'|BASE', collect()), $date);
            $legacy = $global->get($v->variant_id)?->first();
            $baseStatus = $base['effective'] ? 'scoped' : ($legacy ? 'legacy_global' : ($base['draft'] ? 'draft' : 'missing'));
            $modifiers = collect($modsByVariant[$v->variant_id] ?? [])->map(function ($mod) use ($v,$versions,$date,$baseStatus) {
                $state = $this->scopeState($versions->get($v->variant_id.'|'.$mod['id'], collect()), $date);
                $mod['status']=$state['effective'] ? 'override' : ($state['draft'] ? 'draft' : 'inherit_base');
                $mod['effective_code']=$state['effective']?->code;
                $mod['draft_id']=$state['draft']?->id;
                $mod['notes'] = collect($mod['notes'])->map(function ($note) use ($v,$versions,$date,$state) {
                    $noteState = $this->scopeState($versions->get($v->variant_id.'|'.$note['id'],collect()),$date);
                    return [...$note, 'status'=>$noteState['effective'] ? 'override' : ($noteState['draft'] ? 'draft' : 'inherit_base'),
                        'effective_code'=>$noteState['effective']?->code, 'draft_id'=>$noteState['draft']?->id];
                })->all();
                return $mod;
            })->all();
            return [
                'variant_id'=>$v->variant_id, 'outlet_id'=>$v->outlet_id, 'product_id'=>$v->product_id,
                'product_name'=>$v->product_name, 'variant_name'=>$v->variant_name,
                'sku'=>$v->sku, 'category_name'=>$v->category_name,
                'status'=>$baseStatus, 'covered'=>$baseStatus === 'scoped' || $baseStatus === 'legacy_global',
                'active_recipe_code'=>$base['effective']?->code,
                'active_recipe_id'=>$base['effective']?->id,
                'legacy_recipe_id'=>$legacy?->id,
                'draft_id'=>$base['draft']?->id,
                'modifiers'=>$modifiers,
            ];
        })->values()->all();
        return [
            'items'=>$rows,
            'pagination'=>$this->pages($paginator),
            'summary'=>['on_page'=>count($rows), 'uncovered'=>collect($rows)->where('covered',false)->count(),
                'legacy_global'=>collect($rows)->where('status','legacy_global')->count(),
                'scoped'=>collect($rows)->where('status','scoped')->count()],
            'date'=>$date,
        ];
    }

    private function scopeState($rows, string $date): array
    {
        $published=$rows->filter(fn ($r) => $r->status === 'published'
            && $r->effective_from <= $date && ($r->effective_to === null || $r->effective_to >= $date))
            ->sortByDesc('version_no')->first();
        return ['effective'=>$published, 'draft'=>$rows->first(fn ($r) => $r->status==='draft')];
    }

    public function existingRecipes(?string $productId, string $search): array
    {
        $q=IngredientRecipe::query()->where('status','published')->where('is_active',true)
            ->where(fn ($items) => $items->whereHas('items')->orWhereHas('parentRecipe.items'));
        if ($productId) $q->where('product_id', $productId);
        if ($search !== '') $q->where(fn ($q) => $q->where('variant_name','like','%'.addcslashes($search,'%_\\').'%')
            ->orWhereHas('product', fn ($p)=>$p->where('name','like','%'.addcslashes($search,'%_\\').'%')));
        return $q->with('product:id,name')->orderByDesc('updated_at')->limit(100)->get()
            ->map(fn ($r) => ['id'=>$r->id,'product_name'=>$r->product?->name,
                'variant_name'=>$r->variant_name,'version_no'=>$r->version_no,'yield_quantity'=>$r->yield_quantity])->all();
    }

    private function assertTarget(string $outletId, string $variantId, ?string $modifierId, ?string $noteId = null): ProductVariant
    {
        $variant = ProductVariant::query()->where('id',$variantId)->where('outlet_id',$outletId)
            ->where('is_active',true)->whereHas('product', fn ($q)=>$q->where('is_active',true))->first();
        if (! $variant) throw ValidationException::withMessages(['product_variant_id'=>['Variant tidak aktif atau di luar outlet.']]);
        $inactivePivot=DB::table('outlet_product')->where('outlet_id',$outletId)
            ->where('product_id',$variant->product_id)->where('is_active',false)->exists();
        if ($inactivePivot) throw ValidationException::withMessages(['product_variant_id'=>['Produk dinonaktifkan pada outlet.']]);
        if ($modifierId) {
            $mod=PosModifier::query()->whereKey($modifierId)->where('outlet_id',$outletId)->where('is_active',true)->first();
            if (! $mod) throw ValidationException::withMessages(['modifier_id'=>['Modifier tidak aktif pada outlet.']]);
            $assignments=DB::table('pos_modifier_assignments')->where('modifier_id',$modifierId)->get();
            if ($assignments->isNotEmpty() && ! $assignments->contains(fn ($a) =>
                ($a->assignable_type==='product' && $a->assignable_id===$variant->product_id)
                || ($a->assignable_type==='category' && $a->assignable_id===$variant->product?->category_id))) {
                throw ValidationException::withMessages(['modifier_id'=>['Modifier tidak berlaku untuk produk ini.']]);
            }
        }
        if ($noteId) {
            if (! $modifierId || ! DB::table('pos_modifier_notes')->where('id',$noteId)
                ->where('modifier_id',$modifierId)->exists()) {
                throw ValidationException::withMessages(['modifier_note_id'=>['Pilihan modifier tidak cocok dengan grup modifier.']]);
            }
        }
        return $variant;
    }

    public function createDraft(array $data, ?string $userId): array
    {
        return DB::transaction(function () use ($data,$userId) {
            $outlet=$data['outlet_id'];$variantId=$data['product_variant_id'];$modifier=$data['modifier_id']??null;
            $note=$data['modifier_note_id']??null;
            $variant=$this->assertTarget($outlet,$variantId,$modifier,$note);
            // Serialize version-number allocation and concurrent drafts on physical variant.
            ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->first();
            $key=$note ?: ($modifier ?: 'BASE');
            $prior=DB::table(self::VERSION_TABLE)->where('product_variant_id',$variantId)->where('modifier_key',$key)->get();
            if ($prior->contains(fn ($v)=>$v->status==='draft')) {
                throw ValidationException::withMessages(['version'=>['Sudah ada draft untuk scope ini. Ubah draft tersebut dahulu.']]);
            }
            $id=(string) Str::ulid();
            $version=((int) $prior->max('version_no'))+1;
            $source=$data['source_recipe_id']??null;
            $sourceItems=[];
            if ($source) {
                $recipe=IngredientRecipe::query()->with(['items', 'parentRecipe.items'])->whereKey($source)
                    ->where('status','published')->where('is_active',true)->first();
                if (! $recipe) throw ValidationException::withMessages(['source_recipe_id'=>['Recipe sumber wajib published dan aktif.']]);
                $effectiveItems=$recipe->items->isNotEmpty() ? $recipe->items : $recipe->parentRecipe?->items;
                if (! $effectiveItems || $effectiveItems->isEmpty()) {
                    throw ValidationException::withMessages(['source_recipe_id'=>['Recipe sumber belum mempunyai bahan yang dapat disalin.']]);
                }
                $sourceItems=$effectiveItems->map(fn ($i)=>[
                    'sku_id'=>(string)$i->sku_id, 'quantity'=>(string)$i->input_quantity,
                    'input_uom_id'=>(string)$i->input_uom_id,'waste_percentage'=>(string)$i->waste_percentage,
                ])->all();
            }
            DB::table(self::VERSION_TABLE)->insert([
                'id'=>$id, 'code'=>'R22-'.strtoupper($id), 'outlet_id'=>$outlet,'product_variant_id'=>$variantId,
                'modifier_id'=>$modifier,'modifier_note_id'=>$note,'modifier_key'=>$key,'version_no'=>$version,'status'=>'draft',
                'source_recipe_id'=>$source,'yield_quantity'=>$data['yield_quantity']??($recipe->yield_quantity??1),
                'notes'=>$data['notes']??null,'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->storeItems($id,$source ? $sourceItems : ($data['items']??[]));
            return $this->version($id);
        }, 3);
    }

    public function updateDraft(string $id, array $data, ?string $userId): array
    {
        return DB::transaction(function () use ($id,$data,$userId) {
            $row=DB::table(self::VERSION_TABLE)->where('id',$id)->lockForUpdate()->first();
            if (! $row || $row->status!=='draft') throw ValidationException::withMessages(['recipe'=>['Hanya draft yang dapat diubah.']]);
            DB::table(self::VERSION_TABLE)->where('id',$id)->update([
                'yield_quantity'=>$data['yield_quantity'],'notes'=>$data['notes']??null,
                'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
            $this->storeItems($id,$data['items']);
            return $this->version($id);
        }, 3);
    }

    private function storeItems(string $id, array $items): void
    {
        if (count($items)>80) throw ValidationException::withMessages(['items'=>['Maksimal 80 bahan per recipe.']]);
        $seen=[];$rows=[];
        foreach ($items as $i=>$item) {
            if (isset($seen[$item['sku_id']])) throw ValidationException::withMessages(['items'=>['SKU tidak boleh berulang dalam resep.']]);
            $seen[$item['sku_id']]=true;
            $sku=StockSku::query()->whereKey($item['sku_id'])->where('is_active',true)->first();
            if (! $sku || ! $sku->base_uom_id) throw ValidationException::withMessages(['items'=>['SKU atau Base UOM tidak aktif.']]);
            $qty=(float)$item['quantity'];$waste=(float)($item['waste_percentage']??0);
            $preview=$this->graph->preview((string)$item['input_uom_id'], (string)$sku->base_uom_id, $qty);
            $base=(float)$preview['result_quantity'];
            if (! is_finite($base) || $base<=0) throw ValidationException::withMessages(['items'=>['Konversi UOM tidak valid.']]);
            if (round($base*(1+$waste/100), 8) <= 0) throw ValidationException::withMessages(['items'=>['Qty konversi terlalu kecil.']]);
            $rows[]=[
                'id'=>(string)Str::ulid(),'recipe_version_id'=>$id,'sku_id'=>$sku->id,
                'input_quantity'=>number_format($qty,8,'.',''),'input_uom_id'=>$item['input_uom_id'],
                'base_uom_id'=>$sku->base_uom_id,
                'consumption_base_quantity'=>number_format($base*(1+$waste/100),8,'.',''),
                'waste_percentage'=>number_format($waste,4,'.',''),
                'conversion_snapshot'=>json_encode(['factor'=>$preview['factor'],'path'=>$preview['path']??[],
                    'conversion_ids'=>$preview['conversion_ids']??[]],JSON_THROW_ON_ERROR),
                'sort_order'=>$i*10+10,'created_at'=>now(),'updated_at'=>now(),
            ];
        }
        DB::table(self::ITEM_TABLE)->where('recipe_version_id',$id)->delete();
        if ($rows) DB::table(self::ITEM_TABLE)->insert($rows);
    }

    public function publish(string $id, string $from, ?string $to, ?string $userId): array
    {
        return DB::transaction(function () use ($id,$from,$to,$userId) {
            $unlocked=DB::table(self::VERSION_TABLE)->where('id',$id)->first();
            if (! $unlocked) throw ValidationException::withMessages(['id'=>['Recipe tidak ditemukan.']]);
            ProductVariant::query()->whereKey($unlocked->product_variant_id)->lockForUpdate()->first();
            $row=DB::table(self::VERSION_TABLE)->where('id',$id)->lockForUpdate()->first();
            if ($row->status !== 'draft') throw ValidationException::withMessages(['status'=>['Recipe sudah dipublikasikan.']]);
            $this->assertTarget($row->outlet_id,$row->product_variant_id,$row->modifier_id,$row->modifier_note_id);
            if ($from < now('Asia/Makassar')->toDateString()) throw ValidationException::withMessages(['effective_from'=>['Tidak boleh backdate; histori transaksi harus dipertahankan.']]);
            if ($to && $to < $from) throw ValidationException::withMessages(['effective_to'=>['Tanggal selesai harus setelah tanggal mulai.']]);
            if ((float)$row->yield_quantity<=0 || ! DB::table(self::ITEM_TABLE)->where('recipe_version_id',$id)->exists()) {
                throw ValidationException::withMessages(['items'=>['Minimal satu bahan dan yield positif wajib sebelum publish.']]);
            }
            $other=DB::table(self::VERSION_TABLE)->where('product_variant_id',$row->product_variant_id)
                ->where('modifier_key',$row->modifier_key)->where('status','published')
                ->where('id','!=',$id)->lockForUpdate()->get();
            foreach ($other as $prev) {
                if ($prev->effective_from < $from && ($prev->effective_to===null || $prev->effective_to >= $from)) {
                    DB::table(self::VERSION_TABLE)->where('id',$prev->id)->update([
                        'effective_to'=>date('Y-m-d',strtotime($from.' -1 day')),'updated_at'=>now(),
                    ]);
                    continue;
                }
                if (($to===null || $prev->effective_from<=$to) && ($prev->effective_to===null || $prev->effective_to >= $from)) {
                    throw ValidationException::withMessages(['effective_from'=>['Periode tumpang tindih dengan versi published.']]);
                }
            }
            DB::table(self::VERSION_TABLE)->where('id',$id)->update(['status'=>'published',
                'effective_from'=>$from,'effective_to'=>$to,'published_by_user_id'=>$userId,
                'published_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            return $this->version($id);
        }, 3);
    }

    public function versions(string $outletId, string $variantId, ?string $modifierId, ?string $noteId = null): array
    {
        $this->assertTarget($outletId,$variantId,$modifierId,$noteId);
        $q=DB::table(self::VERSION_TABLE)->where('outlet_id',$outletId)->where('product_variant_id',$variantId)
            ->where('modifier_key',$noteId ?: ($modifierId ?: 'BASE'))->orderByDesc('version_no');
        return $q->limit(30)->get()->map(fn($row)=>$this->version($row->id))->all();
    }

    public function version(string $id): array
    {
        $row=DB::table(self::VERSION_TABLE)->where('id',$id)->first();
        if (! $row) throw ValidationException::withMessages(['recipe'=>['Recipe tidak ditemukan.']]);
        $items=DB::table(self::ITEM_TABLE.' as i')->join('stk_skus as s','s.id','=','i.sku_id')
            ->join('stk_uoms as u','u.id','=','i.input_uom_id')
            ->where('i.recipe_version_id',$id)->orderBy('i.sort_order')
            ->get(['i.sku_id','s.name as sku_name','i.input_quantity','i.input_uom_id','u.code as input_uom',
                'i.base_uom_id','i.waste_percentage','i.consumption_base_quantity']);
        return [...(array)$row, 'items'=>$items];
    }

    private function pages($paginator): array
    {
        return ['current_page'=>$paginator->currentPage(),'last_page'=>$paginator->lastPage(),
            'per_page'=>$paginator->perPage(),'total'=>$paginator->total()];
    }
}
