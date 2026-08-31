<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only write boundary for a variant's permitted UOMs and conversion rules.
 */
final class ProductVariantUomService
{
    /**
     * @param  array<mixed>  $definitions
     */
    public function sync(ProductVariant $variant, array $definitions): ProductVariant
    {
        $variantId = $variant->getKey();

        if (! is_int($variantId)) {
            throw new \LogicException('A variant UOM configuration requires an integer variant ID.');
        }

        return DB::transaction(function () use ($variantId, $definitions): ProductVariant {
            /** @var ProductVariant $lockedVariant */
            $lockedVariant = ProductVariant::query()
                ->with('product')
                ->lockForUpdate()
                ->findOrFail($variantId);

            $configuration = $this->normalizeDefinitions($definitions);
            $units = $this->loadUnits(array_keys($configuration));
            $baseUnitId = $this->validateConfiguration($configuration, $units);

            /** @var Collection<int, ProductVariantUnit> $existingConfigurations */
            $existingConfigurations = $lockedVariant->variantUnits()
                ->lockForUpdate()
                ->get()
                ->keyBy('unit_id');

            $this->assertHistoryCompatibility($lockedVariant, $existingConfigurations, $baseUnitId);
            $this->retireRemovedConfigurations($existingConfigurations, $configuration);
            $this->demotePreviousBase($existingConfigurations, $baseUnitId);
            $this->upsertConfigurations($lockedVariant, $existingConfigurations, $configuration);

            $lockedVariant->update(['unit_id' => $baseUnitId]);

            if (! $lockedVariant->product instanceof Product) {
                throw new \LogicException('A variant UOM configuration requires a product.');
            }

            foreach ($units as $unit) {
                $lockedVariant->product->addAllowedUnit($unit);
            }

            return $lockedVariant->refresh();
        }, attempts: 5);
    }

    /**
     * @param  array<mixed>  $definitions
     * @return array<int, array{unit_id: int, is_base: bool, is_purchase: bool, is_sale: bool, is_display: bool, factor_to_base: numeric-string, rounding_increment: numeric-string, permits_cross_family_conversion: bool, is_active: bool}>
     */
    private function normalizeDefinitions(array $definitions): array
    {
        if ($definitions === []) {
            throw ValidationException::withMessages([
                'variant_uoms' => 'At least one active unit configuration is required.',
            ]);
        }

        $normalized = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! isset($definition['unit_id']) || ! is_numeric($definition['unit_id'])) {
                throw ValidationException::withMessages([
                    'variant_uoms' => 'Each unit configuration must name a unit.',
                ]);
            }

            $unitId = (int) $definition['unit_id'];

            if ($unitId < 1 || array_key_exists($unitId, $normalized)) {
                throw ValidationException::withMessages([
                    'variant_uoms' => 'Each unit can be configured only once per variant.',
                ]);
            }

            $normalized[$unitId] = [
                'unit_id' => $unitId,
                'is_base' => $this->boolean($definition['is_base'] ?? false, 'is_base'),
                'is_purchase' => $this->boolean($definition['is_purchase'] ?? false, 'is_purchase'),
                'is_sale' => $this->boolean($definition['is_sale'] ?? false, 'is_sale'),
                'is_display' => $this->boolean($definition['is_display'] ?? false, 'is_display'),
                'factor_to_base' => $this->positiveDecimal($definition['factor_to_base'] ?? null, 'factor_to_base'),
                'rounding_increment' => $this->positiveDecimal($definition['rounding_increment'] ?? null, 'rounding_increment'),
                'permits_cross_family_conversion' => $this->boolean($definition['permits_cross_family_conversion'] ?? false, 'permits_cross_family_conversion'),
                'is_active' => $this->boolean($definition['is_active'] ?? true, 'is_active'),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<int>  $unitIds
     * @return Collection<int, Unit>
     */
    private function loadUnits(array $unitIds): Collection
    {
        /** @var Collection<int, Unit> $units */
        $units = Unit::query()->whereKey($unitIds)->get()->keyBy('id');

        if ($units->count() !== count($unitIds)) {
            throw ValidationException::withMessages([
                'variant_uoms' => 'Every configured unit must exist.',
            ]);
        }

        return $units;
    }

    /**
     * @param  array<int, array{unit_id: int, is_base: bool, is_purchase: bool, is_sale: bool, is_display: bool, factor_to_base: numeric-string, rounding_increment: numeric-string, permits_cross_family_conversion: bool, is_active: bool}>  $configuration
     * @param  Collection<int, Unit>  $units
     */
    private function validateConfiguration(array $configuration, Collection $units): int
    {
        $baseConfigurations = array_filter(
            $configuration,
            static fn (array $definition): bool => $definition['is_base'] && $definition['is_active'],
        );

        if (count($baseConfigurations) !== 1) {
            throw ValidationException::withMessages([
                'variant_uoms' => 'Exactly one active base unit is required.',
            ]);
        }

        $baseConfiguration = array_values($baseConfigurations)[0];
        $baseUnitId = $baseConfiguration['unit_id'];
        $baseUnit = $units->get($baseUnitId);

        if (! $baseUnit instanceof Unit || ! $baseUnit->is_active) {
            throw ValidationException::withMessages([
                'variant_uoms' => 'The base unit must be active.',
            ]);
        }

        if (bccomp($baseConfiguration['factor_to_base'], '1', 6) !== 0) {
            throw ValidationException::withMessages([
                'factor_to_base' => 'The base unit must use a conversion factor of 1.',
            ]);
        }

        foreach ($configuration as $definition) {
            $unit = $units->get($definition['unit_id']);

            if (! $unit instanceof Unit || ! $unit->is_active) {
                throw ValidationException::withMessages([
                    'variant_uoms' => 'Every configured unit must be active.',
                ]);
            }

            $this->assertUnitPrecision($unit);
            $this->assertWithinPrecision($definition['rounding_increment'], $unit->precision, 'rounding_increment');

            if (! $definition['permits_cross_family_conversion'] && $unit->family !== $baseUnit->family) {
                throw ValidationException::withMessages([
                    'variant_uoms' => 'A conversion across unit families requires an explicit variant conversion.',
                ]);
            }
        }

        return $baseUnitId;
    }

    /**
     * @param  Collection<int, ProductVariantUnit>  $existingConfigurations
     */
    private function assertHistoryCompatibility(ProductVariant $variant, Collection $existingConfigurations, int $baseUnitId): void
    {
        if (! $variant->hasStockHistory()) {
            return;
        }

        $existingBases = $existingConfigurations
            ->filter(static fn (ProductVariantUnit $configuration): bool => $configuration->is_base && $configuration->is_active);

        if ($existingBases->count() !== 1) {
            throw ValidationException::withMessages([
                'variant_uoms' => 'The existing variant configuration has no unambiguous active base unit.',
            ]);
        }

        $existingBase = $existingBases->firstOrFail();

        if ($existingBase->unit_id === $baseUnitId) {
            return;
        }

        throw ValidationException::withMessages([
            'variant_uoms' => 'The base unit cannot change after this variant has stock history.',
        ]);
    }

    /**
     * @param  Collection<int, ProductVariantUnit>  $existingConfigurations
     * @param  array<int, array{is_active: bool}>  $configuration
     */
    private function retireRemovedConfigurations(Collection $existingConfigurations, array $configuration): void
    {
        foreach ($existingConfigurations as $unitId => $existingConfiguration) {
            $definition = $configuration[$unitId] ?? null;

            if ($definition !== null && $definition['is_active']) {
                continue;
            }

            $existingConfiguration->update([
                'is_base' => false,
                'is_active' => false,
                'retired_at' => now(),
            ]);
        }
    }

    /**
     * @param  Collection<int, ProductVariantUnit>  $existingConfigurations
     */
    private function demotePreviousBase(Collection $existingConfigurations, int $baseUnitId): void
    {
        foreach ($existingConfigurations as $unitId => $existingConfiguration) {
            if (! $existingConfiguration->is_base || (int) $unitId === $baseUnitId) {
                continue;
            }

            $existingConfiguration->update(['is_base' => false]);
        }
    }

    /**
     * @param  Collection<int, ProductVariantUnit>  $existingConfigurations
     * @param  array<int, array{unit_id: int, is_base: bool, is_purchase: bool, is_sale: bool, is_display: bool, factor_to_base: numeric-string, rounding_increment: numeric-string, permits_cross_family_conversion: bool, is_active: bool}>  $configuration
     */
    private function upsertConfigurations(ProductVariant $variant, Collection $existingConfigurations, array $configuration): void
    {
        foreach ($configuration as $unitId => $definition) {
            $attributes = [
                'is_base' => $definition['is_base'],
                'is_purchase' => $definition['is_purchase'],
                'is_sale' => $definition['is_sale'],
                'is_display' => $definition['is_display'],
                'factor_to_base' => $definition['factor_to_base'],
                'rounding_increment' => $definition['rounding_increment'],
                'permits_cross_family_conversion' => $definition['permits_cross_family_conversion'],
                'is_active' => $definition['is_active'],
                'retired_at' => $definition['is_active'] ? null : now(),
            ];

            $existingConfiguration = $existingConfigurations->get($unitId);

            if ($existingConfiguration instanceof ProductVariantUnit) {
                $existingConfiguration->update($attributes);

                continue;
            }

            $variant->variantUnits()->create([
                'unit_id' => $definition['unit_id'],
                ...$attributes,
                'effective_from' => now(),
            ]);
        }
    }

    private function boolean(mixed $value, string $field): bool
    {
        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (is_bool($boolean)) {
            return $boolean;
        }

        throw ValidationException::withMessages([$field => 'The value must be true or false.']);
    }

    /** @return numeric-string */
    private function positiveDecimal(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([$field => 'The value must be a positive decimal.']);
        }

        $decimal = (string) $value;

        if (! is_numeric($decimal) || ! preg_match('/^\d+(?:\.\d{1,6})?$/', $decimal) || bccomp($decimal, '0', 6) !== 1) {
            throw ValidationException::withMessages([$field => 'The value must be a positive decimal with at most six decimal places.']);
        }

        return $decimal;
    }

    private function assertUnitPrecision(Unit $unit): void
    {
        if ($unit->precision < 0 || $unit->precision > 6 || (! $unit->allows_decimal && $unit->precision !== 0)) {
            throw ValidationException::withMessages([
                'variant_uoms' => 'Each unit must have a valid precision between zero and six decimal places.',
            ]);
        }
    }

    /** @param numeric-string $value */
    private function assertWithinPrecision(string $value, int $precision, string $field): void
    {
        if (bccomp($value, bcadd($value, '0', $precision), 12) !== 0) {
            throw ValidationException::withMessages([
                $field => 'The value exceeds the configured unit precision.',
            ]);
        }
    }
}
