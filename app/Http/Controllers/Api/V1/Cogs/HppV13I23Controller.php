<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Cogs\V13\HppI23ReadService;
use App\Services\Cogs\V13\ScopedRecipeV13I22Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class HppV13I23Controller extends CogsBaseController
{
    public function __construct(
        private readonly HppI23ReadService $hpp,
        private readonly ScopedRecipeV13I22Service $scope,
    ) {}

    private function selectedOutlet(Request $request, ?string $outlet): string
    {
        $id=$this->scope->authorizedOutlet($request,$outlet);
        if (! $id) throw ValidationException::withMessages(['outlet_id'=>['Pilih outlet terlebih dahulu.']]);
        return $id;
    }

    /** Current stock HPP preview. Does not imply a historical valuation. */
    public function overview(Request $request): JsonResponse
    {
        $data=$request->validate([
            'outlet_id'=>['nullable','string','exists:outlets,id'],
            'q'=>['nullable','string','max:120'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','min:1','max:30'],
        ]);
        $id=$this->selectedOutlet($request,$data['outlet_id']??null);
        $this->hpp->prerequisites();
        return ApiResponse::ok($this->hpp->overview($id,trim((string)($data['q']??'')),
            (int)($data['page']??1), (int)($data['per_page']??15), now('Asia/Makassar')->toDateString()));
    }

    public function detail(Request $request): JsonResponse
    {
        $data=$request->validate([
            'outlet_id'=>['nullable','string','exists:outlets,id'],
            'product_variant_id'=>['required','string','exists:product_variants,id'],
            'modifier_id'=>['nullable','string','exists:pos_modifiers,id'],
            'modifier_note_id'=>['nullable','string','exists:pos_modifier_notes,id'],
        ]);
        $id=$this->selectedOutlet($request,$data['outlet_id']??null);
        $this->hpp->prerequisites();
        return ApiResponse::ok($this->hpp->detail($id,$data['product_variant_id'],
            $data['modifier_id']??null,$data['modifier_note_id']??null, now('Asia/Makassar')->toDateString()));
    }
}
