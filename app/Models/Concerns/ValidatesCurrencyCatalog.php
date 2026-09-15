<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Settings\CurrencyCatalogService;

trait ValidatesCurrencyCatalog
{
    protected function validateActiveCurrency(string $field): void
    {
        if (! $this->isDirty($field)) {
            return;
        }

        $value = $this->getAttribute($field);
        if (! is_string($value)) {
            return;
        }

        $this->setAttribute(
            $field,
            app(CurrencyCatalogService::class)->normalizeActive($value, $field),
        );
    }
}
