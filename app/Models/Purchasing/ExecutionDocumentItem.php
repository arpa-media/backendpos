<?php
namespace App\Models\Purchasing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
class ExecutionDocumentItem extends Model { use HasUlids; protected $guarded=[]; protected function casts(): array { return ['ordered_qty'=>'decimal:4','executed_qty'=>'decimal:4','unit_price'=>'decimal:2','tax_amount'=>'decimal:2','line_total'=>'decimal:2','metadata'=>'array']; } }
