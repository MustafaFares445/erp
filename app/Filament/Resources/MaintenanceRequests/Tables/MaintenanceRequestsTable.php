<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Tables;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyStatus;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
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
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
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
                FavoriteColumn::make(),
                TextColumn::make('id')->label(__('Job #'))->sortable(),
                TextColumn::make('customer.company_name')
                    ->label(__('Customer'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('equipment')
                    ->label(__('Equipment'))
                    ->getStateUsing(static fn (MaintenanceRecord $record): string => $record->serializedInventoryUnit?->productVariant->name
                        ?? ($record->is_equipment_unlinked ? 'External / unlinked' : '—'))
                    ->description(static fn (MaintenanceRecord $record): ?string => $record->serial_number !== null ? 'SN '.$record->serial_number : null),
                TextColumn::make('status')
                    ->label(__('Stage'))
                    ->badge()
                    ->formatStateUsing(static fn (MaintenanceStatus $state): string => $state->label())
                    ->color(static fn (MaintenanceStatus $state): string => $state->color()),
                TextColumn::make('warranty_status')
                    ->label(__('Warranty eligibility'))
                    ->badge()
                    ->formatStateUsing(static fn (WarrantyStatus $state): string => $state->label())
                    ->color(static fn (WarrantyStatus $state): string => $state->color()),
                TextColumn::make('coverage_decision')
                    ->label(__('Repair coverage'))
                    ->badge()
                    ->formatStateUsing(static fn (WarrantyClaimDecision $state): string => $state->label())
                    ->color(static fn (WarrantyClaimDecision $state): string => $state->color()),
                TextColumn::make('customer_responsibility')
                    ->label(__('Customer pays'))
                    ->state(static fn (MaintenanceRecord $record): string => number_format(self::coverage($record)['customer_amount_minor'] / 100, 2)),
                TextColumn::make('next_action')
                    ->label(__('Next action'))
                    ->state(static fn (MaintenanceRecord $record): string => self::nextAction($record))
                    ->wrap(),
                TextColumn::make('billing_type')
                    ->label(__('Commercial'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->since()
                    ->sortable(),
                TextColumn::make('warranty_expiry_date')
                    ->label(__('Warranty expiry'))
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Stage'))
                    ->getTitleFromRecordUsing(static fn (MaintenanceRecord $record): string => $record->status->label()),
                Group::make('customer.company_name')->label(__('Customer')),
                Group::make('warranty_status')
                    ->label(__('Warranty eligibility'))
                    ->getTitleFromRecordUsing(static fn (MaintenanceRecord $record): string => $record->warranty_status->label()),
                Group::make('billing_type')
                    ->label(__('Commercial status'))
                    ->getTitleFromRecordUsing(static fn (MaintenanceRecord $record): string => $record->billing_type->label()),
                Group::make('created_at')->label(__('Created at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Stage'))
                        ->options(self::enumOptions(MaintenanceStatus::cases()))
                        ->multiple(),
                    SelectConstraint::make('warranty_status')
                        ->label(__('Warranty eligibility'))
                        ->options(self::enumOptions(WarrantyStatus::cases()))
                        ->multiple(),
                    SelectConstraint::make('coverage_decision')
                        ->label(__('Repair coverage'))
                        ->options(self::enumOptions(WarrantyClaimDecision::cases()))
                        ->multiple(),
                    SelectConstraint::make('billing_type')
                        ->label(__('Commercial status'))
                        ->options(self::enumOptions(MaintenanceBillingType::cases()))
                        ->multiple(),
                    NumberConstraint::make('id')->label(__('Job #'))->integer(),
                    RelationshipConstraint::make('customer')
                        ->label(__('Customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    TextConstraint::make('serial_number')->label(__('Serial number')),
                    DateConstraint::make('warranty_expiry_date')->label(__('Warranty expiry')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                    DateConstraint::make('updated_at')->label(__('Updated')),
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(static fn (MaintenanceRecord $record): bool => ! $record->isLockedForChanges()),
                ActionGroup::make([
                    Action::make('cancel')
                        ->label(__('Cancel maintenance'))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('transition')
                        ->visible(static fn (MaintenanceRecord $record): bool => ! $record->isFinalised())
                        ->action(static fn (MaintenanceRecord $record) => self::applyTransition($record, MaintenanceStatus::Cancelled)),
                    Action::make('archive')
                        ->label(__('Delete'))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('delete')
                        ->visible(static fn (MaintenanceRecord $record): bool => ! $record->trashed())
                        ->action(static fn (MaintenanceRecord $record) => $record->delete()),
                    Action::make('restore')
                        ->label(__('Restore'))
                        ->requiresConfirmation()
                        ->authorize('restore')
                        ->visible(static fn (MaintenanceRecord $record): bool => $record->trashed())
                        ->action(static fn (MaintenanceRecord $record) => $record->restore()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label(__('Delete selected'))
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
                        ->label(__('Restore selected'))
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

    /**
     * @param  list<MaintenanceBillingType|MaintenanceStatus|WarrantyClaimDecision|WarrantyStatus>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(static fn (MaintenanceBillingType|MaintenanceStatus|WarrantyClaimDecision|WarrantyStatus $case): array => [$case->value => $case->label()])
            ->all();
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
                ->title(__('Unable to change the maintenance request status'))
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
