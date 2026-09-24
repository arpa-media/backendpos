<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendOutgoingInvoices();
        $this->extendManualInvoices();
        $this->createPaymentLedger();
        $this->normalizeExistingBalances();
        $this->syncExistingPurchasingIssues();
        $this->createPurchasingIssueTrigger();
    }

    private function extendOutgoingInvoices(): void
    {
        if (! Schema::hasTable('wh_v3_outgoing_invoices')) return;
        if (! Schema::hasColumn('wh_v3_outgoing_invoices', 'paid_total')) {
            Schema::table('wh_v3_outgoing_invoices', fn (Blueprint $t) => $t->decimal('paid_total', 22, 2)->default(0)->after('grand_total'));
        }
        if (! Schema::hasColumn('wh_v3_outgoing_invoices', 'balance_due')) {
            Schema::table('wh_v3_outgoing_invoices', fn (Blueprint $t) => $t->decimal('balance_due', 22, 2)->default(0)->after('paid_total'));
        }
        if (! Schema::hasColumn('wh_v3_outgoing_invoices', 'issued_at')) {
            Schema::table('wh_v3_outgoing_invoices', fn (Blueprint $t) => $t->timestamp('issued_at')->nullable()->after('status'));
        }
        if (! Schema::hasColumn('wh_v3_outgoing_invoices', 'paid_at')) {
            Schema::table('wh_v3_outgoing_invoices', fn (Blueprint $t) => $t->timestamp('paid_at')->nullable()->after('issued_at'));
        }
    }

    private function extendManualInvoices(): void
    {
        if (! Schema::hasTable('wh_v3_manual_invoices')) return;
        if (! Schema::hasColumn('wh_v3_manual_invoices', 'paid_total')) {
            Schema::table('wh_v3_manual_invoices', fn (Blueprint $t) => $t->decimal('paid_total', 22, 2)->default(0)->after('grand_total'));
        }
        if (! Schema::hasColumn('wh_v3_manual_invoices', 'balance_due')) {
            Schema::table('wh_v3_manual_invoices', fn (Blueprint $t) => $t->decimal('balance_due', 22, 2)->default(0)->after('paid_total'));
        }
        if (! Schema::hasColumn('wh_v3_manual_invoices', 'issued_at')) {
            Schema::table('wh_v3_manual_invoices', fn (Blueprint $t) => $t->timestamp('issued_at')->nullable()->after('status'));
        }
        if (! Schema::hasColumn('wh_v3_manual_invoices', 'paid_at')) {
            Schema::table('wh_v3_manual_invoices', fn (Blueprint $t) => $t->timestamp('paid_at')->nullable()->after('issued_at'));
        }
    }

    private function createPaymentLedger(): void
    {
        if (Schema::hasTable('wh_v3_invoice_payments')) return;
        Schema::create('wh_v3_invoice_payments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('payment_number', 80)->unique();
            $t->string('document_source', 40)->index();
            $t->string('document_id', 80)->index();
            $t->string('direction', 20)->index();
            $t->ulid('warehouse_id')->index();
            $t->date('payment_date')->index();
            $t->text('payment_term_detail')->nullable();
            $t->decimal('amount', 22, 2);
            $t->ulid('payment_account_id')->nullable()->index();
            $t->json('payment_account_snapshot')->nullable();
            $t->string('payer_name_snapshot', 180)->nullable();
            $t->string('payer_nisj_snapshot', 80)->nullable();
            $t->string('reference_number', 140)->nullable()->index();
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('posted')->index();
            $t->string('idempotency_key', 180);
            $t->ulid('confirmed_by_user_id')->nullable()->index();
            $t->timestamp('confirmed_at')->nullable()->index();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['document_source','document_id','idempotency_key'], 'whv3_invpay_doc_idem_uq');
            $t->index(['warehouse_id','direction','payment_date'], 'whv3_invpay_wh_dir_date_idx');
            $t->foreign('warehouse_id', 'whv3_invpay_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('payment_account_id', 'whv3_invpay_acct_fk')->references('id')->on('wh_v3_payment_accounts')->nullOnDelete();
            $t->foreign('confirmed_by_user_id', 'whv3_invpay_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function normalizeExistingBalances(): void
    {
        if (Schema::hasTable('wh_v3_outgoing_invoices') && Schema::hasColumn('wh_v3_outgoing_invoices', 'balance_due')) {
            DB::table('wh_v3_outgoing_invoices')->update(['balance_due' => DB::raw('GREATEST(grand_total - COALESCE(paid_total,0),0)')]);
        }
        if (Schema::hasTable('wh_v3_manual_invoices') && Schema::hasColumn('wh_v3_manual_invoices', 'balance_due')) {
            DB::table('wh_v3_manual_invoices')->update(['balance_due' => DB::raw('GREATEST(grand_total - COALESCE(paid_total,0),0)')]);
            DB::table('wh_v3_manual_invoices')->where('direction','incoming')->where('status','approved')
                ->update(['status'=>'issued','issued_at'=>DB::raw('COALESCE(issued_at, updated_at)')]);
        }
    }

    private function syncExistingPurchasingIssues(): void
    {
        if (! Schema::hasTable('pur_invoices') || ! Schema::hasTable('wh_v3_outgoing_invoices')) return;
        $ids = DB::table('pur_invoices as p')
            ->join('wh_v3_outgoing_invoices as w', 'w.id', '=', 'p.source_document_id')
            ->where('p.direction','INCOMING')->where('p.source_document_kind','WAREHOUSE_OUTGOING_INVOICE')
            ->whereIn('p.status',['ISSUED','PARTIALLY_PAID','PAID'])->whereNull('p.deleted_at')->where('w.status','approved')
            ->pluck('w.id');
        if ($ids->isNotEmpty()) {
            DB::table('wh_v3_outgoing_invoices')->whereIn('id',$ids)->where('status','approved')
                ->update(['status'=>'issued','issued_at'=>now(),'updated_at'=>now()]);
        }
    }

    private function createPurchasingIssueTrigger(): void
    {
        if (! Schema::hasTable('pur_invoices') || ! Schema::hasTable('wh_v3_outgoing_invoices')) return;
        if (! in_array(DB::connection()->getDriverName(), ['mysql','mariadb'], true)) return;
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_pur_invoice_issue_wh_outgoing_v3');
            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_pur_invoice_issue_wh_outgoing_v3
AFTER UPDATE ON pur_invoices
FOR EACH ROW
BEGIN
    IF NEW.direction = 'INCOMING'
       AND NEW.source_document_kind = 'WAREHOUSE_OUTGOING_INVOICE'
       AND NEW.status IN ('ISSUED','PARTIALLY_PAID','PAID')
       AND OLD.status <> NEW.status THEN
        UPDATE wh_v3_outgoing_invoices
           SET status = 'issued',
               issued_at = COALESCE(issued_at, NEW.issued_at, CURRENT_TIMESTAMP),
               updated_at = CURRENT_TIMESTAMP
         WHERE id = NEW.source_document_id
           AND status = 'approved';
    END IF;
END
SQL);
        } catch (\Throwable $e) {
            // Hosting tertentu tidak memberi privilege CREATE TRIGGER.
            // Lifecycle service tetap melakukan reconciliation pada list/detail sebagai fallback.
            report($e);
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql','mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_pur_invoice_issue_wh_outgoing_v3');
        }
        // Non-destructive: payment ledger and lifecycle columns are intentionally retained.
    }
};
