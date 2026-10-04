<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceNextStep;
use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyClaimDecision;
use App\Models\MaintenanceRecord;

final class MaintenanceNextActionResolver
{
    public function resolve(MaintenanceRecord $record): string
    {
        if ($record->status === MaintenanceStatus::Cancelled) {
            return __('No action — cancelled');
        }

        if ($record->status !== MaintenanceStatus::Closed) {
            return MaintenanceNextStep::forRecord($record)?->label() ?? __('Complete');
        }

        if (! in_array($record->billing_type, [MaintenanceBillingType::Unbilled, MaintenanceBillingType::Quoted], true)) {
            return __('Commercial follow-up complete');
        }

        return match ($record->coverage_decision) {
            WarrantyClaimDecision::FullyCovered,
            WarrantyClaimDecision::Goodwill,
            WarrantyClaimDecision::ThirdPartyWarranty,
            WarrantyClaimDecision::ServiceContract => __('Settle covered repair'),
            WarrantyClaimDecision::PartiallyCovered,
            WarrantyClaimDecision::Rejected => __('Create final customer invoice'),
            default => __('Review billing'),
        };
    }
}
