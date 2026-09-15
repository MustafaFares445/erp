<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Currency;
use Illuminate\Validation\ValidationException;

final class CurrencyCatalogService
{
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
        $code = Currency::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('code');

        return is_string($code) && $code !== '' ? $code : 'AED';
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
}
