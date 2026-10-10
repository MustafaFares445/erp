<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InventoryReturnStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Posted, self::Cancelled], true);
    }

    public function label(): string
    {
        return __('admin.inventory.return_status.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
