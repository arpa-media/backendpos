<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('wh_v13_i20_invoice_plans')) {
            Schema::create('wh_v13_i20_invoice_plans', function(Blueprint $t):void {
                $t->ulid('id')->primary();
                $t->ulid('warehouse_id')->index();
                $t->string('direction',20); $t->string('document_source',40);$t->string('document_id',80);
                $t->string('plan_type',12); $t->decimal('invoice_total',22,2);$t->decimal('dp_amount',22,2)->default(0);
                $t->char('payload_hash',64);$t->ulid('approved_by_user_id')->nullable();$t->timestamps();
                $t->unique(['document_source','document_id','direction'],'wh13i20_plan_doc_uq');
                $t->index(['warehouse_id','direction','created_at'],'wh13i20_plan_scope_idx');
            });
        }
        if (!Schema::hasTable('wh_v13_i20_invoice_terms')) {
            Schema::create('wh_v13_i20_invoice_terms', function(Blueprint $t):void {
                $t->ulid('id')->primary();$t->ulid('plan_id');$t->unsignedSmallInteger('sequence');
                $t->date('due_date');$t->decimal('amount',22,2);$t->string('label',100)->nullable();$t->timestamps();
                $t->unique(['plan_id','sequence'],'wh13i20_terms_seq_uq');
                $t->index(['plan_id','due_date'],'wh13i20_terms_due_idx');
                $t->foreign('plan_id','wh13i20_terms_plan_fk')->references('id')->on('wh_v13_i20_invoice_plans')->restrictOnDelete();
            });
        }
        if (!Schema::hasTable('wh_v13_i20_term_allocations')) {
            Schema::create('wh_v13_i20_term_allocations', function(Blueprint $t):void {
                $t->ulid('id')->primary();$t->ulid('term_id');$t->ulid('payment_id');$t->decimal('amount',22,2);$t->timestamps();
                $t->unique(['term_id','payment_id'],'wh13i20_allocation_once_uq');
                $t->foreign('term_id','wh13i20_alloc_term_fk')->references('id')->on('wh_v13_i20_invoice_terms')->restrictOnDelete();
                $t->foreign('payment_id','wh13i20_alloc_payment_fk')->references('id')->on('wh_v3_invoice_payments')->restrictOnDelete();
            });
        }
    }
    public function down():void { // Financial history intentionally retained on rollback.
    }
};
