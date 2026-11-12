<?php

namespace App\Http\Resources\Web;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'inventory_transfer_id'   => $this->inventory_transfer_id,
            'stock_id'                => $this->stock_id,
            'restock_item_id'         => $this->restock_item_id,
            'unit_id'                 => $this->whenLoaded('stock', fn () => $this->stock?->unit_id),
            'quantity'                => $this->quantity,
            'stock'                   => new StockResource($this->whenLoaded('stock')),
        ];
    }
}
