<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Product;
use App\Observers\ProductVariantObserver;

/**
 * The physical nature of a {@see Product}, and the single source of truth for which
 * tracking rules apply to every variant beneath it.
 *
 * Each case fixes the variant tracking flags rather than leaving them independently
 * editable, so `track_serials`/`track_expiry` can never contradict the product's type:
 *
 * - {@see self::Machine} — serialized equipment. One serial (and optional IoT number)
 *   per physical unit, whole quantities only, never an expiry date.
 * - {@see self::ExpiryMaterial} — consumable material received in batches that expire.
 *   Every receipt records a lot with an expiry date; expired lots cannot leave stock.
 * - {@see self::Grain} — bulk goods sold by weight. Decimal quantities in a weight-bearing
 *   unit, with a net weight per stock unit so weight totals can be derived.
 */
enum ProductType: string
{
    case Machine = 'machine';
    case ExpiryMaterial = 'expiry_material';
    case Grain = 'grain';

    /**
     * The classification rule, in one place. Used by the backfill migration, the catalog
     * import, and the product form so a type is never derived two different ways.
     */
    public static function fromTrackingFlags(bool $tracksSerials, bool $tracksExpiry): self
    {
        if ($tracksSerials) {
            return self::Machine;
        }

        return $tracksExpiry ? self::ExpiryMaterial : self::Grain;
    }

    /**
     * The variant tracking columns this type implies.
     *
     * The single definition of that mapping. Every write surface spreads this into the
     * variant it persists — explicitly, rather than relying on
     * {@see ProductVariantObserver}, because a seeder running under
     * `WithoutModelEvents` never fires the observer. The observer remains as a backstop for
     * paths that forget.
     *
     * @return array{track_serials: bool, track_expiry: bool, track_batches: bool}
     */
    public function trackingFlags(): array
    {
        return [
            'track_serials' => $this->tracksSerials(),
            'track_expiry' => $this->tracksExpiry(),
            'track_batches' => $this->tracksBatches(),
        ];
    }

    /** Machines are identified unit by unit; nothing else is. */
    public function tracksSerials(): bool
    {
        return $this === self::Machine;
    }

    /** Only expiry materials carry an expiry date, and they always do (FR: expiry tracking required). */
    public function tracksExpiry(): bool
    {
        return $this === self::ExpiryMaterial;
    }

    /**
     * Batch/lot identity. Every expiry material carries one, since that is the mechanism its
     * expiry is tracked by — but bulk goods without an expiry, like a sack of dental stone
     * powder, still need to be traceable to the batch they arrived in, so grain carries one too.
     * Only machines, identified unit by unit instead, carry neither.
     */
    public function tracksBatches(): bool
    {
        return $this !== self::Machine;
    }

    /** A fractional machine is meaningless; grains and materials are measured. */
    public function requiresWholeQuantity(): bool
    {
        return $this === self::Machine;
    }

    /** Grains are weighed, so a net weight per stock unit is mandatory. */
    public function requiresWeight(): bool
    {
        return $this === self::Grain;
    }

    public function label(): string
    {
        return __('admin.inventory.product_type.types.'.$this->value);
    }

    public function description(): string
    {
        return __('admin.inventory.product_type.descriptions.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Machine => 'info',
            self::ExpiryMaterial => 'warning',
            self::Grain => 'success',
        };
    }

    /**
     * Narrows a multi-select filter's submitted values to types this application recognises.
     *
     * Filter state arrives as request data, so an unrecognised value is discarded rather than
     * passed into a query — a select's `options()` constrains only what the dropdown shows.
     *
     * The parameter is deliberately `mixed`: this receives raw filter state, which a tampered
     * request can make any shape at all.
     *
     * @return list<string>
     */
    public static function fromFilterValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $types = [];

        foreach ($values as $value) {
            $type = is_string($value) ? self::tryFrom($value) : null;

            if ($type instanceof self) {
                $types[] = $type->value;
            }
        }

        return $types;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
