<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;

/**
 * Shared lifecycle vocabulary for {@see MaintenanceRecord} ("Maintenance
 * Request") and {@see MaintenanceTask} ("Service Record") — one enum, one
 * rule, two call sites (FR-065/073,
 * contracts/maintenance-lifecycle.md §1).
 */
enum MaintenanceStatus: string
{
    case Open = 'open';
    case Diagnosing = 'diagnosing';
    case AwaitingApproval = 'awaiting_approval';
    case ReadyForRepair = 'ready_for_repair';
    case InProgress = 'in_progress';
    case QualityAssurance = 'quality_assurance';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Diagnosing, self::InProgress, self::Cancelled],
            self::Diagnosing => [self::AwaitingApproval, self::ReadyForRepair, self::InProgress, self::Cancelled],
            self::AwaitingApproval => [self::ReadyForRepair, self::InProgress, self::Cancelled],
            self::ReadyForRepair => [self::InProgress, self::Cancelled],
            self::InProgress => [self::QualityAssurance, self::Closed, self::Cancelled],
            self::QualityAssurance => [self::InProgress, self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return __('admin.support.maintenance_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::Diagnosing => 'warning',
            self::AwaitingApproval => 'warning',
            self::ReadyForRepair => 'info',
            self::InProgress => 'primary',
            self::QualityAssurance => 'info',
            self::Closed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
