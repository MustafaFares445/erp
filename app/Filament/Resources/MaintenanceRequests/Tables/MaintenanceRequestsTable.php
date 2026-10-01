<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Tables;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\WarrantyClaimService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use LogicException;

final class MaintenanceRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('Job #')->sortable(),
                TextColumn::make('customer.company_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('equipment')
                    ->label('Equipment')
                    ->getStateUsing(static fn (MaintenanceRecord $record): string => $record->serializedInventoryUnit?->productVariant->name
                        ?? ($record->is_equipment_unlinked ? 'External / unlinked' : '—'))
                    ->description(static fn (MaintenanceRecord $record): ?string => $record->serial_number !== null ? 'SN '.$record->serial_number : null),
                TextColumn::make('status')
                    ->label('Stage')
                    ->badge()
                    ->formatStateUsing(static fn (MaintenanceStatus $state): string => $state->label())
                    ->color(static fn (MaintenanceStatus $state): string => $state->color()),
                TextColumn::make('warranty_status')
                    ->label('Warranty eligibility')
                    ->badge()
                    ->formatStateUsing(static fn (WarrantyStatus $state): string => $state->label())
                    ->color(static fn (WarrantyStatus $state): string => $state->color()),
                TextColumn::make('coverage_decision')
                    ->label('Repair coverage')
                    ->badge()
                    ->formatStateUsing(static fn (WarrantyClaimDecision $state): string => $state->label())
                    ->color(static fn (WarrantyClaimDecision $state): string => $state->color()),
                TextColumn::make('customer_responsibility')
                    ->label('Customer pays')
                    ->state(static fn (MaintenanceRecord $record): string => number_format(self::coverage($record)['customer_amount_minor'] / 100, 2)),
                TextColumn::make('next_action')
                    ->label('Next action')
                    ->state(static fn (MaintenanceRecord $record): string => self::nextAction($record))
                    ->wrap(),
                TextColumn::make('billing_type')
                    ->label('Commercial')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(MaintenanceStatus::cases())
                        ->mapWithKeys(static fn (MaintenanceStatus $status): array => [$status->value => $status->label()])),
                SelectFilter::make('warranty_status')
                    ->label('Warranty eligibility')
                    ->options(collect(WarrantyStatus::cases())
                        ->mapWithKeys(static fn (WarrantyStatus $status): array => [$status->value => $status->label()])),
                SelectFilter::make('coverage_decision')
                    ->label('Repair coverage')
                    ->options(collect(WarrantyClaimDecision::cases())
                        ->mapWithKeys(static fn (WarrantyClaimDecision $decision): array => [$decision->value => $decision->label()])),
                SelectFilter::make('billing_type')
                    ->label('Commercial status')
                    ->options(collect(MaintenanceBillingType::cases())
                        ->mapWithKeys(static fn (MaintenanceBillingType $type): array => [$type->value => str($type->value)->headline()->toString()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(static fn (MaintenanceRecord $record): bool => ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)),
                ActionGroup::make([
                    Action::make('cancel')
                        ->label('Cancel maintenance')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('update')
                        ->visible(static fn (MaintenanceRecord $record): bool => ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true))
                        ->action(static fn (MaintenanceRecord $record) => self::applyTransition($record, MaintenanceStatus::Cancelled)),
                    Action::make('archive')
                        ->label('Delete')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('delete')
                        ->visible(static fn (MaintenanceRecord $record): bool => ! $record->trashed())
                        ->action(static fn (MaintenanceRecord $record) => $record->delete()),
                    Action::make('restore')
                        ->label('Restore')
                        ->requiresConfirmation()
                        ->authorize('restore')
                        ->visible(static fn (MaintenanceRecord $record): bool => $record->trashed())
                        ->action(static fn (MaintenanceRecord $record) => $record->restore()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Delete selected')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('deleteAny')
                        ->action(static function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof MaintenanceRecord) {
                                    $record->delete();
                                }
                            }
                        }),
                    BulkAction::make('restore')
                        ->label('Restore selected')
                        ->requiresConfirmation()
                        ->authorize('restoreAny')
                        ->action(static function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof MaintenanceRecord) {
                                    $record->restore();
                                }
                            }
                        }),
                ]),
            ]);
    }

    private static function nextAction(MaintenanceRecord $record): string
    {
        return match ($record->status) {
            MaintenanceStatus::Open => 'Record diagnosis',
            MaintenanceStatus::Diagnosing => 'Determine coverage',
            MaintenanceStatus::AwaitingApproval => self::approvalNextAction($record),
            MaintenanceStatus::ReadyForRepair => 'Start repair',
            MaintenanceStatus::InProgress => 'Send to QA',
            MaintenanceStatus::QualityAssurance => 'Complete QA',
            MaintenanceStatus::Closed => 'Commercial follow-up',
            MaintenanceStatus::Cancelled => 'Cancelled',
        };
    }

    private static function approvalNextAction(MaintenanceRecord $record): string
    {
        $customerAmount = self::coverage($record)['customer_amount_minor'];

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

    /** @return array{total_amount_minor:int,covered_amount_minor:int,customer_amount_minor:int} */
    private static function coverage(MaintenanceRecord $record): array
    {
        return app(WarrantyClaimService::class)->coverageSummary($record);
    }

    private static function applyTransition(MaintenanceRecord $record, MaintenanceStatus $to): void
    {
        try {
            app(MaintenanceRecordService::class)->transition($record, $to, self::currentActor());
        } catch (DomainException $domainException) {
            Notification::make()
                ->danger()
                ->title('Unable to change the maintenance request status')
                ->body($domainException->getMessage())
                ->send();
        }
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }
}
