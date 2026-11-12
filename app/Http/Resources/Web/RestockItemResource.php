<?php

namespace App\Http\Resources\Web;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestockItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'restock_id'         => $this->restock_id,
            'product_id'         => $this->product_id,
            'unit_id'            => $this->unit_id,
            'requested_quantity' => $this->requested_quantity,
            'fulfilled_quantity' => $this->fulfilled_quantity,

            'product' => $this->whenLoaded('product', fn () => [
                'id'   => $this->product->id,
                'name' => $this->product->name,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => new UnitResource($this->unit)),
        ];
    }
}
