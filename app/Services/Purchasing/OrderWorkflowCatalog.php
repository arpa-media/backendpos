<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\PurchaseOrderDocument;
use App\Models\Purchasing\PurchaseOrderLine;
use App\Models\Purchasing\ReimburseOrder;
use App\Models\Purchasing\ReimburseOrderItem;
use App\Models\Purchasing\ServiceOrder;
use App\Models\Purchasing\ServiceOrderItem;
use InvalidArgumentException;

class OrderWorkflowCatalog
{
    public const PURCHASE_ORDER = 'PURCHASE_ORDER';
    public const SERVICE_ORDER = 'SERVICE_ORDER';
    public const REIMBURSE_ORDER = 'REIMBURSE_ORDER';

    /** @return array<string, mixed> */
    public function definition(string $kind): array
    {
        $normalized = $this->normalize($kind);

        return match ($normalized) {
            self::PURCHASE_ORDER => [
                'kind' => self::PURCHASE_ORDER,
                'slug' => 'purchase-order',
                'module_key' => 'purchase-orders',
                'label' => 'Purchase Order',
                'number_field' => 'po_number',
                'number_prefix' => 'PO',
                'model' => PurchaseOrderDocument::class,
                'item_model' => PurchaseOrderLine::class,
                'item_foreign_key' => 'purchase_order_id',
                'qty_field' => 'approved_qty',
                'permission' => 'purchasing.purchase_order',
                'request_types' => ['PURCHASE', 'ASSET', 'STOCK'],
                'manual_request_types' => ['PURCHASE', 'ASSET'],
                'execution_label' => 'Goods Receipt',
                'document_event_type' => 'PURCHASE_ORDER',
                'supplier_required' => true,
            ],
            self::SERVICE_ORDER => [
                'kind' => self::SERVICE_ORDER,
                'slug' => 'service-order',
                'module_key' => 'service-orders',
                'label' => 'Service Order',
                'number_field' => 'service_order_number',
                'number_prefix' => 'SO',
                'model' => ServiceOrder::class,
                'item_model' => ServiceOrderItem::class,
                'item_foreign_key' => 'service_order_id',
                'qty_field' => 'qty',
                'permission' => 'purchasing.service_order',
                'request_types' => ['SERVICE'],
                'manual_request_types' => ['SERVICE'],
                'execution_label' => 'Service Acceptance',
                'document_event_type' => 'SERVICE_ORDER',
                'supplier_required' => true,
            ],
            self::REIMBURSE_ORDER => [
                'kind' => self::REIMBURSE_ORDER,
                'slug' => 'reimburse-order',
                'module_key' => 'reimburse-orders',
                'label' => 'Reimburse Order',
                'number_field' => 'reimburse_order_number',
                'number_prefix' => 'RO',
                'model' => ReimburseOrder::class,
                'item_model' => ReimburseOrderItem::class,
                'item_foreign_key' => 'reimburse_order_id',
                'qty_field' => 'qty',
                'permission' => 'purchasing.reimburse_order',
                'request_types' => ['REIMBURSE'],
                'manual_request_types' => ['REIMBURSE'],
                'execution_label' => 'Reimburse Payment',
                'document_event_type' => 'REIMBURSE_ORDER',
                'supplier_required' => false,
            ],
        };
    }

    public function normalize(string $kind): string
    {
        $value = strtoupper(str_replace('-', '_', trim($kind)));

        return match ($value) {
            'PURCHASE_ORDER', 'PURCHASE_ORDERS', 'PO' => self::PURCHASE_ORDER,
            'SERVICE_ORDER', 'SERVICE_ORDERS', 'SO' => self::SERVICE_ORDER,
            'REIMBURSE_ORDER', 'REIMBURSE_ORDERS', 'RO' => self::REIMBURSE_ORDER,
            default => throw new InvalidArgumentException('Jenis order Purchasing tidak dikenali.'),
        };
    }

    /** @return array<int, string> */
    public function statuses(): array
    {
        return [
            'DRAFT',
            'AWAITING_FINANCE_APPROVAL_1',
            'AWAITING_FINANCE_APPROVAL_2',
            'APPROVED',
            'REJECTED',
            'PARTIALLY_EXECUTED',
            'EXECUTED',
            'CANCELLED',
        ];
    }
}
