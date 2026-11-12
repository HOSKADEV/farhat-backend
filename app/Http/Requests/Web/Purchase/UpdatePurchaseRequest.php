<?php

namespace App\Http\Requests\Web\Purchase;

use App\Http\Requests\Web\Concerns\ValidatesProductUnitLines;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequest extends FormRequest
{
    use ValidatesProductUnitLines;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id'            => ['sometimes', 'required', 'integer', 'exists:branches,id'],
            'supplier_id'          => ['sometimes', 'required', 'integer', 'exists:suppliers,id'],
            'note'                 => ['sometimes', 'nullable', 'string'],
            'items'                => [
                'sometimes',
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail) {
                    $this->validateUniqueProductUnits($value, $fail, 'purchases.duplicate_product_unit');
                },
            ],
            'items.*.product_id'   => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.unit_id'      => ['required_with:items', 'integer', 'exists:units,id'],
            'items.*.quantity'     => ['required_with:items', 'integer', 'min:1'],
            'items.*.price'        => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
