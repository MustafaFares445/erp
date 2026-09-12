<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CustomerQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:4000'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
