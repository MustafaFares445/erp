<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EmployeeInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(['customer', 'lead'])],
            'subject_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::enum(InteractionType::class)],
            'direction' => ['required', Rule::enum(InteractionDirection::class)],
            'occurred_at' => ['nullable', 'date'],
            'summary' => ['required', 'string', 'max:2000'],
            'outcome' => ['nullable', Rule::enum(InteractionOutcome::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'customer_visit_id' => ['nullable', 'integer', 'exists:customer_visits,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
        ];
    }
}
