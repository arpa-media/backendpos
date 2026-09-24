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
    public function up(): void
    {
        $this->ensureIndexes();
        $this->hardenAccountReceivable();
        $this->hardenGoLiveMenu();
    }

    private function ensureIndexes(): void
    {
        $contracts = [
            ['pur_invoices', ['direction','journal_status','status','due_date'], 'pur06_inv_dir_jrn_status_due_idx'],
            ['pur_invoices', ['direction','balance_due','due_date'], 'pur06_inv_dir_bal_due_idx'],
            ['pur_invoice_payments', ['invoice_id','status','journal_status','payment_date'], 'pur06_pay_inv_status_jrn_date_idx'],
            ['pur_document_attachments', ['document_type','document_id'], 'pur06_att_doc_type_id_idx'],
            ['pur_goods_receipts', ['status','realization_status','document_date'], 'pur06_gr_status_real_date_idx'],
            ['pur_service_acceptances', ['status','realization_status','document_date'], 'pur06_sa_status_real_date_idx'],
            ['pur_reimburse_payments', ['status','realization_status','document_date'], 'pur06_rp_status_real_date_idx'],
            ['pur_reimburse_payables', ['status','due_date','company_code'], 'pur06_rap_status_due_company_idx'],
            ['finance_general_postings', ['source_code','status','business_date'], 'pur06_gen_source_status_date_idx'],
        ];

        foreach ($contracts as [$table, $columns, $name]) {
            if (! Schema::hasTable($table)) continue;
            if (collect($columns)->contains(fn (string $column): bool => ! Schema::hasColumn($table, $column))) continue;
            if ($this->indexExists($table, $name)) continue;
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    private function hardenAccountReceivable(): void
    {
        $permissions = [
            'purchasing.account_receivable.view',
            'purchasing.account_receivable.create',
            'purchasing.account_receivable.update',
            'purchasing.account_receivable.delete',
            'purchasing.account_receivable.payment',
        ];
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');

        Role::query()->where('guard_name','web')->where(function($query):void{
            $query->whereRaw('LOWER(name) IN (?, ?)', ['admin','administrator'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['%super%admin%']);
        })->get()->each(fn(Role $role) => $role->givePermissionTo($permissions));

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code','purchasing')->first();
        if (! $portal) return;

        $existing = DB::table('access_menus')->where('code','purchasing-account-receivables')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code'=>'purchasing-account-receivables'], [
            'id'=>$menuId,
            'portal_id'=>$portal->id,
            'name'=>'Account Receivable',
            'path'=>'/purchasing/account-receivables',
            'sort_order'=>110,
            'permission_view'=>'purchasing.account_receivable.view',
            'permission_create'=>'purchasing.account_receivable.create',
            'permission_update'=>'purchasing.account_receivable.update',
            'permission_delete'=>'purchasing.account_receivable.delete',
            'is_active'=>true,
            'created_at'=>$existing->created_at ?? now(),
            'updated_at'=>now(),
        ]);

        if (Schema::hasTable('access_role_menu_permissions')) {
            $sourceId = DB::table('access_menus')->where('code','purchasing-account-payables')->value('id');
            if ($sourceId) {
                foreach (DB::table('access_role_menu_permissions')->where('menu_id',$sourceId)->get() as $row) {
                    $query=DB::table('access_role_menu_permissions')->where('access_role_id',$row->access_role_id)->where('menu_id',$menuId);
                    $row->access_level_id===null ? $query->whereNull('access_level_id') : $query->where('access_level_id',$row->access_level_id);
                    if ($query->exists()) continue;
                    DB::table('access_role_menu_permissions')->insert([
                        'id'=>(string)Str::ulid(),
                        'access_role_id'=>$row->access_role_id,
                        'access_level_id'=>$row->access_level_id,
                        'menu_id'=>$menuId,
                        'can_view'=>(bool)$row->can_view,
                        'can_create'=>(bool)$row->can_create,
                        'can_edit'=>(bool)$row->can_edit,
                        'can_delete'=>(bool)$row->can_delete,
                        'created_at'=>now(),
                        'updated_at'=>now(),
                    ]);
                }
            }
        }
    }

    private function hardenGoLiveMenu(): void
    {
        if (! Schema::hasTable('access_menus')) return;
        DB::table('access_menus')->where('code','purchasing-go-live')->update([
            'name'=>'Go-Live & Audit',
            'is_active'=>true,
            'updated_at'=>now(),
        ]);
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name) return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    public function down(): void
    {
        // Non-destructive final hardening: indexes/access/audit history are retained.
    }
};
