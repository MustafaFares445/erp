<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\MaintenanceRecord;
use App\Services\Support\MaintenanceBillingService;
use Filament\Support\Contracts\HasLabel;

/**
 * How a {@see MaintenanceRecord} job's cost was (or was not) recovered
 * (WP-2.9, GAP-MW-09/GAP-MW-10). `Unbilled` is the default for every job;
 * `WarrantyCovered` recognises zero customer revenue against a real cost;
 * `TicketSettled` means the previously collected ticket fee was invoiced and
 * applied through the canonical payment/accounting path. `Quoted` and
 * `Invoiced` are the standard Sales billing path, set only by
 * {@see MaintenanceBillingService}.
 */
enum MaintenanceBillingType: string implements HasLabel
{
    use HasTranslatedLabel;

    case Unbilled = 'unbilled';
    case WarrantyCovered = 'warranty_covered';
    case GoodwillCovered = 'goodwill_covered';
    case ThirdPartyCovered = 'third_party_covered';
    case ServiceContractCovered = 'service_contract_covered';
    case TicketSettled = 'ticket_settled';
    case Quoted = 'quoted';
    case Invoiced = 'invoiced';

    /**
     * Whether a job in this billing state has already recovered (or has been
     * declared never to recover) its cost — used to guard against billing a
     * job twice.
     */
    public function isSettled(): bool
    {
        return in_array($this, [
            self::WarrantyCovered,
            self::GoodwillCovered,
            self::ThirdPartyCovered,
            self::ServiceContractCovered,
            self::TicketSettled,
            self::Invoiced,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
