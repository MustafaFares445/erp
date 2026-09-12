<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class IssueTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:120'],
        ];
    }
}
