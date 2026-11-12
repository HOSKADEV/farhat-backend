<?php

namespace App\Http\Requests\Web\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_id'                   => ['nullable', 'integer', 'exists:inventories,id'],
            'items'                          => ['nullable', 'array'],
            'items.*.purchase_item_id'       => ['nullable', 'integer', 'exists:purchase_items,id', 'distinct:strict', 'required_without:items.*.product_id'],
            'items.*.product_id'             => ['nullable', 'integer', 'exists:products,id', 'required_without:items.*.purchase_item_id'],
            'items.*.selling_prices'          => ['nullable', 'array'],
            'items.*.selling_prices.*'        => ['numeric', 'gt:0'],
            'items.*.installment_prices'      => ['nullable', 'array'],
            'items.*.installment_prices.*'    => ['numeric', 'gt:0'],
        ];
    }
}
