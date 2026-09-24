<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_menus')) return;
        $menus = [
            ['warehouse-v3-purchase-requests','/warehouse/purchasing/purchase-requests','warehouse.procurement.request'],
            ['warehouse-v3-purchase-orders','/warehouse/purchasing/purchase-orders','warehouse.procurement.order'],
            ['warehouse-v3-sales-stock-request','/warehouse/stock-requests/inbox','warehouse.stock_request.inbox'],
            ['warehouse-v3-logistics-do','/warehouse/delivery-orders','warehouse.delivery_order'],
            ['warehouse-v3-logistics-gr','/warehouse/goods-receipts','warehouse.receiving.monitor'],
        ];
        foreach ($menus as [$code,$path,$permission]) {
            $query = DB::table('access_menus')->where('code',$code);
            if (! $query->exists()) $query = DB::table('access_menus')->where('path',$path);
            if (! $query->exists()) continue;
            $payload = [];
            foreach ([
                'permission_view' => $permission.'.view',
                'permission_create' => $permission.'.create',
                'permission_update' => $permission.'.update',
                'permission_delete' => $permission.'.delete',
            ] as $column => $value) {
                if (Schema::hasColumn('access_menus', $column)) $payload[$column] = $value;
            }
            if (Schema::hasColumn('access_menus','is_active')) $payload['is_active'] = true;
            if (Schema::hasColumn('access_menus','updated_at')) $payload['updated_at'] = now();
            if ($payload === []) continue;
            $query->update($payload);
        }
    }
    public function down(): void { /* non-destructive Access Matrix guard */ }
};
