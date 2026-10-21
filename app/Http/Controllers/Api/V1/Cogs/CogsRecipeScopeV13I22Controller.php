<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Cogs\V13\ScopedRecipeV13I22Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CogsRecipeScopeV13I22Controller extends CogsBaseController
{
    public function __construct(private readonly ScopedRecipeV13I22Service $service) {}

    public function options(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->options($request));
    }

    private function outlet(Request $request, ?string $id): string
    {
        $outlet=$this->service->authorizedOutlet($request,$id);
        if (! $outlet) throw ValidationException::withMessages(['outlet_id'=>['Pilih outlet sebelum memuat data.']]);
        return $outlet;
    }

    public function coverage(Request $request): JsonResponse
    {
        $data=$request->validate([
            'outlet_id'=>['nullable','string','exists:outlets,id'],
            'q'=>['nullable','string','max:120'],
            'date'=>['nullable','date_format:Y-m-d'],
            'per_page'=>['nullable','integer','min:1','max:50'],
        ]);
        $outlet=$this->outlet($request,$data['outlet_id']??null);
        return ApiResponse::ok($this->service->coverage($outlet,(string)($data['q']??''),
            (string)($data['date']??now()->toDateString()), (int)($data['per_page']??20)));
    }

    public function sourceRecipes(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'q'=>['nullable','string','max:120'],
            'product_id'=>['nullable','string','exists:products,id'],
        ]);
        return ApiResponse::ok(['items'=>$this->service->existingRecipes(
            $filters['product_id']??null, (string)($filters['q']??''))]);
    }

    public function versions(Request $request): JsonResponse
    {
        $data=$request->validate([
            'outlet_id'=>['nullable','string','exists:outlets,id'],
            'product_variant_id'=>['required','string','exists:product_variants,id'],
            'modifier_id'=>['nullable','string','exists:pos_modifiers,id'],
            'modifier_note_id'=>['nullable','string','exists:pos_modifier_notes,id'],
        ]);
        $outlet=$this->outlet($request,$data['outlet_id']??null);
        return ApiResponse::ok(['items'=>$this->service->versions($outlet,$data['product_variant_id'],$data['modifier_id']??null,$data['modifier_note_id']??null)]);
    }

    public function createDraft(Request $request): JsonResponse
    {
        $data=$request->validate([
            'outlet_id'=>['nullable','string','exists:outlets,id'],
            'product_variant_id'=>['required','string','exists:product_variants,id'],
            'modifier_id'=>['nullable','string','exists:pos_modifiers,id'],
            'modifier_note_id'=>['nullable','string','exists:pos_modifier_notes,id'],
            'source_recipe_id'=>['nullable','string','exists:cogs_recipes,id'],
            'yield_quantity'=>['nullable','numeric','gt:0','max:9999999'],
            'notes'=>['nullable','string','max:3000'],
            'items'=>['nullable','array','max:80'],
            'items.*.sku_id'=>['required','string','exists:stk_skus,id'],
            'items.*.quantity'=>['required','numeric','gt:0','max:99999999'],
            'items.*.input_uom_id'=>['required','string','exists:stk_uoms,id'],
            'items.*.waste_percentage'=>['nullable','numeric','min:0','max:100'],
        ]);
        $data['outlet_id']=$this->outlet($request,$data['outlet_id']??null);
        if (! empty($data['source_recipe_id']) && ! empty($data['items'])) {
            throw ValidationException::withMessages(['items'=>['Pilih resep existing ATAU isi bahan manual, bukan keduanya.']]);
        }
        return ApiResponse::ok($this->service->createDraft($data,(string)$request->user()->id),'Draft recipe outlet berhasil dibuat.');
    }

    public function updateDraft(Request $request,string $id): JsonResponse
    {
        $data=$request->validate([
            'outlet_id'=>['nullable','string','exists:outlets,id'],
            'yield_quantity'=>['required','numeric','gt:0','max:9999999'],
            'notes'=>['nullable','string','max:3000'],
            'items'=>['required','array','max:80'],
            'items.*.sku_id'=>['required','string','exists:stk_skus,id'],
            'items.*.quantity'=>['required','numeric','gt:0','max:99999999'],
            'items.*.input_uom_id'=>['required','string','exists:stk_uoms,id'],
            'items.*.waste_percentage'=>['nullable','numeric','min:0','max:100'],
        ]);
        // Verify outlet ownership on every write, not just when listing.
        $this->assertOwned($request,$id,$data['outlet_id']??null);
        return ApiResponse::ok($this->service->updateDraft($id,$data,(string)$request->user()->id));
    }

    public function publish(Request $request,string $id): JsonResponse
    {
        $data=$request->validate([
            'outlet_id'=>['nullable','string','exists:outlets,id'],
            'effective_from'=>['required','date_format:Y-m-d'],
            'effective_to'=>['nullable','date_format:Y-m-d'],
        ]);
        $this->assertOwned($request,$id,$data['outlet_id']??null);
        return ApiResponse::ok($this->service->publish($id,$data['effective_from'],$data['effective_to']??null,
            (string)$request->user()->id),'Recipe outlet berhasil dipublikasikan.');
    }

    private function assertOwned(Request $request,string $id,?string $selectedOutlet): void
    {
        $row=\Illuminate\Support\Facades\DB::table('cogs_v13_i22_recipe_versions')->where('id',$id)->first(['outlet_id']);
        if (! $row) throw ValidationException::withMessages(['id'=>['Recipe tidak ditemukan.']]);
        $authorized=$this->outlet($request,$selectedOutlet ?: $row->outlet_id);
        if ($authorized !== $row->outlet_id) {
            throw ValidationException::withMessages(['outlet_id'=>['Recipe di luar scope outlet.']]);
        }
    }
}
