<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductOperationalProfile: string
{
    case Standard = 'standard';
    case Expiring = 'expiring';
    case Serialized = 'serialized';
    case SerializedServiceable = 'serialized_serviceable';
    case Regulated = 'regulated';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Standard => __('Standard'),
            self::Expiring => __('Expiring'),
            self::Serialized => __('Serialized'),
            self::SerializedServiceable => __('Serialized & serviceable'),
            self::Regulated => __('Regulated'),
            self::Service => __('Service'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $profile): array => [$profile->value => $profile->label()])
            ->all();
    }

    public static function fromLegacyType(ProductType $type): self
    {
        return match ($type) {
            ProductType::Machine => self::Serialized,
            ProductType::ExpiryMaterial => self::Expiring,
            ProductType::Grain => self::Standard,
        };
    }

    public function defaultTrackingMode(): TrackingMode
    {
        return match ($this) {
            self::Expiring, self::Regulated => TrackingMode::Lot,
            self::Serialized, self::SerializedServiceable => TrackingMode::Serial,
            self::Standard, self::Service => TrackingMode::None,
        };
    }

    public function tracksExpirationByDefault(): bool
    {
        return $this === self::Expiring;
    }

    public function serviceableByDefault(): bool
    {
        return $this === self::SerializedServiceable;
    }

    public function warrantyEnabledByDefault(): bool
    {
        return $this === self::SerializedServiceable;
    }

    public function udiEnabledByDefault(): bool
    {
        return $this === self::Regulated;
    }
}
