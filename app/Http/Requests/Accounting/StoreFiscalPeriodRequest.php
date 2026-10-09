<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class StoreFiscalPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route middleware already gates this action; the check is repeated
        // so the request is safe to use from anywhere else.
        return $this->user()?->can('accounting.fiscal_periods.create') ?? false;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'date_lock' => ['nullable', 'date'],
        ];
    }

    /**
     * The attributes the model uses for the lifecycle columns.
     */
    public function periodAttributes(): array
    {
        return $this->only(['name', 'start_date', 'end_date', 'date_lock']);
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'A period cannot end before it starts.',
        ];
    }
}
