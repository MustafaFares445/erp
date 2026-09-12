<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\LeadSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EmployeeLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::enum(LeadSource::class)],
            'source_detail' => ['nullable', 'string', 'max:255'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'preferred_language' => ['nullable', 'string', 'max:16'],
        ];
    }
}
