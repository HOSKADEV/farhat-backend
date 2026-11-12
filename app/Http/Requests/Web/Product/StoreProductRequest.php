<?php

namespace App\Http\Requests\Web\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type_id'      => ['nullable', 'integer', 'exists:types,id'],
            'color_id'     => ['nullable', 'integer', 'exists:colors,id'],
            'brand_id'     => ['nullable', 'integer', 'exists:brands,id'],
            'name'         => ['required', 'string', 'max:255'],
            'base_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'barcode'      => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
            'image'        => ['nullable', 'image', 'max:5120'],
            'min_quantity' => ['nullable', 'integer', 'min:0'],

            'unit_conversions'            => ['sometimes', 'array'],
            'unit_conversions.*.unit_id'  => ['required', 'integer', 'distinct', 'exists:units,id'],
            'unit_conversions.*.factor'   => ['required', 'numeric', 'gt:0'],
        ];
    }
}
