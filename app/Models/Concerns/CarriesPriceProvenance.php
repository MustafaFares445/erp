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
     *     resolved_price_source:mixed,
     *     resolved_price_tier_id:mixed,
     *     price_floor_override_id:mixed,
     *     list_price_minor:mixed,
     *     floor_price_minor:mixed
     * }
     */
    public function priceProvenanceAttributes(): array
    {
        return [
            'resolved_price_source' => $this->getAttribute('resolved_price_source'),
            'resolved_price_tier_id' => $this->getAttribute('resolved_price_tier_id'),
            'price_floor_override_id' => $this->getAttribute('price_floor_override_id'),
            'list_price_minor' => $this->getAttribute('list_price_minor'),
            'floor_price_minor' => $this->getAttribute('floor_price_minor'),
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
