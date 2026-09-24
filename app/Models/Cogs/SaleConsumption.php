<?php

namespace App\Models\Cogs;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SaleConsumption extends Model
{
    use HasUlids;

    public const TYPE_CONSUMPTION = 'sale_consumption';
    public const TYPE_REVERSAL = 'sale_consumption_reversal';
    public const TYPE_EXCEPTION = 'exception';

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FAILED = 'failed';

    protected $table = 'cogs_sale_consumptions';

    protected $fillable = [
        'outlet_id',
        'sale_id',
        'sale_item_id',
        'product_id',
        'product_variant_id',
        'recipe_id',
        'movement_type',
        'status',
        'business_date',
        'business_timezone',
        'sale_number_snapshot',
        'sale_status_snapshot',
        'product_name_snapshot',
        'variant_name_snapshot',
        'sold_quantity',
        'recipe_yield_quantity',
        'total_base_quantity',
        'total_cost',
        'movement_count',
        'exception_code',
        'exception_message',
        'event_reason',
        'source_fingerprint',
        'idempotency_key',
        'metadata',
        'processed_at',
        'reversed_at',
        'reversed_by_consumption_id',
        'resolved_at',
        'resolved_by_consumption_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'sold_quantity' => 'decimal:8',
            'recipe_yield_quantity' => 'decimal:8',
            'total_base_quantity' => 'decimal:8',
            'total_cost' => 'decimal:2',
            'movement_count' => 'integer',
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'reversed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    public function recipe()
    {
        return $this->belongsTo(IngredientRecipe::class, 'recipe_id')->withTrashed();
    }

    public function items()
    {
        return $this->hasMany(SaleConsumptionItem::class, 'consumption_id')->orderBy('id');
    }

    public function reversedBy()
    {
        return $this->belongsTo(self::class, 'reversed_by_consumption_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(self::class, 'resolved_by_consumption_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
