<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceRecord;
use App\Services\Support\WarrantyClaimService;

/**
 * The single next operational step of a maintenance request. Shared by the
 * list table so the "Next action" column and the primary row button can never
 * disagree.
 */
enum MaintenanceNextStep: string
{
    case RecordDiagnosis = 'record_diagnosis';
    case DetermineCoverage = 'determine_coverage';
    case ConfirmApproval = 'confirm_approval';
    case CreateQuotation = 'create_quotation';
    case WaitingQuoteApproval = 'waiting_quote_approval';
    case MarkReadyForRepair = 'mark_ready_for_repair';
    case StartRepair = 'start_repair';
    case SendToQa = 'send_to_qa';
    case CompleteQa = 'complete_qa';

    public static function forRecord(MaintenanceRecord $record): ?self
    {
        return match ($record->status) {
            MaintenanceStatus::Open => self::RecordDiagnosis,
            MaintenanceStatus::Diagnosing => self::DetermineCoverage,
            MaintenanceStatus::AwaitingApproval => self::forApproval($record),
            MaintenanceStatus::ReadyForRepair => self::StartRepair,
            MaintenanceStatus::InProgress => self::SendToQa,
            MaintenanceStatus::QualityAssurance => self::CompleteQa,
            MaintenanceStatus::Closed, MaintenanceStatus::Cancelled => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RecordDiagnosis => __('Record diagnosis'),
            self::DetermineCoverage => __('Determine coverage'),
            self::ConfirmApproval => __('Confirm approval'),
            self::CreateQuotation => __('Create quotation'),
            self::WaitingQuoteApproval => __('Waiting quote approval'),
            self::MarkReadyForRepair => __('Mark ready for repair'),
            self::StartRepair => __('Start repair'),
            self::SendToQa => __('Send to QA'),
            self::CompleteQa => __('Complete QA'),
        };
    }

    private static function forApproval(MaintenanceRecord $record): self
    {
        $customerAmount = app(WarrantyClaimService::class)->coverageSummary($record)['customer_amount_minor'];

        if ($customerAmount <= 0) {
            return self::ConfirmApproval;
        }

        if ($record->quotation_id === null) {
            return self::CreateQuotation;
        }

        $record->loadMissing('quotation');

        return $record->quotation?->status === QuotationStatus::Accepted
            ? self::MarkReadyForRepair
            : self::WaitingQuoteApproval;
    }
}
