<?php

namespace App\Http\Requests\Web\Purchase;

use App\Http\Requests\Web\Concerns\ValidatesProductUnitLines;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    use ValidatesProductUnitLines;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id'            => ['required', 'integer', 'exists:branches,id'],
            'supplier_id'          => ['required', 'integer', 'exists:suppliers,id'],
            'note'                 => ['nullable', 'string'],
            'items'                => [
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail) {
                    $this->validateUniqueProductUnits($value, $fail, 'purchases.duplicate_product_unit');
                },
            ],
            'items.*.product_id'   => ['required', 'integer', 'exists:products,id'],
            'items.*.unit_id'      => ['required', 'integer', 'exists:units,id'],
            'items.*.quantity'     => ['required', 'integer', 'min:1'],
            'items.*.price'        => ['required', 'numeric', 'min:0'],
        ];
    }
}
