<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\InvoiceIndexRequest;
use App\Http\Requests\Api\V1\Purchasing\IssueInvoiceRequest;
use App\Http\Requests\Api\V1\Purchasing\RecordInvoicePaymentRequest;
use App\Http\Requests\Api\V1\Purchasing\StoreInvoiceRequest;
use App\Http\Requests\Api\V1\Purchasing\UpdateInvoiceRequest;
use App\Services\Purchasing\InvoiceWorkflowService;
use App\Services\Purchasing\PurchasingModuleAccessService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use App\Services\Purchasing\WarehouseStockRequestLiabilityOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceWorkflowController extends Controller
{
    public function __construct(
        private readonly InvoiceWorkflowService $service,
        private readonly PurchasingModuleRegistry $registry,
        private readonly PurchasingModuleAccessService $access,
        private readonly WarehouseStockRequestLiabilityOwnershipService $liabilityOwnership,
    ) {}

    public function catalogs(Request $request): JsonResponse
    {
        $direction = $this->direction($request);
        $data = $this->service->catalogs($direction);
        // Iterasi 08: invoice lives inside consolidated AP/AR workspace.
        $key = $direction === 'incoming' ? 'account-payables' : 'account-receivables';
        $module = $this->registry->find($key);
        $data['capabilities'] = $module
            ? $this->access->capabilities($request->user(), $module)
            : ['view' => false, 'create' => false, 'edit' => false, 'delete' => false];
        return $this->ok($data);
    }

    public function index(InvoiceIndexRequest $request): JsonResponse
    {
        $direction = $this->direction($request);
        $data = $this->service->paginate($direction, $request->validated());
        if ($direction === 'incoming') $data = $this->liabilityOwnership->decorateInvoicePayload($data);
        return $this->ok($data);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        return $this->ok($this->service->create($this->direction($request), $request->validated(), $request->user()), 201);
    }

    public function show(Request $request): JsonResponse
    {
        $direction = $this->direction($request);
        $data = $this->service->show($direction, $this->invoiceId($request));
        if ($direction === 'incoming') $data = $this->liabilityOwnership->decorateInvoicePayload($data);
        return $this->ok($data);
    }

    public function update(UpdateInvoiceRequest $request): JsonResponse
    {
        return $this->ok($this->service->update($this->direction($request), $this->invoiceId($request), $request->validated(), $request->user()));
    }

    public function issue(IssueInvoiceRequest $request): JsonResponse
    {
        $direction = $this->direction($request);
        $invoiceId = $this->invoiceId($request);
        $data = $this->service->issue($direction, $invoiceId, $request->validated(), $request->user());
        if ($direction === 'incoming') {
            $data['liability_ownership'] = $this->liabilityOwnership->coverIfWarehouseStockRequestMirror($invoiceId, $request->user()?->id);
            $data = $this->liabilityOwnership->decorateInvoicePayload($data);
        }
        return $this->ok($data);
    }

    public function payment(RecordInvoicePaymentRequest $request): JsonResponse
    {
        $direction = $this->direction($request);
        $invoiceId = $this->invoiceId($request);
        if ($direction === 'incoming') $this->liabilityOwnership->assertInvoiceCanBePaid($invoiceId);
        return $this->ok($this->service->recordPayment($direction, $invoiceId, $request->validated(), $request->user()));
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->service->deleteDraft($this->direction($request), $this->invoiceId($request));
        return $this->ok(['deleted' => true]);
    }

    private function invoiceId(Request $request): string
    {
        $id = trim((string) $request->route('id'));
        abort_if($id === '', 404, 'Invoice tidak ditemukan.');
        return $id;
    }

    private function direction(Request $request): string
    {
        $routeDirection = strtolower(trim((string) $request->route('direction')));
        if (in_array($routeDirection, ['incoming', 'outgoing'], true)) return $routeDirection;

        $name = strtolower((string) optional($request->route())->getName());
        if (str_contains($name, '.incoming.')) return 'incoming';
        if (str_contains($name, '.outgoing.')) return 'outgoing';

        $path = strtolower($request->path());
        if (str_contains($path, '/invoices/incoming')) return 'incoming';
        if (str_contains($path, '/invoices/outgoing')) return 'outgoing';

        abort(404, 'Arah invoice tidak dikenali.');
    }

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }
}
