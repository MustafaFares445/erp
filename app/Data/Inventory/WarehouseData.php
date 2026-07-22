<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use Spatie\LaravelData\Data;

/**
 * Single source of truth for warehouse validation rules (plan §2.5), shared
 * by the Filament form and, in the future, an API Form Request.
 *
 * `code` uniqueness is deliberately **not** included here: "ignore the
 * current record on edit" is inherently channel-specific (Filament resolves
 * it automatically from its bound resource model via
 * `TextInput::unique(ignoreRecord: true)`; a future API request would need
 * the route-bound model instead). Each channel layers its own `unique` rule
 * on top of the shape rules below, so the shared source stays honest about
 * what it can and cannot own.
 *
 * @see /specs/002-warehouses-stock-visibility/contracts/warehouse-resource.md
 */
final class WarehouseData extends Data
{
    public function __construct(
        public string $name,
        public string $code,
        public ?string $address,
        public bool $is_active,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
