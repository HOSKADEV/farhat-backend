<?php

namespace App\Http\Requests\Web\Stock;

use Illuminate\Foundation\Http\FormRequest;

class ConvertStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'product_id'   => ['required', 'integer', 'exists:products,id'],
            'from_unit_id' => ['required', 'integer', 'exists:units,id'],
            'to_unit_id'   => ['required', 'integer', 'different:from_unit_id', 'exists:units,id'],
            'quantity'     => ['required', 'numeric', 'gt:0'],
            'unit_cost'    => ['nullable', 'numeric', 'min:0'],
            'note'         => ['nullable', 'string', 'max:2000'],
        ];
    }
}
