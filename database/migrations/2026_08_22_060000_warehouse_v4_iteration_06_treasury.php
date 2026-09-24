<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL = 'warehouse-operations';

    public function up(): void
    {
        foreach (['outlets','users','wh_v3_payment_accounts','wh_v4_finance_coa','wh_v4_finance_general_postings'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v4 Iterasi 06 membutuhkan tabel {$table}. Apply Iterasi 01-05 terlebih dahulu.");
            }
        }

        $this->extendPaymentAccounts();
        $this->treasuryTransactions();
        $this->treasuryEvents();
        $this->registerAccessMatrix();
    }

    private function extendPaymentAccounts(): void
    {
        Schema::table('wh_v3_payment_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_v3_payment_accounts', 'account_type')) {
                $table->string('account_type', 16)->nullable()->index()->after('account_number');
            }
            if (! Schema::hasColumn('wh_v3_payment_accounts', 'finance_coa_id')) {
                $table->char('finance_coa_id', 26)->nullable()->index()->after('account_type');
            }
            if (! Schema::hasColumn('wh_v3_payment_accounts', 'is_treasury_enabled')) {
                $table->boolean('is_treasury_enabled')->default(true)->index()->after('finance_coa_id');
            }
        });

        if (Schema::hasColumn('wh_v3_payment_accounts', 'account_type')) {
            DB::table('wh_v3_payment_accounts')->whereNull('account_type')->update([
                'account_type' => DB::raw("CASE WHEN COALESCE(bank_name,'') <> '' OR COALESCE(account_number,'') <> '' THEN 'BANK' ELSE 'CASH' END"),
                'updated_at' => now(),
            ]);
        }
    }

    private function treasuryTransactions(): void
    {
        if (Schema::hasTable('wh_v4_treasury_transactions')) return;

        Schema::create('wh_v4_treasury_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('treasury_number', 72)->unique();
            $table->char('warehouse_id', 26)->index();
            $table->string('transaction_type', 24)->index();
            $table->date('transaction_date')->index();
            $table->decimal('amount', 22, 2);
            $table->string('currency_code', 3)->default('IDR');

            $table->char('from_payment_account_id', 26)->nullable()->index();
            $table->char('to_payment_account_id', 26)->nullable()->index();
            $table->char('counter_account_id', 26)->nullable()->index();
            $table->json('from_account_snapshot')->nullable();
            $table->json('to_account_snapshot')->nullable();
            $table->json('counter_account_snapshot')->nullable();

            $table->string('counterparty_name', 180)->nullable();
            $table->string('reference_number', 140)->nullable()->index();
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_id', 100)->nullable()->index();
            $table->string('source_key', 191)->nullable()->unique();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('DRAFT')->index();
            $table->boolean('auto_generated')->default(false)->index();
            $table->string('approval_mode', 30)->default('MANUAL');
            $table->char('general_posting_id', 26)->nullable()->index();
            $table->json('metadata')->nullable();

            $table->char('created_by_user_id', 26)->nullable()->index();
            $table->char('updated_by_user_id', 26)->nullable()->index();
            $table->char('submitted_by_user_id', 26)->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->char('approved_by_user_id', 26)->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->char('rejected_by_user_id', 26)->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('warehouse_id', 'whv4_treas_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $table->foreign('from_payment_account_id', 'whv4_treas_from_acc_fk')->references('id')->on('wh_v3_payment_accounts')->nullOnDelete();
            $table->foreign('to_payment_account_id', 'whv4_treas_to_acc_fk')->references('id')->on('wh_v3_payment_accounts')->nullOnDelete();
            $table->foreign('counter_account_id', 'whv4_treas_counter_fk')->references('id')->on('wh_v4_finance_coa')->nullOnDelete();
            $table->foreign('general_posting_id', 'whv4_treas_gp_fk')->references('id')->on('wh_v4_finance_general_postings')->nullOnDelete();
            $table->foreign('created_by_user_id', 'whv4_treas_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'whv4_treas_updater_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submitted_by_user_id', 'whv4_treas_submit_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by_user_id', 'whv4_treas_approve_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by_user_id', 'whv4_treas_reject_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['warehouse_id','transaction_type','status','transaction_date'], 'whv4_treas_wh_type_status_idx');
        });
    }

    private function treasuryEvents(): void
    {
        if (Schema::hasTable('wh_v4_treasury_events')) return;

        Schema::create('wh_v4_treasury_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('treasury_transaction_id', 26)->index();
            $table->string('event_type', 60)->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->char('actor_user_id', 26)->nullable()->index();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();

            $table->foreign('treasury_transaction_id', 'whv4_treas_evt_tx_fk')->references('id')->on('wh_v4_treasury_transactions')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'whv4_treas_evt_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function registerAccessMatrix(): void
    {
        $types = [
            'cash_in' => ['Cash-In','/warehouse/finance/cash-in',730],
            'cash_out' => ['Cash-Out','/warehouse/finance/cash-out',740],
            'bank_in' => ['Bank-In','/warehouse/finance/bank-in',750],
            'bank_out' => ['Bank-Out','/warehouse/finance/bank-out',760],
            'book_transfer' => ['Book Transfer','/warehouse/finance/book-transfer',770],
        ];

        $permissions = [];
        foreach (array_keys($types) as $type) {
            $base = 'warehouse.finance.'.str_replace('_','.', $type);
            foreach (['view','create','update','submit','approve'] as $action) $permissions[] = "{$base}.{$action}";
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ($permissions as $permission) Permission::findOrCreate($permission, $guard);
            if (Schema::hasTable('roles')) {
                Role::query()->where('guard_name',$guard)
                    ->whereIn(DB::raw('LOWER(name)'), ['admin','administrator','superadmin','super-admin','warehouse'])
                    ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions));
            }
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        if (! $portal) return;

        $now = now();
        foreach ($types as $type => [$name,$path,$sort]) {
            $base = 'warehouse.finance.'.str_replace('_','.', $type);
            $code = 'warehouse-finance-'.str_replace('_','-', $type).'-v4';
            $existing = DB::table('access_menus')->where('code',$code)->first();
            $menuId = (string)($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(['code'=>$code],[
                'id'=>$menuId,'portal_id'=>$portal->id,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
                'permission_view'=>"{$base}.view",'permission_create'=>"{$base}.create",'permission_update'=>"{$base}.update",'permission_delete'=>null,
                'is_active'=>true,'created_at'=>$existing->created_at ?? $now,'updated_at'=>$now,
            ]);
            $this->seedMatrixRows($menuId,$now);
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedMatrixRows(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn($id)=>(string)$id)->all() : [];
        foreach (DB::table('access_roles')->get(['id','code']) as $role) {
            $enabled = in_array(strtoupper(trim((string)$role->code)), ['ADMIN','WAREHOUSE'], true);
            foreach (array_merge([null],$levels) as $levelId) {
                $q=DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$menuId);
                $levelId===null ? $q->whereNull('access_level_id') : $q->where('access_level_id',$levelId);
                if ($q->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id'=>(string)Str::ulid(),'access_role_id'=>$role->id,'access_level_id'=>$levelId,'menu_id'=>$menuId,
                    'can_view'=>$enabled,'can_create'=>$enabled,'can_edit'=>$enabled,'can_delete'=>false,'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: treasury and accounting history must never be silently deleted.
    }
};
