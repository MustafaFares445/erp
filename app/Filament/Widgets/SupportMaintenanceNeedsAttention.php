<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\SupportPermission;
use App\Enums\WarrantyClaimDecision;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRecord;
use App\Services\Support\WarrantyClaimService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class SupportMaintenanceNeedsAttention extends TableWidget
{
    protected static ?string $heading = 'Maintenance requiring action';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::MaintenanceRequestView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(self::attentionQuery())
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(static fn (MaintenanceRecord $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('id')->label(__('Job #')),
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('status')
                    ->label(__('Stage'))
                    ->badge()
                    ->formatStateUsing(static fn (MaintenanceStatus $state): string => $state->label())
                    ->color(static fn (MaintenanceStatus $state): string => $state->color()),
                TextColumn::make('coverage_decision')
                    ->label(__('Coverage'))
                    ->badge()
                    ->formatStateUsing(static fn (WarrantyClaimDecision $state): string => $state->label())
                    ->color(static fn (WarrantyClaimDecision $state): string => $state->color()),
                TextColumn::make('customer_amount')
                    ->label(__('Customer pays'))
                    ->state(static fn (MaintenanceRecord $record): string => number_format(
                        app(WarrantyClaimService::class)->coverageSummary($record)['customer_amount_minor'] / 100,
                        2,
                    )),
                TextColumn::make('next_action')
                    ->label(__('Next action'))
                    ->state(static fn (MaintenanceRecord $record): string => self::nextAction($record))
                    ->wrap(),
                TextColumn::make('updated_at')->label(__('Updated'))->since(),
            ])
            ->paginated([5, 10]);
    }

    /** @return Builder<MaintenanceRecord> */
    private static function attentionQuery(): Builder
    {
        return MaintenanceRecord::query()
            ->whereIn('status', [
                MaintenanceStatus::Open->value,
                MaintenanceStatus::Diagnosing->value,
                MaintenanceStatus::AwaitingApproval->value,
                MaintenanceStatus::ReadyForRepair->value,
                MaintenanceStatus::QualityAssurance->value,
            ]);
    }

    private static function nextAction(MaintenanceRecord $record): string
    {
        return match ($record->status) {
            MaintenanceStatus::Open => 'Record diagnosis',
            MaintenanceStatus::Diagnosing => 'Determine coverage',
            MaintenanceStatus::AwaitingApproval => self::approvalNextAction($record),
            MaintenanceStatus::ReadyForRepair => 'Start repair',
            MaintenanceStatus::QualityAssurance => 'Complete QA',
            default => 'Review maintenance job',
        };
    }

    private static function approvalNextAction(MaintenanceRecord $record): string
    {
        $customerAmount = app(WarrantyClaimService::class)->coverageSummary($record)['customer_amount_minor'];

        if ($customerAmount <= 0) {
            return 'Confirm approval';
        }

        if ($record->quotation_id === null) {
            return 'Create quotation';
        }

        $record->loadMissing('quotation');

        return $record->quotation?->status === QuotationStatus::Accepted
            ? 'Mark ready for repair'
            : 'Waiting quote approval';
    }
}
