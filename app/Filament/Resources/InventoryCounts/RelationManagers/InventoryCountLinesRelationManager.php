<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts\RelationManagers;

use App\Enums\InventoryPermission;
use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Models\InventoryCountLine;
use App\Models\User;
use App\Services\Inventory\InventoryCountService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Inline per-line counting (WP-3.5, GAP-MW-06) — the "uncounted" filter
 * defaults on, so an operator working the sheet sees exactly what is left to
 * count rather than having to look for it.
 */
final class InventoryCountLinesRelationManager extends RelationManager
{
    use InteractsWithInventoryServices;

    protected static string $relationship = 'lines';

    protected static ?string $title = 'Count lines';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('productVariant.sku')->label('SKU'),
                TextColumn::make('productVariant.name')->label('Variant'),
                TextColumn::make('lot.lot_number')->label('Lot')->placeholder('—'),
                TextColumn::make('serializedUnit.serial_number')->label('Serial')->placeholder('—'),
                TextColumn::make('stock_condition')->badge(),
                TextColumn::make('system_base_quantity')->label('System')->numeric(decimalPlaces: 6),
                TextColumn::make('counted_base_quantity')->label('Counted')->numeric(decimalPlaces: 6)->placeholder('Uncounted'),
                TextColumn::make('variance_base_quantity')->label('Variance')->numeric(decimalPlaces: 6)->placeholder('—'),
                IconColumn::make('recount_requested')->label('Flagged')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('uncounted')
                    ->label('Uncounted')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('counted_base_quantity'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('counted_base_quantity'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('record_count')
                    ->label('Record count')
                    ->schema([
                        TextInput::make('quantity')
                            ->label('Counted quantity')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can(InventoryPermission::CountRecord->value) ?? false)
                    ->action(function (InventoryCountLine $record, array $data): void {
                        $quantity = $data['quantity'] ?? null;

                        if (! is_numeric($quantity)) {
                            throw new LogicException('A counted quantity is required.');
                        }

                        $this->runLineAction(
                            fn (InventoryCountService $service, User $actor): InventoryCountLine => $service->recordCount($record, (string) $quantity, $actor),
                            'Count recorded.',
                        );
                    }),
                Action::make('request_recount')
                    ->label('Request recount')
                    ->color('warning')
                    ->visible(fn (): bool => auth()->user()?->can(InventoryPermission::CountRecord->value) ?? false)
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->action(function (InventoryCountLine $record, array $data): void {
                        $reason = $data['reason'] ?? null;

                        if (! is_string($reason)) {
                            throw new LogicException('A reason is required to request a recount.');
                        }

                        $this->runLineAction(
                            fn (InventoryCountService $service, User $actor): InventoryCountLine => $service->requestRecount($record, $actor, $reason),
                            'Recount requested.',
                        );
                    }),
                Action::make('accept_variance')
                    ->label('Accept variance')
                    ->color('gray')
                    ->visible(fn (InventoryCountLine $record): bool => $record->recount_requested
                        && (auth()->user()?->can(InventoryPermission::CountConfirm->value) ?? false))
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->action(function (InventoryCountLine $record, array $data): void {
                        $reason = $data['reason'] ?? null;

                        if (! is_string($reason)) {
                            throw new LogicException('A reason is required to accept a flagged variance.');
                        }

                        $this->runLineAction(
                            fn (InventoryCountService $service, User $actor): InventoryCountLine => $service->acceptVariance($record, $actor, $reason),
                            'Variance accepted.',
                        );
                    }),
            ])
            ->toolbarActions([]);
    }

    private function runLineAction(callable $operation, string $successMessage): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory count actor is required.');
        }

        $this->runInventoryOperation(
            fn (): mixed => $operation(app(InventoryCountService::class), $actor),
            $successMessage,
        );
    }
}
