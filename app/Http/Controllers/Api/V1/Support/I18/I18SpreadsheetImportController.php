<?php

namespace App\Http\Controllers\Api\V1\Support\I18;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Spreadsheet\I18\I18SpreadsheetImportRegistry;
use App\Services\Spreadsheet\SpreadsheetChunkImportService;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class I18SpreadsheetImportController extends Controller
{
    public function __construct(
        private readonly SpreadsheetChunkImportService $chunks,
        private readonly I18SpreadsheetImportRegistry $registry,
    ) {}

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'batch_id' => ['required','ulid'],
            'offset' => ['nullable','integer','min:0'],
            'chunk_size' => ['nullable','integer','min:1','max:100'],
        ]);
        try {
            $module = trim((string) $request->route('spreadsheet_module', ''));
            if ($module === '') throw new InvalidArgumentException('spreadsheet_module endpoint I18 belum dikonfigurasi.');
            $adapter = $this->registry->get($module);
            $routeContext = [
                'warehouse_id' => trim((string) $request->attributes->get('warehouse_scope_id', '')) ?: null,
            ];
            $result = $this->chunks->process(
                $request->user(),
                (string) $data['batch_id'],
                (int) ($data['offset'] ?? 0),
                $adapter->aliases(),
                $adapter->requiredHeaders(),
                fn (array $row, array $context): array => $adapter->handle($row, [...$context, ...$routeContext]),
                (int) ($data['chunk_size'] ?? 30),
                $adapter->moduleKey(),
            );
            return ApiResponse::ok($result, 'Chunk import spreadsheet I18 selesai.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'I18_SPREADSHEET_IMPORT_INVALID', 422);
        }
    }
}
