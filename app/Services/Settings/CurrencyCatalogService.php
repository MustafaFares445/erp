<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Currency;
use Illuminate\Validation\ValidationException;

final class CurrencyCatalogService
{
    private ?string $defaultCodeCache = null;

    /** @return array<string, string> */
    public function activeOptions(): array
    {
        return Currency::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get(['code', 'name'])
            ->mapWithKeys(static fn (Currency $currency): array => [
                $currency->code => $currency->code.' — '.$currency->name,
            ])
            ->all();
    }

    public function defaultCode(): string
    {
        if ($this->defaultCodeCache !== null) {
            return $this->defaultCodeCache;
        }

        $code = Currency::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('code');

        return $this->defaultCodeCache = is_string($code) && $code !== '' ? $code : 'AED';
    }

    public function normalizeActive(string $code, string $field = 'currency'): string
    {
        $normalized = mb_strtoupper(mb_trim($code));

        if ($normalized === '' || ! Currency::query()->where('code', $normalized)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                $field => __('admin.currencies.validation.active'),
            ]);
        }

        return $normalized;
    }

    /** @return array<string, string> */
    public function baseOptions(): array
    {
        $code = $this->defaultCode();
        $name = Currency::query()->where('code', $code)->value('name');

        return [$code => $code.' — '.(is_string($name) && $name !== '' ? $name : $code)];
    }

    public function normalizeBase(string $code, string $field = 'currency'): string
    {
        $normalized = $this->normalizeActive($code, $field);
        $base = $this->defaultCode();

        if ($normalized !== $base) {
            throw ValidationException::withMessages([
                $field => "Accounting postings currently support only the base currency {$base}.",
            ]);
        }

        return $normalized;
    }
}
