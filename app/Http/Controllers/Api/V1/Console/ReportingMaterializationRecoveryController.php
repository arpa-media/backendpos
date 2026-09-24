<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportingMaterializationRecoveryController extends Controller
{
    public function __construct(private readonly ReportingMaterializationOrchestrator $orchestrator) {}

    public function recover(Request $request): JsonResponse
    {
        $payload = $request->validate($this->rules());
        try {
            $result = $this->orchestrator->requestCoverageRecovery(
                $payload,
                (string) ($request->user()?->getAuthIdentifier() ?? '')
            );
            return response()->json([
                'data' => $result,
                'message' => ($result['state'] ?? null) === 'ready'
                    ? 'Coverage report sudah siap.'
                    : 'Override materialisasi diterima. Coverage yang kurang diprioritaskan tanpa me-rebuild data yang sudah siap.',
            ], ($result['state'] ?? null) === 'ready' ? 200 : 202);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function status(Request $request): JsonResponse
    {
        $payload = $request->validate($this->rules());
        try {
            return response()->json(['data' => $this->orchestrator->recoveryStatus($payload)]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function rules(): array
    {
        return [
            'pipeline' => ['required', 'in:daily,hourly'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'outlet_ids' => ['required', 'array', 'min:1', 'max:50'],
            'outlet_ids.*' => ['required', 'string', 'max:40'],
        ];
    }
}
