<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryImportRunStatus: string
{
    case Queued = 'queued';
    case Parsing = 'parsing';
    case Ready = 'ready';
    case ReadyWithErrors = 'ready_with_errors';
    case Invalid = 'invalid';
    case Applying = 'applying';
    case Confirmed = 'confirmed';
    case ConfirmedWithErrors = 'confirmed_with_errors';
    case Failed = 'failed';

    public function canApply(): bool
    {
        return $this === self::Ready || $this === self::ReadyWithErrors;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Invalid,
            self::Confirmed,
            self::ConfirmedWithErrors,
            self::Failed,
        ], true);
    }
}
