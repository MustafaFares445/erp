<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every supplier answer in one place, across both document types.
 *
 * Uses the simpler single-page `ManageX` shape rather than the full page set
 * (R-010): a confirmation is a handful of fields with no children, so a view
 * page would show the same thing the row already does.
 *
 * The `confirmable_type` filter is the reason this surface exists at all —
 * without it there is no way to answer "which customer orders are waiting on a
 * supplier right now?" across the whole system.
 *
 * @see /specs/017-purchasing-orders-suppliers/spec.md User Story 4
 */
final class SupplierConfirmationResource extends Resource
{
    protected static ?string $model = SupplierConfirmation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.purchasing';

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
            Select::make('confirmable_type')
                ->label(__('admin.purchasing.fields.confirmable_type'))
                ->options(self::targetTypeOptions())
                ->required()
                ->live(),
            Select::make('confirmable_id')
                ->label(__('admin.purchasing.fields.confirmable'))
                ->options(fn (callable $get): array => self::targetOptions($get('confirmable_type')))
                ->searchable()
                ->required(),
            Select::make('supplier_id')
                ->label(__('admin.purchasing.fields.supplier'))
                ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->required(),
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
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('confirmable_type')
                    ->label(__('admin.purchasing.fields.confirmable_type'))
                    ->formatStateUsing(static fn (string $state): string => self::targetTypeOptions()[$state] ?? $state),
                TextColumn::make('confirmable_id')
                    ->label(__('admin.purchasing.fields.confirmable')),
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
                        SupplierConfirmationStatus::Confirmed => 'success',
                        SupplierConfirmationStatus::Rejected => 'danger',
                    }),
                TextColumn::make('promised_at')
                    ->label(__('admin.purchasing.fields.promised_at'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('confirmedBy.name')
                    ->label(__('admin.purchasing.fields.confirmed_by'))
                    ->placeholder('—'),
                TextColumn::make('confirmed_at')
                    ->label(__('admin.purchasing.fields.confirmed_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('confirmation_status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->options(static fn (): array => self::statusOptions()),
                SelectFilter::make('confirmable_type')
                    ->label(__('admin.purchasing.fields.confirmable_type'))
                    ->options(static fn (): array => self::targetTypeOptions()),
                SelectFilter::make('supplier_id')
                    ->label(__('admin.purchasing.fields.supplier'))
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                SupplierConfirmationActions::confirm(),
                SupplierConfirmationActions::reject(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSupplierConfirmations::route('/')];
    }

    /** @return array<string, string> */
    private static function targetTypeOptions(): array
    {
        return [
            PurchaseOrder::class => __('admin.purchasing.confirmable.purchase_order'),
            Order::class => __('admin.purchasing.confirmable.order'),
        ];
    }

    /** @return array<int, string> */
    private static function targetOptions(mixed $type): array
    {
        $numbers = match ($type) {
            PurchaseOrder::class => PurchaseOrder::query()->orderByDesc('id')->pluck('purchase_order_number', 'id'),
            Order::class => Order::query()->orderByDesc('id')->pluck('order_number', 'id'),
            default => collect(),
        };

        $options = [];

        foreach ($numbers as $id => $number) {
            if (is_numeric($id) && is_string($number)) {
                $options[(int) $id] = $number;
            }
        }

        return $options;
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
}
