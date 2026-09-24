<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Cogs\UomConversion;
use App\Models\StockInventory\StockUom;
use App\Services\Cogs\UomConversionGraphService;
use App\Services\Cogs\UomConversionSpreadsheetService;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UomConversionController extends CogsBaseController
{
    public function __construct(private readonly UomConversionGraphService $graph, private readonly UomConversionSpreadsheetService $spreadsheets)
    {
    }


    public function template(): Response
    {
        return $this->spreadsheets->template();
    }

    public function export(): Response
    {
        return $this->spreadsheets->export();
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240']]);
        return ApiResponse::ok($this->spreadsheets->import($data['file'], $request->user()?->id), 'Import UOM Conversion selesai.');
    }

    public function catalogs(): JsonResponse
    {
        $items = StockUom::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (StockUom $uom) => $this->serializeUom($uom))
            ->values()
            ->all();

        return ApiResponse::ok(['uoms' => $items]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = UomConversion::query()->with(['fromUom', 'toUom']);
        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->where(function ($builder) use ($term): void {
                $builder->where('notes', 'like', "%{$term}%")
                    ->orWhereHas('fromUom', fn ($uom) => $uom
                        ->where('code', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%"))
                    ->orWhereHas('toUom', fn ($uom) => $uom
                        ->where('code', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%"));
            });
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query
            ->orderBy('from_uom_id')
            ->orderBy('to_uom_id')
            ->paginate((int) ($filters['per_page'] ?? 100));

        return ApiResponse::ok([
            'items' => collect($paginator->items())
                ->map(fn (UomConversion $conversion) => $this->serialize($conversion))
                ->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ((bool) ($data['is_active'] ?? true)) {
            $this->graph->assertAcyclic($data['from_uom_id'], $data['to_uom_id']);
        }

        $userId = $request->user()?->id;
        $conversion = UomConversion::query()->create([
            ...$data,
            'conversion_factor' => $this->normalizeFactor($data['conversion_factor']),
            'notes' => $this->nullableText($data['notes'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return ApiResponse::ok(
            $this->serialize($conversion->load(['fromUom', 'toUom'])),
            'UOM Conversion berhasil dibuat.',
            201
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $conversion = UomConversion::query()->find($id);
        if (! $conversion) {
            return ApiResponse::error('UOM Conversion tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $conversion->id);
        if ((bool) ($data['is_active'] ?? true)) {
            $this->graph->assertAcyclic($data['from_uom_id'], $data['to_uom_id'], $conversion->id);
        }

        $conversion->fill([
            ...$data,
            'conversion_factor' => $this->normalizeFactor($data['conversion_factor']),
            'notes' => $this->nullableText($data['notes'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok(
            $this->serialize($conversion->fresh(['fromUom', 'toUom'])),
            'UOM Conversion berhasil diperbarui.'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $conversion = UomConversion::query()->find($id);
        if (! $conversion) {
            return ApiResponse::error('UOM Conversion tidak ditemukan.', 'NOT_FOUND', 404);
        }

        try {
            $conversion->delete();
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'conversion' => ['Konversi sudah digunakan oleh recipe atau transaksi. Nonaktifkan saja agar histori tetap aman.'],
            ]);
        }

        return ApiResponse::ok(null, 'UOM Conversion berhasil dihapus.');
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_uom_id' => ['required', 'string', 'exists:stk_uoms,id'],
            'to_uom_id' => ['required', 'string', 'exists:stk_uoms,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
        ]);

        return ApiResponse::ok($this->graph->preview(
            $data['from_uom_id'],
            $data['to_uom_id'],
            (float) $data['quantity'],
        ));
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'from_uom_id' => ['required', 'string', 'exists:stk_uoms,id'],
            'to_uom_id' => [
                'required',
                'string',
                'different:from_uom_id',
                'exists:stk_uoms,id',
                Rule::unique('stk_uom_conversions', 'to_uom_id')
                    ->where(fn ($query) => $query->where('from_uom_id', $request->input('from_uom_id')))
                    ->ignore($ignoreId),
            ],
            'conversion_factor' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'to_uom_id.unique' => 'Pasangan UOM asal dan base UOM sudah tersedia.',
        ]);
    }

    private function serialize(UomConversion $conversion): array
    {
        return [
            'id' => (string) $conversion->id,
            'from_uom' => $this->serializeUom($conversion->fromUom),
            'to_uom' => $this->serializeUom($conversion->toUom),
            'conversion_factor' => (string) $conversion->conversion_factor,
            'formula' => sprintf(
                '1 %s = %s %s',
                $conversion->fromUom?->symbol ?: $conversion->fromUom?->code,
                $this->trimDecimal((string) $conversion->conversion_factor),
                $conversion->toUom?->symbol ?: $conversion->toUom?->code,
            ),
            'notes' => $conversion->notes,
            'is_active' => (bool) $conversion->is_active,
            'created_at' => $conversion->created_at?->toIso8601String(),
            'updated_at' => $conversion->updated_at?->toIso8601String(),
        ];
    }

    private function serializeUom(?StockUom $uom): ?array
    {
        if (! $uom) {
            return null;
        }

        return [
            'id' => (string) $uom->id,
            'code' => (string) $uom->code,
            'name' => (string) $uom->name,
            'symbol' => (string) $uom->symbol,
            'decimal_places' => (int) $uom->decimal_places,
            'is_active' => (bool) $uom->is_active,
        ];
    }

    private function normalizeFactor(mixed $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }

    private function trimDecimal(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }
}
