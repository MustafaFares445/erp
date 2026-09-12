<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierReturnExpectedOutcome: string
{
    case Replacement = 'replacement';
    case Credit = 'credit';
    case Refund = 'refund';

    public function requiresFinancialCredit(): bool
    {
        return in_array($this, [self::Credit, self::Refund], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Replacement->value => 'Replacement',
            self::Credit->value => 'Supplier credit',
            self::Refund->value => 'Supplier refund',
        ];
    }
}
