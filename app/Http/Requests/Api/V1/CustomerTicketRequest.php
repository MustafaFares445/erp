<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TicketType::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'continued_from_ticket_id' => ['nullable', 'integer'],
        ];
    }
}
