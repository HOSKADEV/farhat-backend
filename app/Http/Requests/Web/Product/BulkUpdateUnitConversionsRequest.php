<?php

namespace App\Http\Requests\Web\Product;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateUnitConversionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'products'                                 => ['required', 'array', 'min:1'],
            'products.*.product_id'                    => ['required', 'integer', 'distinct', 'exists:products,id'],
            'products.*.base_unit_id'                  => ['required', 'integer', 'exists:units,id'],
            'products.*.unit_conversions'              => ['required', 'array', 'min:1'],
            'products.*.unit_conversions.*.unit_id'    => ['required', 'integer', 'exists:units,id'],
            'products.*.unit_conversions.*.factor'     => ['required', 'numeric', 'gt:0'],
        ];
    }
}
