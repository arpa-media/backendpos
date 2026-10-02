<?php

namespace App\Models\GeneralAffair;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InventoryDriveFolderMapping extends Model
{
    use HasUlids;

    protected $table = 'ga_inventory_drive_folder_mappings';
    protected $guarded = [];
    protected $casts = [
        'is_active' => 'boolean',
        'last_master_row_count' => 'integer',
        'last_master_generated_at' => 'datetime',
    ];
}
