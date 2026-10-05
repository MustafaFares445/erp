<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Customer;

use App\Enums\TicketCustomerImpact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductQualityComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'customer_impact' => ['nullable', Rule::enum(TicketCustomerImpact::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'product_contexts' => ['required', 'array', 'min:1', 'max:20'],
            'product_contexts.*.original_inventory_operation_line_id' => ['required', 'integer'],
            'product_contexts.*.quantity' => ['required', 'numeric', 'gt:0'],
            'product_contexts.*.notes' => ['nullable', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}
