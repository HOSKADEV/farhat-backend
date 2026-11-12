<?php

namespace App\Http\Requests\Web\Restock;

use App\Http\Requests\Web\Concerns\ValidatesProductUnitLines;
use App\Models\Unit;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreRestockRequest extends FormRequest
{
    use ValidatesProductUnitLines;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id'                    => ['required', 'integer', 'exists:branches,id'],
            'note'                         => ['nullable', 'string', 'max:65535'],
            'items'                        => [
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail) {
                    $this->validateUniqueProductUnits($value, $fail, 'restocks.duplicate_product_unit', Unit::defaultId());
                },
            ],
            'items.*.product_id'           => ['required', 'integer', 'exists:products,id'],
            'items.*.unit_id'              => ['nullable', 'integer', 'exists:units,id'],
            'items.*.requested_quantity'   => ['required', 'integer', 'min:1'],
        ];
    }
}
