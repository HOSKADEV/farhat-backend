<?php

namespace App\Http\Requests\Web\Restock;

use Illuminate\Foundation\Http\FormRequest;

class FulfillRestockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'              => ['required', 'string', 'in:purchase,transfer,none'],
            'supplier_id'       => ['required_if:type,purchase', 'integer', 'exists:suppliers,id'],
            'from_inventory_id' => ['required_if:type,transfer', 'integer', 'exists:inventories,id'],
            'note'              => ['nullable', 'string', 'max:65535'],
            'items'              => ['required_if:type,purchase', 'array', 'min:1'],
            'items.*.restock_item_id' => ['nullable', 'integer', 'exists:restock_items,id', 'distinct:strict', 'required_without:items.*.product_id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id', 'required_without:items.*.restock_item_id'],
            'items.*.unit_id'    => ['nullable', 'integer', 'exists:units,id'],
            'items.*.price'      => ['required_if:type,purchase', 'numeric', 'min:0'],
        ];
    }
}
