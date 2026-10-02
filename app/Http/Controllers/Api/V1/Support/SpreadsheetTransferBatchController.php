<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class SpreadsheetTransferBatchController extends Controller
{
    public function __construct(private readonly SpreadsheetTransferBatchService $batches) {}

    public function store(Request $request)
    {
        $operation = strtoupper(trim((string) $request->input('operation', 'IMPORT')));
        $rules = [
            'operation' => ['nullable', 'in:IMPORT,EXPORT,import,export'],
            'module_key' => ['required', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'chunk_size' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'metadata' => ['nullable'],
        ];
        if ($operation === 'IMPORT') $rules['file'] = ['required', 'file', 'mimes:xlsx', 'max:20480'];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) return ApiResponse::error('Batch spreadsheet tidak valid.', 'SPREADSHEET_BATCH_VALIDATION', 422, $validator->errors()->toArray());

        $metadata = $request->input('metadata', []);
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($metadata)) $metadata = [];

        try {
            $batch = $operation === 'EXPORT'
                ? $this->batches->createExportBatch($request->user(), (string) $request->input('module_key'), (int) $request->input('chunk_size', 500), $request->input('idempotency_key'), $metadata)
                : $this->batches->createImportBatch($request->user(), (string) $request->input('module_key'), $request->file('file'), (int) $request->input('chunk_size', 30), $request->input('idempotency_key'), $metadata);
            return ApiResponse::ok($batch, 'Batch spreadsheet siap.', 201);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SPREADSHEET_BATCH_INVALID', 422);
        }
    }

    public function show(Request $request, string $batch)
    {
        try {
            $data = $this->batches->findOwned($batch, $request->user());
            $data['row_results'] = $this->batches->rowResults($batch, $request->user(), (int) $request->query('row_limit', 100));
            return ApiResponse::ok($data);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SPREADSHEET_BATCH_NOT_FOUND', 404);
        }
    }

    public function cancel(Request $request, string $batch)
    {
        try { return ApiResponse::ok($this->batches->requestCancel($batch, $request->user()), 'Permintaan cancel diterima.'); }
        catch (InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(), 'SPREADSHEET_BATCH_NOT_FOUND', 404); }
    }

    public function destroy(Request $request, string $batch)
    {
        try {
            $data = $this->batches->purgeTerminalFiles($batch, $request->user());
            return ApiResponse::ok($data, 'File batch spreadsheet dibersihkan. Audit import tetap disimpan.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'SPREADSHEET_BATCH_DELETE_BLOCKED', 409);
        }
    }
}
