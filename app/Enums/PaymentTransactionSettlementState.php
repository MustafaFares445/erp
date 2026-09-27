<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentTransactionSettlementState: string
{
    case WaitingForProvider = 'waiting_for_provider';
    case RequiresAttention = 'requires_attention';
    case Settled = 'settled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('admin.payments.settlement_state.'.$this->value);
    }

    public function description(): string
    {
        return __('admin.payments.settlement_state_description.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::WaitingForProvider => 'warning',
            self::RequiresAttention => 'danger',
            self::Settled => 'success',
            self::Failed, self::Cancelled => 'gray',
        };
    }
}
