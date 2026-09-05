<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CreditNote;

/**
 * Why a {@see CreditNote} was raised, classified separately from
 * its free-text `reason` explanation so credit activity can be reported on
 * without parsing prose.
 */
enum CreditNoteReason: string
{
    case SalesReturn = 'sales_return';
    case PricingAdjustment = 'pricing_adjustment';
    case TaxAdjustment = 'tax_adjustment';
    case CommercialDiscount = 'commercial_discount';
    case Other = 'other';

    public function label(): string
    {
        return __('admin.sales.credit_note_reason.'.$this->value);
    }
}
