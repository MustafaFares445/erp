<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/** How a product / lot quality complaint was resolved. */
enum QualityResolutionType: string implements HasLabel
{
    use HasTranslatedLabel;

    case NoDefectFound = 'no_defect_found';
    case Replacement = 'replacement';
    case CustomerReturn = 'customer_return';
    case Refund = 'refund';
    case CreditNote = 'credit_note';
    case SupplierClaim = 'supplier_claim';
    case LotInvestigation = 'lot_investigation';

    /** Resolutions that may reference an existing customer return request. */
    public function mayLinkReturnRequest(): bool
    {
        return in_array($this, [self::CustomerReturn, self::Refund, self::CreditNote, self::Replacement], true);
    }
}
