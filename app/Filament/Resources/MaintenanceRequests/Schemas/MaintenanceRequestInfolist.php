<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Schemas;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\SupportPermission;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyRecoveryStatus;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\MaintenanceRecord;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\MaintenanceCostService;
use App\Services\Support\WarrantyClaimService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MaintenanceRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Workflow'))
                ->description(__('Current stage, next action, and the support context for this repair.'))
                ->schema([
                    TextEntry::make('status')
                        ->label(__('Stage'))
                        ->badge()
                        ->formatStateUsing(static fn (MaintenanceStatus $state): string => $state->label())
                        ->color(static fn (MaintenanceStatus $state): string => $state->color()),
                    TextEntry::make('next_action')
                        ->label(__('Next action'))
                        ->state(static fn (MaintenanceRecord $record): string => self::nextAction($record))
                        ->badge()
                        ->color('primary'),
                    TextEntry::make('source')
                        ->label(__('Source'))
                        ->state(static fn (MaintenanceRecord $record): string => match (true) {
                            $record->ticket_id !== null => 'Ticket',
                            $record->scheduleOccurrence !== null => 'Preventive schedule',
                            default => 'Manual',
                        })
                        ->badge(),
                    TextEntry::make('customer.company_name')->label(__('Customer')),
                    TextEntry::make('ticket.ticket_number')->label(__('Raised from ticket'))->placeholder(__('Standalone')),
                    TextEntry::make('description')->label(__('Reported / requested work'))->columnSpanFull(),
                ])
                ->columns(3),
            Section::make(__('Equipment & warranty eligibility'))
                ->description(__('Eligibility only. Final repair coverage is decided after diagnosis.'))
                ->schema([
                    TextEntry::make('serializedInventoryUnit.productVariant.name')
                        ->label(__('Equipment'))
                        ->placeholder(__('External / unlinked')),
                    TextEntry::make('serial_number')->label(__('Serial number'))->placeholder(__('—')),
                    TextEntry::make('is_equipment_unlinked')
                        ->label(__('Equipment status'))
                        ->formatStateUsing(static fn (bool $state): string => $state ? 'External / unlinked' : 'Known equipment')
                        ->badge()
                        ->color(static fn (bool $state): string => $state ? 'warning' : 'success'),
                    TextEntry::make('warranty_status')
                        ->label(__('Warranty eligibility'))
                        ->badge()
                        ->formatStateUsing(static fn (WarrantyStatus $state): string => $state->label())
                        ->color(static fn (WarrantyStatus $state): string => $state->color()),
                    TextEntry::make('warranty_expiry_date')->label(__('Warranty expiry'))->date()->placeholder(__('—')),
                    TextEntry::make('warranty_guidance')
                        ->label(__('Meaning'))
                        ->state(static fn (MaintenanceRecord $record): string => self::warrantyGuidance($record))
                        ->columnSpanFull(),
                ])
                ->columns(3),
            Section::make(__('Diagnosis'))
                ->description(__('Technical findings that must exist before a claim coverage decision.'))
                ->visible(static fn (MaintenanceRecord $record): bool => $record->diagnosed_at !== null)
                ->schema([
                    TextEntry::make('diagnosis_summary')->label(__('Technician findings'))->columnSpanFull(),
                    TextEntry::make('root_cause')->label(__('Root cause'))->columnSpanFull(),
                    TextEntry::make('failure_category')
                        ->label(__('Failure category'))
                        ->formatStateUsing(static fn (?WarrantyFailureCategory $state): string => $state?->label() ?? '—')
                        ->badge(),
                    TextEntry::make('diagnosedBy.name')->label(__('Diagnosed by'))->placeholder(__('—')),
                    TextEntry::make('diagnosed_at')->label(__('Diagnosed at'))->dateTime()->placeholder(__('—')),
                ])
                ->columns(3),
            Section::make(__('Coverage decision'))
                ->description(__('Who pays this diagnosed repair. This is separate from warranty eligibility.'))
                ->visible(static fn (MaintenanceRecord $record): bool => $record->diagnosed_at !== null)
                ->schema([
                    TextEntry::make('coverage_decision')
                        ->label(__('Decision'))
                        ->badge()
                        ->formatStateUsing(static fn (WarrantyClaimDecision $state): string => $state->label())
                        ->color(static fn (WarrantyClaimDecision $state): string => $state->color()),
                    TextEntry::make('coverage_source')
                        ->label(__('Coverage source'))
                        ->formatStateUsing(static fn (?WarrantyCoverageSource $state): string => $state?->label() ?? 'Not decided')
                        ->badge(),
                    TextEntry::make('coverage_decided_at')->label(__('Decided at'))->dateTime()->placeholder(__('—')),
                    TextEntry::make('coverage_reason')->label(__('Internal decision reason'))->placeholder(__('—'))->columnSpanFull(),
                    TextEntry::make('customer_coverage_explanation')
                        ->label(__('Customer explanation'))
                        ->placeholder(__('—'))
                        ->columnSpanFull(),
                    TextEntry::make('coverage_total')
                        ->label(__('Repair charge basis'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::coverage($record)['total_amount_minor'])),
                    TextEntry::make('coverage_paid')
                        ->label(__('Coverage pays'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::coverage($record)['covered_amount_minor'])),
                    TextEntry::make('coverage_customer')
                        ->label(__('Customer responsibility'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::coverage($record)['customer_amount_minor']))
                        ->weight('bold'),
                ])
                ->columns(3),
            Section::make(__('Internal service cost'))
                ->schema([
                    TextEntry::make('parts_cost')
                        ->label(__('Parts cost'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['parts_cost_minor'])),
                    TextEntry::make('labour_cost')
                        ->label(__('Labour cost'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['labour_cost_minor'])),
                    TextEntry::make('third_party_cost')
                        ->label(__('External service cost'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['third_party_cost_minor'])),
                    TextEntry::make('total_cost')
                        ->label(__('Total cost'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['total_cost_minor'])),
                    TextEntry::make('revenue')
                        ->label(__('Revenue'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::margin($record)['revenue_minor'])),
                    TextEntry::make('margin')
                        ->label(__('Margin'))
                        ->state(static fn (MaintenanceRecord $record): string => self::money(self::margin($record)['margin_minor'])),
                ])
                ->columns(3)
                ->visible(static fn (): bool => auth()->user()?->can('viewCost', MaintenanceRecord::class) ?? false),
            Section::make(__('Third-party warranty recovery'))
                ->description(__('Reimbursement from a manufacturer or supplier is tracked separately from customer billing.'))
                ->visible(static fn (MaintenanceRecord $record): bool => $record->coverage_decision === WarrantyClaimDecision::ThirdPartyWarranty
                    && (auth()->user()?->can(SupportPermission::WarrantyRecoveryView->value) ?? false))
                ->schema([
                    TextEntry::make('warrantyRecoveryClaim.status')
                        ->label(__('Recovery status'))
                        ->badge()
                        ->placeholder(__('Claim not created'))
                        ->formatStateUsing(static fn (?WarrantyRecoveryStatus $state): string => $state?->label() ?? 'Claim not created')
                        ->color(static fn (?WarrantyRecoveryStatus $state): string => $state?->color() ?? 'gray'),
                    TextEntry::make('warrantyRecoveryClaim.coverage_source')
                        ->label(__('Recovery source'))
                        ->formatStateUsing(static fn (?WarrantyCoverageSource $state): string => $state?->label() ?? '—')
                        ->placeholder(__('—')),
                    TextEntry::make('recovery_counterparty')
                        ->label(__('Counterparty'))
                        ->state(static fn (MaintenanceRecord $record): string => self::recoveryCounterparty($record)),
                    TextEntry::make('warrantyRecoveryClaim.external_reference')
                        ->label(__('External reference'))
                        ->placeholder(__('—')),
                    TextEntry::make('recovery_claimed')
                        ->label(__('Claimed'))
                        ->state(static fn (MaintenanceRecord $record): string => self::recoveryMoney($record, 'claimed_amount_minor')),
                    TextEntry::make('recovery_approved')
                        ->label(__('Approved'))
                        ->state(static fn (MaintenanceRecord $record): string => self::recoveryMoney($record, 'approved_amount_minor')),
                    TextEntry::make('recovery_received')
                        ->label(__('Received'))
                        ->state(static fn (MaintenanceRecord $record): string => self::recoveryMoney($record, 'received_amount_minor')),
                    TextEntry::make('recovery_outstanding')
                        ->label(__('Outstanding'))
                        ->state(static fn (MaintenanceRecord $record): string => self::recoveryOutstanding($record))
                        ->weight('bold'),
                    TextEntry::make('warrantyRecoveryClaim.rejection_reason')
                        ->label(__('Rejection reason'))
                        ->placeholder(__('—'))
                        ->columnSpanFull(),
                ])
                ->columns(4),
            Section::make(__('Commercial follow-up'))
                ->schema([
                    TextEntry::make('billing_type')->label(__('Billing status'))->badge(),
                    TextEntry::make('quotation.status')
                        ->label(__('Customer quotation'))
                        ->badge()
                        ->placeholder(__('Not created')),
                    TextEntry::make('quotation.id')
                        ->label(__('Quotation'))
                        ->placeholder(__('—'))
                        ->formatStateUsing(static fn (int|string|null $state): string => $state === null ? '—' : 'Quotation #'.$state)
                        ->url(static fn (MaintenanceRecord $record): ?string => $record->quotation_id === null
                            ? null
                            : QuotationResource::getUrl('view', ['record' => $record->quotation_id])),
                    TextEntry::make('invoice.id')
                        ->label(__('Invoice'))
                        ->placeholder(__('—'))
                        ->formatStateUsing(static fn (int|string|null $state): string => $state === null ? '—' : 'Invoice #'.$state)
                        ->url(static fn (MaintenanceRecord $record): ?string => $record->invoice_id === null
                            ? null
                            : InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
                    TextEntry::make('billed_at')->label(__('Commercially settled at'))->dateTime()->placeholder(__('—')),
                ])
                ->columns(3),
        ]);
    }

    private static function nextAction(MaintenanceRecord $record): string
    {
        return match ($record->status) {
            MaintenanceStatus::Open => 'Record diagnosis',
            MaintenanceStatus::Diagnosing => 'Determine coverage',
            MaintenanceStatus::AwaitingApproval => self::approvalNextAction($record),
            MaintenanceStatus::ReadyForRepair => 'Start repair',
            MaintenanceStatus::InProgress => 'Complete work and send to QA',
            MaintenanceStatus::QualityAssurance => 'QA check and complete',
            MaintenanceStatus::Closed => self::closedNextAction($record),
            MaintenanceStatus::Cancelled => 'No action — cancelled',
        };
    }

    private static function approvalNextAction(MaintenanceRecord $record): string
    {
        $customerAmount = (int) $record->coverageLines()->sum('customer_amount_minor');

        if ($customerAmount <= 0) {
            return 'Confirm customer approval';
        }

        if ($record->quotation_id === null) {
            return 'Create customer quotation';
        }

        $record->loadMissing('quotation');

        return $record->quotation?->status === QuotationStatus::Accepted
            ? 'Customer accepted — mark ready for repair'
            : 'Waiting for customer quotation approval';
    }

    private static function closedNextAction(MaintenanceRecord $record): string
    {
        if ($record->billing_type !== MaintenanceBillingType::Unbilled
            && $record->billing_type !== MaintenanceBillingType::Quoted) {
            return 'Commercial follow-up complete';
        }

        return match ($record->coverage_decision) {
            WarrantyClaimDecision::FullyCovered,
            WarrantyClaimDecision::Goodwill,
            WarrantyClaimDecision::ThirdPartyWarranty,
            WarrantyClaimDecision::ServiceContract => 'Settle covered repair',
            WarrantyClaimDecision::PartiallyCovered,
            WarrantyClaimDecision::Rejected => 'Create final customer invoice',
            WarrantyClaimDecision::PendingDiagnosis => 'Review billing',
        };
    }

    private static function warrantyGuidance(MaintenanceRecord $record): string
    {
        $expiry = $record->warranty_expiry_date?->toDateString();

        return match ($record->warranty_status) {
            WarrantyStatus::Covered => 'Warranty is active'.($expiry !== null ? ' until '.$expiry : '').'. Coverage for this failure still depends on diagnosis.',
            WarrantyStatus::Expired => 'The seller warranty has expired. Goodwill, service-contract, manufacturer or supplier coverage may still apply.',
            WarrantyStatus::NotCovered => 'No seller warranty is configured for this equipment.',
            WarrantyStatus::NotApplicable => 'Seller warranty does not apply to this external/unlinked equipment.',
            WarrantyStatus::Unknown => 'Warranty data needs verification before seller-warranty coverage can be approved.',
        };
    }

    /** @return array{total_amount_minor:int,covered_amount_minor:int,customer_amount_minor:int} */
    private static function coverage(MaintenanceRecord $record): array
    {
        return app(WarrantyClaimService::class)->coverageSummary($record);
    }

    /** @return array{parts_cost_minor:int, labour_cost_minor:int, third_party_cost_minor:int, total_cost_minor:int, coverage_percent:float} */
    private static function jobCost(MaintenanceRecord $record): array
    {
        return app(MaintenanceCostService::class)->jobCost($record);
    }

    /** @return array{cost_minor:int, revenue_minor:int, margin_minor:int, billing_type:string} */
    private static function margin(MaintenanceRecord $record): array
    {
        return app(MaintenanceCostService::class)->marginFor($record);
    }

    private static function recoveryCounterparty(MaintenanceRecord $record): string
    {
        $claim = $record->warrantyRecoveryClaim;

        if (! $claim instanceof WarrantyRecoveryClaim) {
            return 'Claim not created';
        }

        return $claim->supplier->name
            ?? $claim->counterparty_name
            ?? 'Not specified';
    }

    private static function recoveryMoney(MaintenanceRecord $record, string $field): string
    {
        $claim = $record->warrantyRecoveryClaim;

        if (! $claim instanceof WarrantyRecoveryClaim) {
            return '—';
        }

        $value = $claim->getAttribute($field);

        return is_numeric($value)
            ? number_format(((int) $value) / 100, 2).' '.$claim->currency
            : '—';
    }

    private static function recoveryOutstanding(MaintenanceRecord $record): string
    {
        $claim = $record->warrantyRecoveryClaim;

        return $claim instanceof WarrantyRecoveryClaim
            ? number_format($claim->outstandingMinor() / 100, 2).' '.$claim->currency
            : '—';
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
