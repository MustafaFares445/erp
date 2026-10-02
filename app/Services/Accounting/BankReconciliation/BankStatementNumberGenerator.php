<?php

declare(strict_types=1);

namespace App\Services\Accounting\BankReconciliation;

use App\Models\BankStatement;

final readonly class BankStatementNumberGenerator
{
    public function next(): string
    {
        $max = BankStatement::query()->lockForUpdate()->max('statement_number');
        $next = is_string($max) && preg_match('/(\d+)$/', $max, $matches) === 1
            ? ((int) $matches[1]) + 1
            : 1;

        return sprintf('BST-%07d', $next);
    }
}
