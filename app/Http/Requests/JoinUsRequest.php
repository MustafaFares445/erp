<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class JoinUsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'string', 'email', 'max:255'],
            'company_phone' => ['required', 'string', 'max:50'],

            'country' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            'accountant_name' => ['nullable', 'string', 'max:255'],
            'accountant_phone' => ['nullable', 'string', 'max:50'],
            'accountant_email' => ['nullable', 'string', 'email', 'max:255'],

            'contact_is_self' => ['required', 'boolean'],
            'contact_name' => ['required_if:contact_is_self,0', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['required_if:contact_is_self,0', 'nullable', 'string', 'max:50'],
            'contact_email' => ['required_if:contact_is_self,0', 'nullable', 'string', 'email', 'max:255'],

            'license' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'tax_certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'passport' => ['required', 'image', 'max:5120'],
            'personal_identity' => ['required', 'image', 'max:5120'],
            'accommodation' => ['required', 'image', 'max:5120'],
        ];
    }
}
