<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'outlets','users','stk_skus','stk_uoms','wh_storages','wh_batches',
            'wh_ledger_postings','wh_v4_finance_coa','wh_v4_finance_general_postings',
            'wh_v4_finance_posting_templates','wh_v4_finance_posting_template_lines',
            'wh_v8_finance_coa_mappings','wh_v8_finance_posting_bridges',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("ERP REV Iteration 09 membutuhkan tabel {$table}. Apply Iterasi 01-08 terlebih dahulu.");
            }
        }

        if (! Schema::hasTable('wh_v9_return_requests')) {
            Schema::create('wh_v9_return_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('return_number', 60)->unique();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->string('source_type', 32)->index();
                $table->string('source_reference', 160)->nullable()->index();
                $table->date('return_date')->index();
                $table->string('status', 24)->default('draft')->index();
                $table->string('reason', 500);
                $table->text('notes')->nullable();

                $table->decimal('total_qty_base', 18, 4)->default(0);
                $table->decimal('return_to_stock_qty_base', 18, 4)->default(0);
                $table->decimal('spoil_qty_base', 18, 4)->default(0);
                $table->decimal('return_stock_value', 22, 2)->default(0);
                $table->decimal('spoil_value', 22, 2)->default(0);

                $table->ulid('stock_ledger_posting_id')->nullable();
                $table->foreign('stock_ledger_posting_id', 'whv9_ret_stock_ledger_fk')
                    ->references('id')->on('wh_ledger_postings')->nullOnDelete();

                $table->ulid('finance_spoil_posting_id')->nullable();
                $table->foreign('finance_spoil_posting_id', 'whv9_ret_spoil_fin_fk')
                    ->references('id')->on('wh_v4_finance_general_postings')->nullOnDelete();

                $table->string('execution_idempotency_key', 160)->nullable()->unique();
                $table->char('execution_payload_fingerprint', 64)->nullable();

                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignUlid('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('executed_at')->nullable();
                $table->foreignUlid('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();

                $table->index(['warehouse_id','status','return_date'], 'whv9_ret_wh_status_date_idx');
            });
        }

        if (! Schema::hasTable('wh_v9_return_request_items')) {
            Schema::create('wh_v9_return_request_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('return_request_id')->constrained('wh_v9_return_requests')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();

                $table->string('uom_code_snapshot', 30);
                $table->string('uom_name_snapshot', 100)->nullable();
                $table->string('base_uom_code_snapshot', 30);
                $table->string('base_uom_name_snapshot', 100)->nullable();
                $table->decimal('conversion_factor_snapshot', 24, 8)->default(1);

                $table->decimal('qty_uom', 18, 4);
                $table->decimal('qty_base', 18, 4);
                $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
                $table->decimal('line_value', 22, 2)->default(0);

                $table->string('outcome', 24)->index(); // RETURN_TO_STOCK | SPOIL
                $table->foreignUlid('storage_id')->nullable()->constrained('wh_storages')->nullOnDelete();
                $table->foreignUlid('batch_id')->nullable()->constrained('wh_batches')->nullOnDelete();
                $table->string('supplier_batch_code', 100)->nullable();
                $table->date('production_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('status', 24)->default('pending')->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['return_request_id','sku_id'], 'whv9_ret_item_sku_uq');
                $table->index(['return_request_id','outcome'], 'whv9_ret_item_outcome_idx');
            });
        }

        if (! Schema::hasTable('wh_v9_return_request_events')) {
            Schema::create('wh_v9_return_request_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('return_request_id')->constrained('wh_v9_return_requests')->cascadeOnDelete();
                $table->string('event_type', 60)->index();
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24)->nullable();
                $table->text('message')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();
                $table->index(['return_request_id','occurred_at'], 'whv9_ret_event_timeline_idx');
            });
        }

        $this->seedFinanceTemplate();
        $this->registerAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedFinanceTemplate(): void
    {
        foreach (['5300','1210'] as $code) {
            if (! DB::table('wh_v4_finance_coa')->where('code',$code)->where('is_active',true)->exists()) {
                throw new RuntimeException("COA Warehouse {$code} wajib tersedia untuk Return Request SPOIL.");
            }
        }

        $template = DB::table('wh_v4_finance_posting_templates')->where('code','RETURN_SPOIL_LOSS')->first();
        $templateId = $template?->id ?: (string) Str::ulid();

        if (! $template) {
            DB::table('wh_v4_finance_posting_templates')->insert([
                'id'=>$templateId,
                'code'=>'RETURN_SPOIL_LOSS',
                'name'=>'Return Material Spoil Loss',
                'source_type'=>'RETURN_REQUEST',
                'description'=>'Material return outcome SPOIL: expense loss tanpa menambah stock Warehouse.',
                'is_system'=>true,
                'is_active'=>true,
                'created_by_user_id'=>null,
                'updated_by_user_id'=>null,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
        } else {
            DB::table('wh_v4_finance_posting_templates')->where('id',$templateId)->update([
                'name'=>'Return Material Spoil Loss',
                'source_type'=>'RETURN_REQUEST',
                'description'=>'Material return outcome SPOIL: expense loss tanpa menambah stock Warehouse.',
                'is_system'=>true,
                'is_active'=>true,
                'updated_at'=>now(),
            ]);
        }

        $lineCount=DB::table('wh_v4_finance_posting_template_lines')->where('template_id',$templateId)->count();
        if ($lineCount === 0) {
            $loss = DB::table('wh_v4_finance_coa')->where('code','5300')->value('id');
            $inventory = DB::table('wh_v4_finance_coa')->where('code','1210')->value('id');

            foreach ([
                [$loss,'DEBIT','inventory_value','Spoil return {{reference_no}}'],
                [$inventory,'CREDIT','inventory_value','Write-off spoil return {{reference_no}}'],
            ] as $index => [$accountId,$side,$amountKey,$memo]) {
                DB::table('wh_v4_finance_posting_template_lines')->insert([
                    'id'=>(string)Str::ulid(),
                    'template_id'=>$templateId,
                    'sort_order'=>$index+1,
                    'account_id'=>$accountId,
                    'side'=>$side,
                    'amount_key'=>$amountKey,
                    'multiplier'=>1,
                    'memo_template'=>$memo,
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
            }
        } elseif ($lineCount !== 2) {
            throw new RuntimeException('Template RETURN_SPOIL_LOSS ditemukan dalam kondisi parsial. Audit template sebelum melanjutkan migration.');
        }
    }

    private function registerAccessMatrix(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard','web');
            foreach ([
                'warehouse.procurement.return_request.view',
                'warehouse.procurement.return_request.create',
                'warehouse.procurement.return_request.update',
                'warehouse.procurement.return_request.delete',
                'warehouse.procurement.return_request.approve',
                'warehouse.procurement.return_request.execute',
                'warehouse.procurement.return_request.cancel',
            ] as $permission) {
                Permission::findOrCreate($permission,$guard);
            }
        }

        if (! Schema::hasTable('access_menus')) return;

        $menu = DB::table('access_menus')->where('path','/warehouse/purchasing/return-requests')->first();
        if (! $menu) return;

        $payload = [];
        if (Schema::hasColumn('access_menus','name')) $payload['name']='Return Request';
        if (Schema::hasColumn('access_menus','permission_view')) $payload['permission_view']='warehouse.procurement.return_request.view';
        if (Schema::hasColumn('access_menus','permission_create')) $payload['permission_create']='warehouse.procurement.return_request.create';
        if (Schema::hasColumn('access_menus','permission_update')) $payload['permission_update']='warehouse.procurement.return_request.update';
        if (Schema::hasColumn('access_menus','permission_delete')) $payload['permission_delete']='warehouse.procurement.return_request.delete';
        if (Schema::hasColumn('access_menus','is_active')) $payload['is_active']=true;
        if (Schema::hasColumn('access_menus','updated_at')) $payload['updated_at']=now();
        if ($payload !== []) DB::table('access_menus')->where('id',$menu->id)->update($payload);
    }

    public function down(): void
    {
        // Non-destructive by design. Return execution, Warehouse Ledger, and Finance posting history remain auditable.
    }
};
