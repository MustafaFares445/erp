<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Tables;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Actions\QuotationActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Filament\Tables\TableFit;
use App\Models\EmployeeProfile;
use App\Models\Quotation;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return TableFit::apply($table)
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder(__('Search by quotation number, customer name, or customer code'))
            ->searchDebounce('300ms')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('quotation_number')->label(__('admin.sales.fields.quotation_number'))->searchable()->sortable(),
                TextColumn::make('customer.company_name')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query->orWhereHas(
                        'customer',
                        static fn (Builder $customer): Builder => $customer
                            ->where('company_name', 'like', '%'.$search.'%')
                            ->orWhere('customer_code', 'like', '%'.$search.'%'),
                    ))
                    ->description(fn (Quotation $record): ?string => $record->customer?->customer_code)
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('admin.sales.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (QuotationStatus $state, Quotation $record): string => $state === QuotationStatus::Sent && $record->isExpired() ? QuotationStatus::Expired->label() : $state->label())
                    ->color(static fn (QuotationStatus $state, Quotation $record): string => $state === QuotationStatus::Sent && $record->isExpired() ? QuotationStatus::Expired->color() : $state->color()),
                TextColumn::make('reservation_coverage')
                    ->label(__('Stock coverage'))
                    ->state(fn (Quotation $record): string => match (true) {
                        $record->status !== QuotationStatus::Accepted && $record->converted_order_id === null => 'Not checked',
                        $record->hasLapsedReservations() => 'Insufficient',
                        default => 'Available',
                    })
                    ->formatStateUsing(static fn (string $state): string => __($state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Insufficient' => 'danger',
                        'Available' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issue_date')->label(__('admin.sales.fields.issue_date'))->date()->sortable(),
                TextColumn::make('expires_at')->label(__('admin.sales.fields.expires_at'))->date()->sortable(),
                TextColumn::make('grand_total')
                    ->label(__('admin.sales.fields.grand_total'))
                    ->money()
                    ->sortable()
                    ->summarize(Sum::make()->money()->label(__('Total'))),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('admin.sales.fields.status'))
                    ->getTitleFromRecordUsing(static fn (Quotation $record): string => $record->status->label()),
                Group::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                Group::make('issue_date')->label(__('admin.sales.fields.issue_date'))->date(),
                Group::make('expires_at')->label(__('admin.sales.fields.expires_at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('admin.sales.fields.status'))
                        ->options(static fn (): array => collect(QuotationStatus::cases())
                            ->mapWithKeys(static fn (QuotationStatus $status): array => [$status->value => $status->label()])
                            ->all())
                        ->multiple(),
                    TextConstraint::make('quotation_number')->label(__('admin.sales.fields.quotation_number')),
                    RelationshipConstraint::make('customer')
                        ->label(__('admin.sales.fields.customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    SelectConstraint::make('employee_id')
                        ->label(__('Salesperson'))
                        ->options(static fn (): array => EmployeeProfile::query()
                            ->with('user:id,name')
                            ->get()
                            ->mapWithKeys(static fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                            ->all())
                        ->searchable()
                        ->multiple(),
                    NumberConstraint::make('grand_total')->label(__('admin.sales.fields.grand_total')),
                    DateConstraint::make('issue_date')->label(__('admin.sales.fields.issue_date')),
                    DateConstraint::make('expires_at')->label(__('admin.sales.fields.expires_at')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
            ])
            ->recordActions(TableFit::rowActions([
                QuotationActions::send()->button(),
                QuotationActions::recordDecision()->button(),
                QuotationActions::convert()->button(),
                QuotationActions::requote()->button(),
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ]));
    }
}
