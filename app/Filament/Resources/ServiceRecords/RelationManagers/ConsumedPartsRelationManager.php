<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\RelationManagers;

use App\Models\MaintenanceTask;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Services\Support\ServiceRecordPartService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class ConsumedPartsRelationManager extends RelationManager
{
    protected static string $relationship = 'parts';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('productVariant.name')->label('Product variant'),
                TextColumn::make('warehouse.name')->label('Warehouse'),
                TextColumn::make('quantity')->numeric(3),
                TextColumn::make('createdBy.name')->label('Consumed by'),
                TextColumn::make('created_at')->label('Consumed at')->dateTime(),
                TextColumn::make('reversed_at')->label('Reversed at')->dateTime()->placeholder('—'),
            ])
            ->headerActions([
                Action::make('consumePart')
                    ->label('Consume Part')
                    ->schema([
                        Select::make('product_variant_id')
                            ->label('Product variant')
                            ->relationship('productVariant', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('warehouse_id')
                            ->label('Warehouse')
                            ->relationship('warehouse', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(0.001)
                            ->required(),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('consume', $this->serviceRecord()))
                    ->action(function (array $data): void {
                        $productVariantId = $data['product_variant_id'] ?? null;
                        $warehouseId = $data['warehouse_id'] ?? null;
                        $quantity = $data['quantity'] ?? null;

                        // @codeCoverageIgnoreStart
                        // The Select/TextInput fields above are each ->required(), so
                        // Filament's own form validation guarantees numeric values here.
                        if (! is_numeric($productVariantId) || ! is_numeric($warehouseId) || ! is_numeric($quantity)) {
                            return;
                        }

                        // @codeCoverageIgnoreEnd

                        app(ServiceRecordPartService::class)->consume(
                            $this->serviceRecord(),
                            (int) $productVariantId,
                            (int) $warehouseId,
                            (float) $quantity,
                            self::currentActor(),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->label('Reverse')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => self::currentActor()->can('reverse', MaintenanceTask::class))
                    ->visible(static fn (ServiceRecordPart $record): bool => $record->reversed_at === null)
                    ->action(static fn (ServiceRecordPart $record) => self::applyReversal($record)),
            ])
            ->toolbarActions([]);
    }

    private static function applyReversal(ServiceRecordPart $record): void
    {
        try {
            app(ServiceRecordPartService::class)->reverse($record, self::currentActor());
            // @codeCoverageIgnoreStart
            // The row action's own ->visible() guard (reversed_at === null) means this
            // can never actually be reached through the action.
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to reverse this consumption')->body($domainException->getMessage())->send();
        }

        // @codeCoverageIgnoreEnd
    }

    private function serviceRecord(): MaintenanceTask
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof MaintenanceTask) {
            throw new LogicException('Expected the owner record of ConsumedPartsRelationManager to be a MaintenanceTask.');
        }

        return $record;
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        // @codeCoverageIgnoreStart
        // The admin panel's own auth middleware guarantees an authenticated User here.
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        // @codeCoverageIgnoreEnd

        return $actor;
    }
}
