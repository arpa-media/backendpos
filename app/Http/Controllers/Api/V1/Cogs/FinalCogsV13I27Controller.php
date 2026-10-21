<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Cogs\V13\FinalCogsI27Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FinalCogsV13I27Controller extends CogsBaseController
{
    public function __construct(private readonly FinalCogsI27Service $service) {}

    private function userId(Request $request): string
    {
        $id = (string) ($request->user()?->id ?? '');
        if (!$id) abort(401, 'Login diperlukan.');
        return $id;
    }

    public function index(Request $request): JsonResponse
    {
        $outlet = $this->outletId($request);
        if ($outlet instanceof JsonResponse) return $outlet;
        $v = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:50'],
        ]);
        return ApiResponse::ok($this->service->list($outlet, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 15)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $outlet = $this->outletId($request);
        if ($outlet instanceof JsonResponse) return $outlet;
        $v = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->details($id, $outlet, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 20)));
    }

    public function prepare(Request $request): JsonResponse
    {
        $outlet = $this->outletId($request);
        if ($outlet instanceof JsonResponse) return $outlet;
        $v = $request->validate([
            'variance_id' => ['required', 'ulid', Rule::exists('cogs_stock_variances', 'id')],
            'fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        return ApiResponse::ok($this->service->prepare($outlet, $v['variance_id'], $v['fingerprint'], $this->userId($request)), 'Proposal HPP final disimpan, menunggu approver lain.');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $outlet = $this->outletId($request);
        if ($outlet instanceof JsonResponse) return $outlet;
        $v = $request->validate([
            'fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'notes' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        return ApiResponse::ok($this->service->approve($id, $outlet, $this->userId($request), $v['fingerprint'], $v['notes']), 'Snapshot disetujui. Belum ada posting GL Finance.');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $outlet = $this->outletId($request);
        if ($outlet instanceof JsonResponse) return $outlet;
        $v = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        return ApiResponse::ok($this->service->reject($id, $outlet, $this->userId($request), $v['reason']), 'Proposal ditolak. Data historis tetap dipertahankan.');
    }

    public function audit(Request $request, string $id): JsonResponse
    {
        $outlet = $this->outletId($request);
        if ($outlet instanceof JsonResponse) return $outlet;
        return ApiResponse::ok($this->service->audit($id, $outlet));
    }
}
