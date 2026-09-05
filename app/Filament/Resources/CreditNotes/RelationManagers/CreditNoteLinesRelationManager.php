<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\RelationManagers;

use App\Enums\CreditNoteStockConsequence;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\InventoryReturnLine;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Sales\CreditNoteService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreditNoteLinesRelationManager extends RelationManager
{
    use InteractsWithSalesServices;

    protected static string $relationship = 'lines';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('description'),
                TextColumn::make('invoiceLine.description')->label(__('admin.sales.fields.invoice_line'))->placeholder('—'),
                TextColumn::make('inventoryReturnLine.id')
                    ->label(__('admin.sales.fields.inventory_return_line'))
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? 'Return line #'.(int) $state : '—')
                    ->placeholder('—'),
                TextColumn::make('quantity')->numeric(decimalPlaces: 3),
                TextColumn::make('unit_price')->label(__('admin.sales.fields.unit_price'))->money(),
                TextColumn::make('tax_amount')->label(__('admin.sales.fields.tax_amount'))->money(),
                TextColumn::make('line_total')->label(__('admin.sales.fields.line_total'))->money(),
            ])
            ->headerActions([$this->addLineAction()])
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn (): bool => $this->creditNoteRecord()->isDraft())
                    ->authorize(fn (): bool => self::salesActor()?->can('update', $this->creditNoteRecord()) ?? false)
                    ->action(fn (CreditNoteLine $record) => self::runSalesOperation(
                        function () use ($record): void {
                            $actor = self::salesActor();

                            if (! $actor instanceof User) {
                                throw new LogicException('An authenticated sales user is required.');
                            }

                            app(CreditNoteService::class)->removeLine($actor, $record);
                        },
                    )),
            ])
            ->toolbarActions([]);
    }

    private function addLineAction(): Action
    {
        return Action::make('addLine')
            ->label(__('admin.sales.actions.add_line'))
            ->visible(fn (): bool => $this->creditNoteRecord()->isDraft()
                && (self::salesActor()?->can('update', $this->creditNoteRecord()) ?? false))
            ->authorize(fn (): bool => self::salesActor()?->can('update', $this->creditNoteRecord()) ?? false)
            ->schema([
                Select::make('invoice_line_id')
                    ->label(__('admin.sales.fields.invoice_line'))
                    ->options(fn (): array => $this->invoiceLineOptions())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $line = is_numeric($state) ? InvoiceLine::query()->find((int) $state) : null;

                        if ($line instanceof InvoiceLine) {
                            $set('description', $line->description);
                            $set('unit_price', $line->unit_price);
                            $set('tax_amount', $line->tax_amount);
                        }
                    }),
                Select::make('inventory_return_line_id')
                    ->label(__('admin.sales.fields.inventory_return_line'))
                    ->options(fn (): array => $this->inventoryReturnLineOptions())
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => $this->creditNoteRecord()->stock_consequence === CreditNoteStockConsequence::GoodsReturned)
                    ->required(fn (): bool => $this->creditNoteRecord()->stock_consequence === CreditNoteStockConsequence::GoodsReturned),
                TextInput::make('quantity')
                    ->label(__('admin.sales.fields.quantity'))
                    ->numeric()
                    ->minValue(0.001)
                    ->required(),
                TextInput::make('unit_price')
                    ->label(__('admin.sales.fields.unit_price'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('tax_amount')
                    ->label(__('admin.sales.fields.tax_amount'))
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Textarea::make('description')
                    ->label(__('admin.sales.fields.description'))
                    ->required()
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated sales user is required.');
                }

                $invoiceLineId = $data['invoice_line_id'] ?? null;
                $inventoryReturnLineId = $data['inventory_return_line_id'] ?? null;

                self::runSalesOperation(
                    fn () => app(CreditNoteService::class)->addLine(
                        $actor,
                        $this->creditNoteRecord(),
                        self::stringFrom($data['description'] ?? null),
                        self::floatFrom($data['quantity'] ?? null),
                        self::floatFrom($data['unit_price'] ?? null),
                        self::floatFrom($data['tax_amount'] ?? null),
                        is_numeric($invoiceLineId) ? InvoiceLine::query()->find((int) $invoiceLineId) : null,
                        is_numeric($inventoryReturnLineId)
                            ? InventoryReturnLine::query()->find((int) $inventoryReturnLineId)
                            : null,
                    ),
                );
            });
    }

    /** @return array<int, string> */
    private function invoiceLineOptions(): array
    {
        $invoiceId = $this->creditNoteRecord()->invoice_id;

        if (! is_int($invoiceId)) {
            return [];
        }

        return InvoiceLine::query()
            ->where('invoice_id', $invoiceId)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (InvoiceLine $line): array => [
                self::integerKey($line) => sprintf('%s — %s', $line->description ?: 'Line', (string) $line->quantity),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function inventoryReturnLineOptions(): array
    {
        $returnId = $this->creditNoteRecord()->inventory_return_id;

        if (! is_int($returnId)) {
            return [];
        }

        $service = app(CreditNoteService::class);

        return InventoryReturnLine::query()
            ->with('productVariant')
            ->where('inventory_return_id', $returnId)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (InventoryReturnLine $line) use ($service): array {
                $returned = (string) $line->transaction_quantity;
                $credited = $service->creditedQuantityForReturnLine($line);
                $remaining = bcsub($returned, $credited, 6);
                $sku = $line->productVariant?->sku ?? (string) $line->product_variant_id;

                return [self::integerKey($line) => sprintf(
                    '%s — returned %s / credited %s / remaining %s',
                    $sku,
                    $returned,
                    $credited,
                    $remaining,
                )];
            })
            ->all();
    }

    private static function integerKey(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            throw new LogicException('Sales records must use integer identifiers.');
        }

        return $key;
    }

    private function creditNoteRecord(): CreditNote
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof CreditNote) {
            throw new LogicException('Expected a CreditNote owner record.');
        }

        return $record;
    }
}
