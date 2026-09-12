<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class EmployeeVanSaleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customer_profiles,id'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'products.*.quantity' => ['required', 'numeric', 'gt:0'],
            'products.*.inventory_lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'products.*.serialized_inventory_unit_ids' => ['nullable', 'array'],
            'products.*.serialized_inventory_unit_ids.*' => ['integer', 'exists:serialized_inventory_units,id'],
        ];
    }
}
