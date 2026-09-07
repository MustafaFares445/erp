<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditNoteStockConsequence: string
{
    case GoodsReturned = 'goods_returned';
    case CustomerRetained = 'customer_retained';
    case NotApplicable = 'not_applicable';

    public function requiresReturnLink(): bool
    {
        return $this === self::GoodsReturned;
    }

    public function label(): string
    {
        return match ($this) {
            self::GoodsReturned => 'Goods returned',
            self::CustomerRetained => 'Customer retained goods',
            self::NotApplicable => 'Not applicable',
        };
    }
}
