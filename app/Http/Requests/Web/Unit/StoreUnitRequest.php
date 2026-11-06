<?php

namespace App\Http\Requests\Web\Unit;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'   => ['required', 'string', 'max:255', 'unique:units,name'],
            'symbol' => ['required', 'string', 'max:20', 'unique:units,symbol'],
        ];
    }
}
