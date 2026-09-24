<?php

namespace App\Services\Purchasing;

use InvalidArgumentException;

class ExecutionWorkflowCatalog
{
    public function definition(string $kind): array
    {
        $k = strtoupper(str_replace('-', '_', trim($kind)));

        return match ($k) {
            'SERVICE_ENTRY_SHEET', 'SERVICE_ENTRY_SHEETS', 'SES' => [
                'kind' => 'SERVICE_ENTRY_SHEET', 'slug' => 'service-entry-sheet', 'label' => 'Service Entry Sheet',
                'table' => 'pur_service_entry_sheets', 'items' => 'pur_service_entry_sheet_items', 'number' => 'ses_number', 'prefix' => 'SES',
                'order_kind' => 'PURCHASE_ORDER', 'order_table' => 'pur_purchase_orders', 'order_items' => 'pur_purchase_order_items', 'order_number' => 'po_number',
                'permission' => 'purchasing.realization_order', 'event' => 'SERVICE_ENTRY_SHEET_APPROVED',
            ],
            'GOODS_RECEIPT', 'GOODS_RECEIPTS', 'GR' => [
                'kind' => 'GOODS_RECEIPT', 'slug' => 'goods-receipt', 'label' => 'Goods Receipt',
                'table' => 'pur_goods_receipts', 'items' => 'pur_goods_receipt_items', 'number' => 'gr_number', 'prefix' => 'GR',
                'order_kind' => 'PURCHASE_ORDER', 'order_table' => 'pur_purchase_orders', 'order_items' => 'pur_purchase_order_items', 'order_number' => 'po_number',
                'permission' => 'purchasing.goods_receipt', 'event' => 'GOODS_RECEIPT_APPROVED',
            ],
            'SERVICE_ACCEPTANCE', 'SERVICE_ACCEPTANCES', 'SA' => [
                'kind' => 'SERVICE_ACCEPTANCE', 'slug' => 'service-acceptance', 'label' => 'Service Acceptance',
                'table' => 'pur_service_acceptances', 'items' => 'pur_service_acceptance_items', 'number' => 'acceptance_number', 'prefix' => 'SA',
                'order_kind' => 'SERVICE_ORDER', 'order_table' => 'pur_service_orders', 'order_items' => 'pur_service_order_items', 'order_number' => 'service_order_number',
                'permission' => 'purchasing.service_acceptance', 'event' => 'SERVICE_ACCEPTANCE_APPROVED',
            ],
            'REIMBURSE_RECEIPT', 'REIMBURSE_RECEIPTS', 'REIMBURSE_PAYMENT', 'REIMBURSE_PAYMENTS', 'RP', 'RR' => [
                'kind' => 'REIMBURSE_PAYMENT', 'slug' => 'reimburse-payment', 'label' => 'Reimburse Receipt',
                'table' => 'pur_reimburse_payments', 'items' => 'pur_reimburse_payment_items', 'number' => 'payment_number', 'prefix' => 'RR',
                'order_kind' => 'REIMBURSE_ORDER', 'order_table' => 'pur_reimburse_orders', 'order_items' => 'pur_reimburse_order_items', 'order_number' => 'reimburse_order_number',
                'permission' => 'purchasing.reimburse_payment', 'event' => 'REIMBURSE_RECEIPT_APPROVED',
            ],
            default => throw new InvalidArgumentException('Jenis dokumen realisasi tidak dikenali.'),
        };
    }
}
