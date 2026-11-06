<?php

namespace App\Http\Requests\Web\Unit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function rules(): array
    {
        $unitId = $this->route('unit')?->id;

        return [
            'name'   => ['required', 'string', 'max:255', Rule::unique('units', 'name')->ignore($unitId)],
            'symbol' => ['required', 'string', 'max:20', Rule::unique('units', 'symbol')->ignore($unitId)],
        ];
    }
}
