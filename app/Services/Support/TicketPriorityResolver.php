<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketCustomerImpact;
use App\Enums\TicketPriority;
use App\Enums\TicketType;

/**
 * Proposes the internal {@see TicketPriority} that
 * {@see SlaService::onTicketLive()} will start the SLA clock against, from
 * the customer-reported {@see TicketCustomerImpact} and {@see TicketType}.
 * A proposal only — the Filament ticket form pre-fills `priority` with it,
 * but support can freely override the field before saving (FR matches the
 * Customer App V1 plan's "customer impact vs internal priority" split).
 */
final readonly class TicketPriorityResolver
{
    public function resolve(TicketType $type, ?TicketCustomerImpact $impact): TicketPriority
    {
        if ($impact === TicketCustomerImpact::ServiceUnavailable) {
            return TicketPriority::Urgent;
        }

        if ($impact === TicketCustomerImpact::Degraded) {
            return match ($type) {
                TicketType::HardwareIssue, TicketType::SoftwareIssue => TicketPriority::High,
                TicketType::GeneralSupport, TicketType::MaintenanceRequest => TicketPriority::Normal,
            };
        }

        return match ($type) {
            TicketType::GeneralSupport => TicketPriority::Low,
            TicketType::HardwareIssue, TicketType::SoftwareIssue, TicketType::MaintenanceRequest => TicketPriority::Normal,
        };
    }
}
