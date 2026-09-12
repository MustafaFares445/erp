<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class EmployeeVoiceNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'audio' => ['required', 'file', 'mimes:mp3,m4a,wav,webm,ogg', 'max:25600'],
            'language' => ['nullable', 'string', 'max:16'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:14400'],
        ];
    }
}
