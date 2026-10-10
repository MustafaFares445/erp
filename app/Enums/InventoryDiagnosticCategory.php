<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The operational area an Inventory View diagnostic finding belongs to.
 */
enum InventoryDiagnosticCategory: string implements HasLabel
{
    case Availability = 'availability';
    case Replenishment = 'replenishment';
    case Expiry = 'expiry';
    case Tracking = 'tracking';
    case Transfer = 'transfer';
    case Reservation = 'reservation';
    case Count = 'count';
    case Delivery = 'delivery';
    case Receipt = 'receipt';
    case Custody = 'custody';
    case Condition = 'condition';
    case Warranty = 'warranty';
    case Maintenance = 'maintenance';
    case Approval = 'approval';
    case Workflow = 'workflow';
    case DataQuality = 'data_quality';

    public function label(): string
    {
        return __('inventory_view_ux.category.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
