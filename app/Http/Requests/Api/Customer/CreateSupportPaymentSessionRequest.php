<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Customer;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class CreateSupportPaymentSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'success_url' => ['required', 'url:http,https', 'max:2048', $this->allowedRedirectHost(...)],
            'cancel_url' => ['required', 'url:http,https', 'max:2048', $this->allowedRedirectHost(...)],
        ];
    }

    /** Redirect targets are limited to the application host and the configured customer-app hosts. */
    private function allowedRedirectHost(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;
        $allowed = [
            parse_url(config()->string('app.url'), PHP_URL_HOST),
            ...(array) config('support.customer_api_redirect_hosts', []),
        ];

        foreach (array_filter($allowed, is_string(...)) as $candidate) {
            $candidate = mb_strtolower($candidate);

            if (is_string($host) && ($host = mb_strtolower($host)) !== '' && ($host === $candidate || str_ends_with($host, '.'.$candidate))) {
                return;
            }
        }

        $fail('The :attribute host is not allowed.');
    }
}
