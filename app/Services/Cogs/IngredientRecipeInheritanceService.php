<?php

namespace App\Services\Cogs;

use App\Models\Cogs\IngredientRecipe;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IngredientRecipeInheritanceService
{
    public function __construct(private readonly IngredientRecipeService $recipes) {}

    public function generateDrafts(array $targets, ?string $userId, string $strategy = 'auto'): array
    {
        $targets = collect($targets)->take(500);
        $summary = ['requested'=>$targets->count(),'created'=>0,'created_inherit'=>0,'created_direct'=>0,'skipped'=>0,'failed'=>0];
        $items = [];
        foreach ($targets as $target) {
            try {
                $result = DB::transaction(fn () => $this->generateOne((string)($target['product_id']??''), (string)($target['variant_key']??''), $userId, $strategy, $target['parent_recipe_id']??null));
                $bucket = $result['outcome']==='created' ? 'created' : 'skipped';
                $summary[$bucket]++;
                if (($result['recipe_mode'] ?? null) === IngredientRecipe::MODE_INHERIT) $summary['created_inherit']++;
                if (($result['recipe_mode'] ?? null) === IngredientRecipe::MODE_DIRECT) $summary['created_direct']++;
                $items[]=$result;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $items[]=['product_id'=>$target['product_id']??null,'variant_key'=>$target['variant_key']??null,'outcome'=>'failed','message'=>$e->getMessage()];
            }
        }
        return compact('summary','items');
    }

    private function generateOne(string $productId, string $variantKey, ?string $userId, string $strategy, ?string $explicitParent): array
    {
        $variantKey=$this->recipes->normalizeVariantKey($variantKey);
        if ($productId==='' || $variantKey==='') throw ValidationException::withMessages(['target'=>['Product dan variant wajib diisi.']]);
        if (IngredientRecipe::query()->where('product_id',$productId)->where('variant_key',$variantKey)->exists()) {
            return ['product_id'=>$productId,'variant_key'=>$variantKey,'outcome'=>'skipped','message'=>'Target sudah mempunyai recipe.'];
        }
        $physical=ProductVariant::query()->where('product_id',$productId)->get(['name'])->contains(fn($v)=>$this->recipes->normalizeVariantKey((string)$v->name)===$variantKey);
        if (!$physical) throw ValidationException::withMessages(['variant_key'=>['Variant POS tidak ditemukan.']]);
        $parent=$explicitParent ? IngredientRecipe::query()->find($explicitParent) : $this->pickParent($productId,$variantKey,$strategy);
        if ($parent && (string)$parent->product_id!==$productId) throw ValidationException::withMessages(['parent_recipe_id'=>['Parent recipe harus berasal dari produk yang sama.']]);
        $name=ProductVariant::query()->where('product_id',$productId)->get(['name'])->first(fn($v)=>$this->recipes->normalizeVariantKey((string)$v->name)===$variantKey)?->name ?: $variantKey;
        $mode = $parent ? IngredientRecipe::MODE_INHERIT : IngredientRecipe::MODE_DIRECT;
        $maxVersion = (int) IngredientRecipe::withTrashed()->where('product_id',$productId)->where('variant_key',$variantKey)->max('version_no');
        $draft=IngredientRecipe::query()->create([
            'product_id'=>$productId,'variant_key'=>$variantKey,'variant_name'=>(string)$name,'recipe_mode'=>$mode,
            'parent_recipe_id'=>$parent ? (string)$parent->id : null,'version_no'=>max(1,$maxVersion+1),'status'=>IngredientRecipe::STATUS_DRAFT,
            'effective_from'=>null,'effective_to'=>null,'yield_quantity'=>$parent?->yield_quantity ?: 1,
            'notes'=>$parent ? 'Generated as inherited draft from '.$parent->variant_name : 'Generated as direct empty draft because no parent recipe exists for this product.',
            'is_active'=>true,'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
        ]);
        $this->recipes->syncVariantLinks($draft);
        return [
            'product_id'=>$productId,'variant_key'=>$variantKey,'recipe_id'=>(string)$draft->id,
            'parent_recipe_id'=>$parent ? (string)$parent->id : null,'recipe_mode'=>$mode,'outcome'=>'created',
            'message'=>$parent ? 'Inherited Draft berhasil dibuat.' : 'Direct Draft kosong berhasil dibuat karena produk belum mempunyai recipe sumber.'
        ];
    }

    private function pickParent(string $productId,string $variantKey,string $strategy): ?IngredientRecipe
    {
        $q=IngredientRecipe::query()->where('product_id',$productId)->where('variant_key','!=',$variantKey)->where('is_active',true);
        $all=$q->orderByRaw("FIELD(status,'published','draft','archived')")->orderByDesc('version_no')->get();
        if ($strategy==='latest') return $all->first();
        foreach (['-','NORMAL','REGULAR','DEFAULT','ORIGINAL'] as $key) {
            $found=$all->first(fn($r)=>$this->recipes->normalizeVariantKey((string)$r->variant_key)===$key);
            if ($found) return $found;
        }
        return $all->first();
    }
}
