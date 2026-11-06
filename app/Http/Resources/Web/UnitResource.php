<?php

namespace App\Http\Resources\Web;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'symbol'     => $this->symbol,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by' => new AvatarResource($this->whenLoaded('creator')),
            'updated_by' => new AvatarResource($this->whenLoaded('updater')),
        ];
    }
}
