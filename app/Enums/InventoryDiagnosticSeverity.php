<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How serious one Inventory View diagnostic finding is.
 *
 * Blocker is reserved for conditions the data proves: the workflow cannot proceed, or the
 * stored balances contradict each other. Warning means a real risk that needs review. Notice is
 * workflow guidance (the next step of an open document). Clear is only used for the overall
 * verdict when every listed check passed, and never claims more than those checks prove.
 */
enum InventoryDiagnosticSeverity: string implements HasColor, HasLabel
{
    case Blocker = 'blocker';
    case Warning = 'warning';
    case Notice = 'notice';
    case Clear = 'clear';

    public function rank(): int
    {
        return match ($this) {
            self::Blocker => 4,
            self::Warning => 3,
            self::Notice => 2,
            self::Clear => 1,
        };
    }

    public function label(): string
    {
        return __('inventory_view_ux.severity.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->tone();
    }

    /**
     * The `data-tone` of the shared `ierp-*` components (see components.css).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Blocker => 'danger',
            self::Warning => 'warning',
            self::Notice => 'info',
            self::Clear => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Blocker => 'heroicon-o-no-symbol',
            self::Warning => 'heroicon-o-exclamation-triangle',
            self::Notice => 'heroicon-o-information-circle',
            self::Clear => 'heroicon-o-check-circle',
        };
    }
}
