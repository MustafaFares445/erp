<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Inventory\ResolvedPrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use Illuminate\Database\Eloquent\Model;

final readonly class PriceExplanationService
{
    public function __construct(private PriceResolver $priceResolver) {}

    /**
     * Current-policy explanation. Historical documents should call
     * {@see stored()} so later tier edits cannot rewrite their evidence.
     *
     * @return array<string, mixed>
     */
    public function explain(ProductVariant $variant, ?User $customer = null): array
    {
        $candidates = $this->priceResolver->candidates($variant, $customer);
        $winner = $candidates[0];

        return [
            'winner' => $this->describe($winner, true),
            'candidates' => array_map(
                fn (ResolvedPrice $candidate, int $index): array => $this->describe($candidate, $index === 0),
                $candidates,
                array_keys($candidates),
            ),
            'floor_price_minor' => $winner->floorPriceMinor,
            'is_below_floor' => $winner->isBelowFloor,
        ];
    }

    /** @return array<string, mixed> */
    public function stored(Model $line): array
    {
        $source = $line->getAttribute('resolved_price_source');

        return [
            'source' => $source instanceof \BackedEnum ? $source->value : $source,
            'tier_id' => $line->getAttribute('resolved_price_tier_id'),
            'price_floor_override_id' => $line->getAttribute('price_floor_override_id'),
            'list_price_minor' => $line->getAttribute('list_price_minor'),
            'floor_price_minor' => $line->getAttribute('floor_price_minor'),
            'unit_price_minor' => self::minor((float) ($line->getAttribute('unit_price') ?? 0)),
            'historical_snapshot' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function describe(ResolvedPrice $price, bool $selected): array
    {
        return [
            'selected' => $selected,
            'source' => $price->source->value,
            'tier_id' => $price->tierId,
            'tier_name' => $price->pricingTier?->name,
            'amount_minor' => self::minor($price->amount),
            'list_price_minor' => $price->listPriceMinor,
            'discount_amount_minor' => self::minor($price->discountAmount),
            'discount_type' => $price->discountType?->value,
            'discount_value' => $price->discountValue,
            'floor_price_minor' => $price->floorPriceMinor,
            'is_below_floor' => $price->isBelowFloor,
        ];
    }

    private static function minor(float $amount): int
    {
        return max(0, (int) round($amount * 100));
    }
}
