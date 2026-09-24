<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrUniformI10XlsxService;
use App\Services\HumanResource\HrUniformInventoryI10Service;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class HrUniformI10Controller extends Controller
{
    public function __construct(
        private readonly HrUniformInventoryI10Service $service,
        private readonly HrUniformI10XlsxService $xlsx,
    ) {}

    public function references()
    {
        return $this->respond(fn () => $this->service->references());
    }

    public function masterIndex(Request $request)
    {
        return $this->respond(fn () => $this->service->masterIndex($request->only(['search', 'company_code', 'item_kind', 'is_active', 'page', 'per_page'])));
    }

    public function masterStore(Request $request)
    {
        $validator = $this->masterValidator($request);
        if ($validator->fails()) return ApiResponse::error('Validasi Data Uniform gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        return $this->respond(fn () => $this->service->createMaster($validator->validated()), 'Data Uniform berhasil ditambahkan.', 201);
    }

    public function masterUpdate(Request $request, string $id)
    {
        $validator = $this->masterValidator($request, true);
        if ($validator->fails()) return ApiResponse::error('Validasi Data Uniform gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        return $this->respond(fn () => $this->service->updateMaster($id, $validator->validated()), 'Data Uniform berhasil diperbarui.');
    }

    public function masterDestroy(string $id)
    {
        return $this->respond(fn () => $this->service->deactivateMaster($id), 'Data Uniform dinonaktifkan.');
    }

    public function stockIndex(Request $request)
    {
        return $this->respond(fn () => $this->service->stockIndex($request->only(['search', 'company_code', 'item_kind', 'is_active', 'low_stock', 'page', 'per_page'])));
    }

    public function inboundIndex(Request $request)
    {
        return $this->respond(fn () => $this->service->inboundIndex($request->only(['search', 'date_from', 'date_to', 'page', 'per_page'])));
    }

    public function inboundShow(string $id)
    {
        return $this->respond(fn () => $this->service->inboundShow($id));
    }

    public function inboundStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'inbound_date' => ['required', 'date_format:Y-m-d'],
            'receiver_name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.uniform_item_id' => ['required', 'string', Rule::exists('HR_uniform_items', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.purchase_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'items.*.squad_charge' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'items.*.company_charge' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $seen = [];
            foreach ((array) $request->input('items', []) as $index => $line) {
                $itemId = trim((string) ($line['uniform_item_id'] ?? ''));
                if ($itemId === '') continue;
                if (isset($seen[$itemId])) $validator->errors()->add("items.{$index}.uniform_item_id", 'Item yang sama tidak boleh diinput dua kali dalam satu transaksi.');
                $seen[$itemId] = true;
            }
        });
        if ($validator->fails()) return ApiResponse::error('Validasi Barang Masuk gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());

        $actor = $request->user()?->id ? (string) $request->user()->id : null;
        return $this->respond(fn () => $this->service->createInbound($validator->validated(), $actor), 'Barang Masuk berhasil diposting.', 201);
    }

    public function recapPreview(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'company_code' => ['nullable', Rule::in(['MDMF', 'BKJB'])],
        ]);
        if ($validator->fails()) return ApiResponse::error('Filter rekap tidak valid.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        return $this->respond(fn () => $this->service->recapPreview($validator->validated()));
    }

    public function recapExport(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'company_code' => ['nullable', Rule::in(['MDMF', 'BKJB'])],
        ]);
        if ($validator->fails()) return ApiResponse::error('Filter export tidak valid.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        try {
            $preview = $this->service->recapPreview($validator->validated());
            $suffix = now()->format('Ymd_His');
            return $this->xlsx->download('REKAP_SERAGAM_ATRIBUT_'.$suffix.'.xlsx', $preview['stock_rows'], $preview['inbound_rows']);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 'UNIFORM_I10_EXPORT_FAILED', 422);
        }
    }

    private function masterValidator(Request $request, bool $update = false)
    {
        return Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:180'],
            'company_code' => ['required', Rule::in(['MDMF', 'BKJB'])],
            'item_kind' => ['required', Rule::in(['UNIFORM', 'ATTRIBUTE'])],
            'size' => ['nullable', 'string', 'max:12', Rule::requiredIf(fn () => strtoupper((string) $request->input('item_kind')) === 'UNIFORM')],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => $update ? ['nullable', 'boolean'] : ['prohibited'],
        ]);
    }

    private function respond(callable $callback, string $message = 'OK', int $status = 200)
    {
        try {
            return ApiResponse::ok($callback(), $message, $status);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 'UNIFORM_I10_RULE', 422);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 'UNIFORM_I10_ERROR', 500);
        }
    }
}
