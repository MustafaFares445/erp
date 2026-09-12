<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class EmployeeOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'max:4000'],
            'customer_id' => ['nullable', 'integer', 'exists:customer_profiles,id', 'required_without:lead_id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id', 'required_without:customer_id'],
            'title' => ['nullable', 'string', 'max:255'],
            'estimated_value_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expected_close_date' => ['nullable', 'date'],
            'probability_percent' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
