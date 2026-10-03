<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a string-backed enum a locale-aware label read from
 * `lang/{locale}/enums.php` under `<snake_case_enum_name>.<case value>`.
 *
 * Dots in case values are replaced with underscores because Laravel treats
 * dots as nested-key separators (e.g. `invoice.issued` => `invoice_issued`).
 *
 * Also satisfies Filament's `HasLabel` contract so badges, selects and filters
 * bound to enum casts render the translated label automatically.
 */
trait HasTranslatedLabel
{
    public function label(): string
    {
        return __('enums.'.Str::snake(class_basename(static::class)).'.'.str_replace('.', '_', $this->value));
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
