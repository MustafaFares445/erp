<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\ResolvedPriceSource;
use Illuminate\Database\Eloquent\Model;

trait CarriesPriceProvenance
{
    /** @return array<string, string> */
    public function priceProvenanceCasts(): array
    {
        return [
            'resolved_price_source' => ResolvedPriceSource::class,
            'resolved_price_tier_id' => 'integer',
            'price_floor_override_id' => 'integer',
            'list_price_minor' => 'integer',
            'floor_price_minor' => 'integer',
        ];
    }

    /**
     * @return array{
     *     resolved_price_source: ResolvedPriceSource|null,
     *     resolved_price_tier_id: int|null,
     *     price_floor_override_id: int|null,
     *     list_price_minor: int|null,
     *     floor_price_minor: int|null
     * }
     */
    public function priceProvenanceAttributes(): array
    {
        $source = $this->getAttribute('resolved_price_source');
        $tierId = $this->getAttribute('resolved_price_tier_id');
        $floorOverrideId = $this->getAttribute('price_floor_override_id');
        $listPriceMinor = $this->getAttribute('list_price_minor');
        $floorPriceMinor = $this->getAttribute('floor_price_minor');

        return [
            'resolved_price_source' => $source instanceof ResolvedPriceSource ? $source : null,
            'resolved_price_tier_id' => is_int($tierId) ? $tierId : null,
            'price_floor_override_id' => is_int($floorOverrideId) ? $floorOverrideId : null,
            'list_price_minor' => is_int($listPriceMinor) ? $listPriceMinor : null,
            'floor_price_minor' => is_int($floorPriceMinor) ? $floorPriceMinor : null,
        ];
    }

    public function copyPriceProvenanceFrom(Model $source): void
    {
        $this->forceFill([
            'resolved_price_source' => $source->getAttribute('resolved_price_source'),
            'resolved_price_tier_id' => $source->getAttribute('resolved_price_tier_id'),
            'price_floor_override_id' => $source->getAttribute('price_floor_override_id'),
            'list_price_minor' => $source->getAttribute('list_price_minor'),
            'floor_price_minor' => $source->getAttribute('floor_price_minor'),
        ]);

        if ($this->exists) {
            $this->save();
        }
    }
}
