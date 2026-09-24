<?php

namespace App\Services\Purchasing;

use InvalidArgumentException;

class InvoiceWorkflowCatalog
{
    /** @return array<string, mixed> */
    public function invoice(string $direction): array
    {
        $normalized = strtoupper(str_replace('-', '_', trim($direction)));

        return match ($normalized) {
            'INCOMING', 'INCOMING_INVOICE', 'INVOICE_MASUK' => [
                'direction' => 'INCOMING',
                'slug' => 'incoming',
                'label' => 'Incoming Invoice',
                'number_prefix' => 'INV-IN',
                'permission' => 'purchasing.incoming_invoice',
                'ledger_slug' => 'account-payable',
                'ledger_label' => 'Account Payable',
            ],
            'OUTGOING', 'OUTGOING_INVOICE', 'INVOICE_KELUAR' => [
                'direction' => 'OUTGOING',
                'slug' => 'outgoing',
                'label' => 'Outgoing Invoice',
                'number_prefix' => 'INV-OUT',
                'permission' => 'purchasing.outgoing_invoice',
                'ledger_slug' => 'account-receivable',
                'ledger_label' => 'Account Receivable',
            ],
            default => throw new InvalidArgumentException('Arah invoice tidak dikenali.'),
        };
    }

    /** @return array<string, mixed> */
    public function ledger(string $ledger): array
    {
        $normalized = strtoupper(str_replace('-', '_', trim($ledger)));

        return match ($normalized) {
            'ACCOUNT_PAYABLE', 'AP', 'PAYABLE' => [
                'slug' => 'account-payable',
                'label' => 'Account Payable',
                'direction' => 'INCOMING',
                'permission' => 'purchasing.account_payable',
            ],
            'ACCOUNT_RECEIVABLE', 'AR', 'RECEIVABLE' => [
                'slug' => 'account-receivable',
                'label' => 'Account Receivable',
                'direction' => 'OUTGOING',
                'permission' => 'purchasing.account_receivable',
            ],
            default => throw new InvalidArgumentException('Jenis ledger invoice tidak dikenali.'),
        };
    }

    /** @return array<string, array<string, string>> */
    public function incomingSources(): array
    {
        // PE05: Reimburse Payment no longer flows through Incoming Invoice.
        // It becomes a dedicated Reimburse AP item and posts recognition + settlement
        // atomically when Finance confirms payment.
        return [
            'GOODS_RECEIPT' => [
                'kind' => 'GOODS_RECEIPT',
                'label' => 'Goods Receipt',
                'table' => 'pur_goods_receipts',
                'items' => 'pur_goods_receipt_items',
                'number' => 'gr_number',
                'order_table' => 'pur_purchase_orders',
                'order_kind' => 'PURCHASE_ORDER',
                'order_number' => 'po_number',
            ],
            'SERVICE_ACCEPTANCE' => [
                'kind' => 'SERVICE_ACCEPTANCE',
                'label' => 'Service Acceptance',
                'table' => 'pur_service_acceptances',
                'items' => 'pur_service_acceptance_items',
                'number' => 'acceptance_number',
                'order_table' => 'pur_service_orders',
                'order_kind' => 'SERVICE_ORDER',
                'order_number' => 'service_order_number',
            ],
        ];
    }

    /** @return array<int, string> */
    public function statuses(): array
    {
        return ['DRAFT', 'ISSUED', 'PARTIALLY_PAID', 'PAID', 'VOID'];
    }

    /** @return array<int, string> */
    public function paymentMethods(): array
    {
        return ['BANK_TRANSFER', 'CASH', 'PETTY_CASH', 'GIRO', 'VIRTUAL_ACCOUNT', 'OTHER'];
    }
}
