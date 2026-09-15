<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class SupplierConfirmationResource extends Resource
{
    protected static ?string $model = SupplierConfirmation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.vendors';

    protected static ?int $navigationSort = 103;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.supplier_confirmations');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.supplier_confirmations');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('purchase_order_id')
                ->label(__('admin.purchasing.fields.purchase_order'))
                ->options(fn (): array => PurchaseOrder::query()
                    ->whereHas('lines')
                    ->orderByDesc('id')
                    ->pluck('purchase_order_number', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->live()
                ->required(),
            Placeholder::make('supplier')
                ->label(__('admin.purchasing.fields.supplier'))
                ->content(fn (Get $get): string => self::poSupplier($get('purchase_order_id'))),
            Placeholder::make('scope')
                ->label(__('admin.purchasing.fields.lines'))
                ->content(__('admin.purchasing.hints.confirmation_all_outstanding_lines')),
            Textarea::make('notes')
                ->label(__('admin.purchasing.fields.notes'))
                ->rows(3)
                ->maxLength(1000)
                ->columnSpanFull(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('purchaseOrder.purchase_order_number')
                    ->label(__('admin.purchasing.fields.purchase_order'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label(__('admin.purchasing.fields.supplier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('confirmation_status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (SupplierConfirmationStatus $state): string => $state->label())
                    ->color(static fn (SupplierConfirmationStatus $state): string => match ($state) {
                        SupplierConfirmationStatus::Pending => 'warning',
                        SupplierConfirmationStatus::Partial => 'warning',
                        SupplierConfirmationStatus::Confirmed => 'success',
                        SupplierConfirmationStatus::Rejected => 'danger',
                    }),
                TextColumn::make('promised_at')->label(__('admin.purchasing.fields.promised_at'))->date()->placeholder('—')->sortable(),
                TextColumn::make('notes')->label(__('admin.purchasing.fields.notes'))->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('created_at')->label(__('admin.common.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('confirmation_status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->options(static fn (): array => self::statusOptions()),
            ])
            ->recordActions([
                SupplierConfirmationActions::response(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSupplierConfirmations::route('/')];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (SupplierConfirmationStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    private static function poSupplier(mixed $purchaseOrderId): string
    {
        if (! is_numeric($purchaseOrderId)) {
            return '—';
        }

        $order = PurchaseOrder::query()->with('supplier')->find((int) $purchaseOrderId);

        return $order?->supplier->name ?? '—';
    }
}
