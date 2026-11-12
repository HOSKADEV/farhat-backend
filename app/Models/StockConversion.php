<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Traits\HasUserstamps;

class StockConversion extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'inventory_id',
        'product_id',
        'from_stock_id',
        'to_stock_id',
        'from_unit_id',
        'to_unit_id',
        'from_factor',
        'to_factor',
        'from_quantity',
        'to_quantity',
        'unit_cost',
        'total_cost',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_factor'   => 'decimal:4',
            'to_factor'     => 'decimal:4',
            'from_quantity' => 'float',
            'to_quantity'   => 'float',
            'unit_cost'     => 'decimal:2',
            'total_cost'    => 'decimal:2',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fromStock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'from_stock_id');
    }

    public function toStock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'to_stock_id');
    }

    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'source', 'source_type', 'source_id');
    }
}
