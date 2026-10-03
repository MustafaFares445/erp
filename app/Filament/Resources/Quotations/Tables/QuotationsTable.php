<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Tables;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Actions\QuotationActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\EmployeeProfile;
use App\Models\Quotation;
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

final class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder(__('Search by quotation number, customer name, or customer code'))
            ->searchDebounce('300ms')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('quotation_number')->label(__('admin.sales.fields.quotation_number'))->searchable()->sortable(),
                TextColumn::make('customer.company_name')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable(['customer.company_name', 'customer.customer_code'])
                    ->description(fn (Quotation $record): ?string => $record->customer?->customer_code),
                TextColumn::make('status')
                    ->label(__('admin.sales.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (QuotationStatus $state): string => $state->label())
                    ->color(static fn (QuotationStatus $state): string => $state->color()),
                TextColumn::make('reservation_coverage')
                    ->label(__('Stock coverage'))
                    ->state(fn (Quotation $record): string => match (true) {
                        $record->status !== QuotationStatus::Accepted && $record->converted_order_id === null => 'Not checked',
                        $record->hasLapsedReservations() => 'Insufficient',
                        default => 'Available',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Insufficient' => 'danger',
                        'Available' => 'success',
                        default => 'gray',
                    }),
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
            ->recordActions([
                ViewAction::make(),
                QuotationActions::send(),
                QuotationActions::recordDecision(),
                QuotationActions::convert(),
                QuotationActions::requote(),
            ]);
    }
}
