<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReceipts;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryReceipts\Pages\ManageInventoryReceipts;
use App\Models\InventoryReceipt;
use App\Models\User;
use App\Services\Inventory\InventoryReceivingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class InventoryReceiptResource extends Resource
{
    use InteractsWithInventoryServices;

    protected static ?string $model = InventoryReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('warehouse_id')->relationship('warehouse', 'name')->required()->searchable()->preload(),
                Select::make('supplier_id')->relationship('supplier', 'name')->searchable()->preload(),
                TextInput::make('supplier_reference')->maxLength(255),
                Textarea::make('notes')->columnSpanFull(),
            ]),
            Repeater::make('items')
                ->relationship()
                ->schema([
                    Select::make('product_variant_id')->relationship('productVariant', 'sku')->required()->searchable()->preload(),
                    Select::make('unit_id')->relationship('unit', 'name')->searchable()->preload(),
                    TextInput::make('quantity')->numeric()->minValue(0.001)->required(),
                    TextInput::make('purchase_cost')->numeric()->minValue(0)->step(0.01),
                    TextInput::make('currency_code')->default('USD')->maxLength(3),
                    TextInput::make('lot_number')->maxLength(100),
                    DatePicker::make('expires_at'),
                    Repeater::make('serializedUnits')
                        ->relationship()
                        ->schema([
                            TextInput::make('serial_number')->required()->maxLength(255)->unique(ignoreRecord: true),
                            TextInput::make('iot_number')->maxLength(255)->unique(ignoreRecord: true),
                        ])
                        ->columnSpanFull(),
                ])
                ->columnSpanFull()
                ->defaultItems(1),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('receipt_number')->placeholder('Pending')->searchable()->sortable(),
            TextColumn::make('warehouse.name')->searchable()->sortable(),
            TextColumn::make('supplier.name')->searchable()->sortable(),
            TextColumn::make('supplier_reference')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('items_count')->counts('items'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(['draft' => 'Draft', 'confirmed' => 'Confirmed']),
            SelectFilter::make('warehouse_id')->relationship('warehouse', 'name')->searchable()->preload(),
            TrashedFilter::make(),
        ])->recordActions([
            Action::make('confirm')
                ->color('success')
                ->visible(fn (InventoryReceipt $record): bool => $record->isDraft() && (auth()->user()?->can('confirm', $record) ?? false))
                ->authorize(fn (InventoryReceipt $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                ->requiresConfirmation()
                ->action(function (InventoryReceipt $record): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    app(self::class)->runInventoryOperation(
                        fn () => app(InventoryReceivingService::class)->confirm($record, $actor),
                        'admin.inventory.receipt.notifications.confirmed',
                    );
                }),
            EditAction::make()->visible(fn (InventoryReceipt $record): bool => $record->isDraft()),
            DeleteAction::make()->visible(fn (InventoryReceipt $record): bool => $record->isDraft()),
            RestoreAction::make(),
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventoryReceipts::route('/')];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
