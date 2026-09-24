<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Purchasing\DocumentAttachment;
use App\Services\Purchasing\FundRequestService;
use App\Services\Purchasing\PurchasingDocumentAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PurchasingDocumentAttachmentController extends Controller
{
    public function __construct(
        private readonly FundRequestService $fundRequests,
        private readonly PurchasingDocumentAttachmentService $attachments,
        private readonly \App\Services\Purchasing\OrderWorkflowService $orders,
    ) {
    }

    public function content(Request $request, string $attachmentId): BinaryFileResponse
    {
        $attachment = DocumentAttachment::query()->findOrFail($attachmentId);
        $type = $this->attachments->normalizeType((string) $attachment->document_type);

        $documentId = (string) $attachment->document_id;
        $visible = false;

        if ($type === PurchasingDocumentAttachmentService::FUND_REQUEST) {
            $visible = $this->fundRequests->visibleQuery($request->user())->whereKey($documentId)->exists();
            if (! $visible) {
                foreach (['purchase-order', 'service-order', 'reimburse-order'] as $kind) {
                    if ($this->orders->visibleQuery($kind, $request->user())->where('fund_request_id', $documentId)->exists()) {
                        $visible = true;
                        break;
                    }
                }
            }
        } else {
            $definition = match ($type) {
                PurchasingDocumentAttachmentService::SERVICE_ENTRY_SHEET => ['table'=>'pur_service_entry_sheets','order_kind'=>'purchase-order'],
                PurchasingDocumentAttachmentService::GOODS_RECEIPT => ['table'=>'pur_goods_receipts','order_kind'=>'purchase-order'],
                PurchasingDocumentAttachmentService::SERVICE_ACCEPTANCE => ['table'=>'pur_service_acceptances','order_kind'=>'service-order'],
                PurchasingDocumentAttachmentService::REIMBURSE_PAYMENT => ['table'=>'pur_reimburse_payments','order_kind'=>'reimburse-order'],
                default => null,
            };
            if ($definition !== null) {
                $execution = \Illuminate\Support\Facades\DB::table($definition['table'])->where('id', $documentId)->whereNull('deleted_at')->first();
                if ($execution) {
                    $visible = $this->orders->visibleQuery($definition['order_kind'], $request->user())->whereKey((string) $execution->order_id)->exists();
                }
            }
        }

        if (! $visible) throw new NotFoundHttpException();

        $disk = (string) ($attachment->disk ?: 'local');
        $path = (string) $attachment->path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            throw new NotFoundHttpException('File attachment tidak ditemukan pada storage.');
        }

        $filename = preg_replace('/[\r\n"]+/', '', (string) $attachment->original_name) ?: 'attachment';

        return response()->file(Storage::disk($disk)->path($path), [
            'Content-Type' => (string) $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
