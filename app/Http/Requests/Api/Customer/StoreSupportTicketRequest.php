<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Customer;

use App\Enums\TicketCustomerImpact;
use App\Enums\TicketType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TicketType::class)],
            'customer_impact' => ['nullable', Rule::enum(TicketCustomerImpact::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'serialized_inventory_unit_id' => ['nullable', 'integer'],
            'external_equipment_name' => ['nullable', 'string', 'max:255'],
            'external_equipment_model' => ['nullable', 'string', 'max:255'],
            'external_serial_number' => ['nullable', 'string', 'max:255'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}
