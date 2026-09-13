<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\OperationStage;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InvoiceDeliveryLink;
use App\Services\Accounting\AccountsReceivableService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CustomersTable
{
    /** @var array<int, array{customer_id: int, customer_name: string, customer_deleted: bool, billed_minor: int, credited_minor: int, paid_minor: int, written_off_minor: int, outstanding_minor: int, buckets: mixed}>|null */
    private static ?array $agingIndex = null;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestInteraction'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer_code')->label('Customer code')->searchable()->sortable(),
                TextColumn::make('company_name')->label('Company name')->searchable()->sortable(),
                TextColumn::make('user.name')->label('Account name')->searchable(),
                TextColumn::make('user.username')->label('Username')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.email')->label('Account email')->searchable(),
                TextColumn::make('email')->label('Company email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('deliveries_awaiting_invoice')
                    ->label('Deliveries awaiting invoice')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->state(fn (CustomerProfile $record): int => self::deliveriesAwaitingInvoiceCount($record)),
                TextColumn::make('outstanding_balance')
                    ->label('Outstanding')
                    ->state(fn (CustomerProfile $record): string => number_format(self::outstandingMinorFor($record) / 100, 2)),
                TextColumn::make('latestInteraction.occurred_at')
                    ->label('Last interaction')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TrashedFilter::make(),
                Filter::make('inactive_90_days')
                    ->label('Inactive 90 days')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDoesntHave('latestInteraction', fn (Builder $interactions): Builder => $interactions
                            ->where('occurred_at', '>=', now()->subDays(90)))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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

    /**
     * Completed deliveries for this customer with no {@see InvoiceDeliveryLink}
     * row yet (WP-2.13, GAP-MW-13) — the operator-facing half of the leak that consolidated
     * invoicing closes.
     */
    private static function deliveriesAwaitingInvoiceCount(CustomerProfile $record): int
    {
        return InventoryOperation::query()
            ->where('customer_id', $record->getKey())
            ->where('stage', OperationStage::Done->value)
            ->whereDoesntHave('invoiceDeliveryLink')
            ->count();
    }

    /**
     * Reads {@see AccountsReceivableService::aging()} once per table render and
     * indexes it by customer (XC-04's no-disagreeing-rules principle: this
     * column never recomputes the figure {@see AccountsReceivableService}
     * already owns), rather than once per row.
     */
    private static function outstandingMinorFor(CustomerProfile $record): int
    {
        if (self::$agingIndex === null) {
            $aging = app(AccountsReceivableService::class)->aging();
            /** @var array<int, array{customer_id: int, customer_name: string, customer_deleted: bool, billed_minor: int, credited_minor: int, paid_minor: int, written_off_minor: int, outstanding_minor: int, buckets: mixed}> $index */
            $index = [];

            foreach ($aging['customers'] as $customerRow) {
                $index[$customerRow['customer_id']] = $customerRow;
            }

            self::$agingIndex = $index;
        }

        $row = self::$agingIndex[$record->id] ?? null;

        return $row === null ? 0 : $row['outstanding_minor'];
    }
}
