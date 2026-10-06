<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ProductOperationalProfile;
use App\Enums\ProductType;
use App\Enums\TrackingMode;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the modern tracking configuration and the legacy tracking projection aligned.
 *
 * New workflows use tracking_mode / tracks_expiration. The existing track_serials,
 * track_batches and track_expiry columns remain projections so older inventory queries and
 * reports keep working during the remediation.
 */
final class ProductVariantObserver
{
    public function updating(ProductVariant $variant): void
    {
        if ($variant->isDirty('unit_id') && $variant->hasStockHistory()) {
            throw ValidationException::withMessages([
                'unit_id' => __('The base unit cannot change after this variant has stock history.'),
            ]);
        }

        if (
            $variant->hasStockHistory()
            && $variant->isDirty(['tracking_mode', 'tracks_expiration', 'track_serials', 'track_batches', 'track_expiry'])
        ) {
            throw ValidationException::withMessages([
                'tracking_mode' => __('Tracking configuration cannot change after this variant has stock history.'),
            ]);
        }
    }

    public function saving(ProductVariant $variant): void
    {
        $product = $this->product($variant);
        $type = $product?->product_type;
        $profile = $product?->operational_profile;

        if (! $profile instanceof ProductOperationalProfile && $type instanceof ProductType) {
            $profile = ProductOperationalProfile::fromLegacyType($type);
        }

        $mode = $variant->tracking_mode;

        if (! $mode instanceof TrackingMode) {
            $legacyMode = $this->legacyTrackingMode($type);
            $mode = $legacyMode !== TrackingMode::None
                ? $legacyMode
                : ($profile?->defaultTrackingMode() ?? TrackingMode::None);
        }

        $tracksExpiration = $variant->tracks_expiration;

        if ($tracksExpiration === null) {
            $tracksExpiration = ($type?->tracksExpiry() ?? false)
                || ($profile?->tracksExpirationByDefault() ?? false);
        }

        if ($tracksExpiration && $mode === TrackingMode::None) {
            $mode = TrackingMode::Lot;
        }

        if (! $variant->exists && $profile instanceof ProductOperationalProfile) {
            if (! $variant->isDirty('serviceable')) {
                $variant->serviceable = $profile->serviceableByDefault();
            }

            if (! $variant->isDirty('warranty_enabled')) {
                $variant->warranty_enabled = $profile->warrantyEnabledByDefault();
            }

            if (! $variant->isDirty('udi_enabled')) {
                $variant->udi_enabled = $profile->udiEnabledByDefault();
            }
        }

        if ($variant->warranty_policy_id !== null || $variant->warranty_duration_value !== null) {
            $variant->warranty_enabled = true;
        }

        $variant->forceFill([
            'tracking_mode' => $mode,
            'tracks_expiration' => $tracksExpiration,
            'track_serials' => $mode === TrackingMode::Serial,
            'track_batches' => $mode === TrackingMode::Lot,
            'track_expiry' => $tracksExpiration,
        ]);
    }

    private function legacyTrackingMode(?ProductType $type): TrackingMode
    {
        if ($type?->tracksSerials() === true) {
            return TrackingMode::Serial;
        }

        if ($type?->tracksBatches() === true) {
            return TrackingMode::Lot;
        }

        return TrackingMode::None;
    }

    private function product(ProductVariant $variant): ?Product
    {
        if ($variant->relationLoaded('product')) {
            return $variant->product;
        }

        return Product::query()->withTrashed()->find($variant->product_id);
    }
}
