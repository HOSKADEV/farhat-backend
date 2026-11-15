<?php

namespace App\Http\Resources\Web;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'inventory_id'  => $this->inventory_id,
            'product_id'    => $this->product_id,
            'from_stock_id' => $this->from_stock_id,
            'to_stock_id'   => $this->to_stock_id,
            'from_unit_id'  => $this->from_unit_id,
            'to_unit_id'    => $this->to_unit_id,
            'from_factor'   => $this->from_factor === null ? null : (float) $this->from_factor,
            'to_factor'     => $this->to_factor === null ? null : (float) $this->to_factor,
            'from_quantity' => (float) $this->from_quantity,
            'to_quantity'   => (float) $this->to_quantity,
            'unit_cost'     => (float) $this->unit_cost,
            'total_cost'    => (float) $this->total_cost,
            'note'          => $this->note,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
            'created_by'    => new AvatarResource($this->whenLoaded('creator')),
            'updated_by'    => new AvatarResource($this->whenLoaded('updater')),

            'product'    => new ProductResource($this->whenLoaded('product')),
            'inventory'  => new InventoryResource($this->whenLoaded('inventory')),
            'from_stock' => new StockResource($this->whenLoaded('fromStock')),
            'to_stock'   => new StockResource($this->whenLoaded('toStock')),
            'from_unit'  => new UnitResource($this->whenLoaded('fromUnit')),
            'to_unit'    => new UnitResource($this->whenLoaded('toUnit')),
            'movements'  => InventoryMovementResource::collection($this->whenLoaded('movements')),
        ];
    }
}
