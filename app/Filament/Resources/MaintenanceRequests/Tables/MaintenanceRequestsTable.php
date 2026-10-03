<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Tables;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceNextStep;
use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\MaintenanceRequests\Actions\MaintenanceBillingActions;
use App\Filament\Resources\MaintenanceRequests\Actions\MaintenanceTransitionActions;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\WarrantyClaimService;
use Closure;
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
use Filament\Support\Icons\Heroicon;
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
                ...self::primaryActions(),
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
            MaintenanceStatus::Closed => 'Commercial follow-up',
            MaintenanceStatus::Cancelled => 'Cancelled',
            default => MaintenanceNextStep::forRecord($record)?->label() ?? '',
        };
    }

    /**
     * The single emphasised row button for the request's next valid step. Form-heavy
     * steps link to the detail page action; plain transitions run the shared
     * transition action that the detail page also uses.
     *
     * @return list<Action>
     */
    private static function primaryActions(): array
    {
        $step = static fn (MaintenanceRecord $record): ?MaintenanceNextStep => MaintenanceNextStep::forRecord($record);
        $is = static fn (MaintenanceNextStep $expected): Closure => static fn (MaintenanceRecord $record): bool => $step($record) === $expected;
        $canAssess = static fn (MaintenanceRecord $record): bool => ! $record->isLockedForChanges();
        $detailUrl = static fn (MaintenanceRecord $record, string $action): string => MaintenanceRequestResource::getUrl('view', [
            'record' => $record,
            'action' => $action,
        ]);

        return [
            Action::make('recordDiagnosisRow')
                ->label(__('Record diagnosis'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->button()
                ->color('primary')
                ->authorize('diagnose')
                ->visible(static fn (MaintenanceRecord $record): bool => $canAssess($record)
                    && ($is(MaintenanceNextStep::RecordDiagnosis)($record)
                        || ($is(MaintenanceNextStep::DetermineCoverage)($record) && $record->diagnosed_at === null)))
                ->url(static fn (MaintenanceRecord $record): string => $detailUrl($record, 'recordDiagnosis')),
            Action::make('determineCoverageRow')
                ->label(__('Determine coverage'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->button()
                ->color('primary')
                ->authorize('decideCoverage')
                ->visible(static fn (MaintenanceRecord $record): bool => $canAssess($record)
                    && $is(MaintenanceNextStep::DetermineCoverage)($record)
                    && $record->diagnosed_at !== null)
                ->url(static fn (MaintenanceRecord $record): string => $detailUrl($record, 'determineCoverage')),
            MaintenanceTransitionActions::customerApproval('confirmApprovalRow', __('Confirm approval'))
                ->button()
                ->color('primary')
                ->visible($is(MaintenanceNextStep::ConfirmApproval)),
            Action::make('createQuotationRow')
                ->label(__('Create quotation'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->button()
                ->color('primary')
                ->authorize(static fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
                ->visible(static fn (MaintenanceRecord $record): bool => $is(MaintenanceNextStep::CreateQuotation)($record)
                    && MaintenanceBillingActions::canCreateQuotation($record))
                ->url(static fn (MaintenanceRecord $record): string => $detailUrl($record, 'create_quotation')),
            Action::make('waitingForCustomerRow')
                ->label(__('Waiting for customer'))
                ->icon(Heroicon::OutlinedClock)
                ->button()
                ->color('gray')
                ->authorize(static fn (MaintenanceRecord $record): bool => $record->quotation !== null
                    && self::currentActor()->can('view', $record->quotation))
                ->visible($is(MaintenanceNextStep::WaitingQuoteApproval))
                ->url(static fn (MaintenanceRecord $record): ?string => $record->quotation_id === null
                    ? null
                    : QuotationResource::getUrl('view', ['record' => $record->quotation_id])),
            MaintenanceTransitionActions::customerApproval('markReadyForRepairRow', __('Mark ready for repair'))
                ->button()
                ->color('primary')
                ->visible($is(MaintenanceNextStep::MarkReadyForRepair)),
            MaintenanceTransitionActions::transition('startRepairRow', __('Start repair'), MaintenanceStatus::InProgress)
                ->icon(Heroicon::OutlinedWrench)
                ->button()
                ->color('primary')
                ->visible($is(MaintenanceNextStep::StartRepair)),
            MaintenanceTransitionActions::transition('sendToQaRow', __('Send to QA'), MaintenanceStatus::QualityAssurance)
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->button()
                ->color('primary')
                ->visible($is(MaintenanceNextStep::SendToQa)),
            MaintenanceTransitionActions::transition('completeQaRow', __('Complete QA'), MaintenanceStatus::Closed)
                ->icon(Heroicon::OutlinedCheckBadge)
                ->button()
                ->color('primary')
                ->visible($is(MaintenanceNextStep::CompleteQa)),
        ];
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
