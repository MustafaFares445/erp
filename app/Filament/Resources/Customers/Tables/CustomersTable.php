<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\CustomerApprovalStatus;
use App\Filament\Resources\Customers\Actions\CustomerApprovalActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\CustomerProfile;
use App\Services\Accounting\CustomerReceivablesSnapshot;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\BooleanConstraint;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestInteraction'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('customer_code')->label(__('Customer code'))->searchable()->sortable(),
                TextColumn::make('company_name')->label(__('Company name'))->searchable()->sortable(),
                TextColumn::make('user.name')->label(__('Account name'))->searchable(),
                TextColumn::make('user.username')->label(__('Username'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.email')->label(__('Account email'))->searchable(),
                TextColumn::make('email')->label(__('Company email'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')->label(__('City'))->searchable()->placeholder(__('—'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('country')->label(__('Country'))->searchable()->placeholder(__('—'))->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')->label(__('Active')),
                TextColumn::make('approval_status')
                    ->label(__('Approval'))
                    ->badge()
                    ->formatStateUsing(fn (CustomerApprovalStatus $state): string => $state->label())
                    ->color(fn (CustomerApprovalStatus $state): string => $state->color()),
                TextColumn::make('deliveries_awaiting_invoice')
                    ->label(__('Deliveries awaiting invoice'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->state(fn (CustomerProfile $record): int => (int) $record->deliveries_awaiting_invoice_count),
                TextColumn::make('outstanding_balance')
                    ->label(__('Outstanding'))
                    ->state(fn (CustomerProfile $record): string => number_format(self::outstandingMinorFor($record) / 100, 2)),
                TextColumn::make('latestInteraction.occurred_at')
                    ->label(__('Last interaction'))
                    ->dateTime()
                    ->placeholder(__('—')),
                TextColumn::make('created_at')->label(__('Created at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('approval_status')
                    ->label(__('Approval status'))
                    ->getTitleFromRecordUsing(static fn (CustomerProfile $record): string => $record->approval_status->label()),
                Group::make('is_active')
                    ->label(__('Active'))
                    ->getTitleFromRecordUsing(static fn (CustomerProfile $record): string => $record->is_active ? __('Active') : __('Inactive')),
                Group::make('city')->label(__('City')),
                Group::make('country')->label(__('Country')),
                Group::make('created_at')->label(__('Created at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    TextConstraint::make('customer_code')->label(__('Customer code')),
                    TextConstraint::make('company_name')->label(__('Company name')),
                    SelectConstraint::make('approval_status')
                        ->label(__('Approval status'))
                        ->options(static fn (): array => array_combine(
                            CustomerApprovalStatus::values(),
                            array_map(static fn (CustomerApprovalStatus $status): string => $status->label(), CustomerApprovalStatus::cases()),
                        ))
                        ->multiple(),
                    BooleanConstraint::make('is_active')->label(__('Active')),
                    RelationshipConstraint::make('user')
                        ->label(__('Account'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    TextConstraint::make('email')->label(__('Company email')),
                    TextConstraint::make('city')->label(__('City')),
                    TextConstraint::make('country')->label(__('Country')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
                TrashedFilter::make(),
                Filter::make('inactive_90_days')
                    ->label(__('Inactive 90 days'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDoesntHave('latestInteraction', fn (Builder $interactions): Builder => $interactions
                            ->where('occurred_at', '>=', now()->subDays(90)))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                CustomerApprovalActions::approve(),
                CustomerApprovalActions::requestChanges(),
                CustomerApprovalActions::reject(),
                CustomerApprovalActions::reactivate(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    private static function outstandingMinorFor(CustomerProfile $record): int
    {
        return app(CustomerReceivablesSnapshot::class)->outstandingMinor((int) $record->id);
    }
}
