<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryConditionChangeType: string
{
    case QuarantineDisposition = 'quarantine_disposition';
    case Damage = 'damage';
    case DamageRecovery = 'damage_recovery';
    case Disposal = 'disposal';

    /**
     * The stock condition this type's posting moves FROM, for the fixed
     * damage-family transitions. Quarantine dispositions have no fixed
     * transition: their `condition_to` is driven by the disposition value.
     */
    public function conditionFrom(): ?StockCondition
    {
        return match ($this) {
            self::Damage => StockCondition::Saleable,
            self::DamageRecovery, self::Disposal => StockCondition::Damaged,
            self::QuarantineDisposition => null,
        };
    }

    /** @see self::conditionFrom() */
    public function conditionTo(): ?StockCondition
    {
        return match ($this) {
            self::Damage => StockCondition::Damaged,
            self::DamageRecovery => StockCondition::Saleable,
            self::Disposal => StockCondition::Disposed,
            self::QuarantineDisposition => null,
        };
    }

    public function movementType(): ?MovementType
    {
        return match ($this) {
            self::Damage => MovementType::Damage,
            self::DamageRecovery => MovementType::DamageRecovery,
            self::Disposal => MovementType::Disposal,
            self::QuarantineDisposition => null,
        };
    }

    public function isDamageFamily(): bool
    {
        return in_array($this, [self::Damage, self::DamageRecovery, self::Disposal], true);
    }
}
