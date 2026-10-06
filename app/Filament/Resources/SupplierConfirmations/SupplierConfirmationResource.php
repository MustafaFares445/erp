<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Filament\Resources\SupplierConfirmations\Pages\ViewSupplierConfirmation;
use App\Filament\Resources\SupplierConfirmations\Schemas\SupplierConfirmationInfolist;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Support\QuantityFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class SupplierConfirmationResource extends Resource
{
    protected static ?string $model = SupplierConfirmation::class;

    protected static bool $shouldRegisterNavigation = false;

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
    public static function infolist(Schema $schema): Schema
    {
        return SupplierConfirmationInfolist::configure($schema);
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('purchase_order_id')
                ->label(__('admin.purchasing.fields.purchase_order'))
                ->options(fn (): array => PurchaseOrder::query()
                    ->whereIn('status', [
                        PurchaseOrderStatus::Accepted->value,
                        PurchaseOrderStatus::PartiallyReceived->value,
                    ])
                    ->whereNotNull('sent_at')
                    ->where(function (Builder $query): void {
                        $query->where('supplier_confirmation_required', true)
                            ->orWhere(function (Builder $legacy): void {
                                $legacy->whereNull('supplier_confirmation_required')
                                    ->whereHas('supplier', static fn (Builder $supplier): Builder => $supplier->where('requires_confirmation', true));
                            });
                    })
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
            ->searchPlaceholder(__('Search PO number, supplier, status, or notes…'))
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
                TextColumn::make('communication_state')
                    ->label(__('PO communication'))
                    ->getStateUsing(fn (SupplierConfirmation $record): string => $record->purchaseOrder?->sent_at === null ? 'Not sent' : 'Sent')
                    ->badge()
                    ->color(fn (SupplierConfirmation $record): string => $record->purchaseOrder?->sent_at === null ? 'warning' : 'success'),
                TextColumn::make('requested_total')
                    ->label(__('Requested'))
                    ->getStateUsing(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('requested_base_quantity'))),
                TextColumn::make('confirmed_total')
                    ->label(__('Confirmed'))
                    ->getStateUsing(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('confirmed_base_quantity'))),
                TextColumn::make('backordered_total')
                    ->label(__('Backordered'))
                    ->getStateUsing(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('backordered_base_quantity'))),
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
                TextColumn::make('supplier_reference')->label(__('Supplier reference'))->searchable()->placeholder(__('—')),
                TextColumn::make('promised_at')->label(__('admin.purchasing.fields.promised_at'))->date()->placeholder(__('—'))->sortable(),
                TextColumn::make('overdue')
                    ->label(__('Promise'))
                    ->getStateUsing(fn (SupplierConfirmation $record): string => $record->promised_at === null || ! $record->isAnswered()
                        ? '—'
                        : (self::isOverdue($record) ? 'Overdue' : 'On track'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'Overdue' => 'danger',
                        'On track' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('next_action')
                    ->label(__('Next action'))
                    ->getStateUsing(fn (SupplierConfirmation $record): string => self::nextAction($record))
                    ->wrap(),
                TextColumn::make('notes')->label(__('admin.purchasing.fields.notes'))->searchable()->limit(60)->wrap()->placeholder(__('—')),
                TextColumn::make('created_at')->label(__('admin.common.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label(__('admin.purchasing.fields.supplier'))
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('confirmation_status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->options(static fn (): array => self::statusOptions()),
                Filter::make('awaiting_response')
                    ->label(__('Awaiting response'))
                    ->query(static fn (Builder $query): Builder => $query
                        ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
                        ->whereHas('purchaseOrder', static fn (Builder $purchaseOrder): Builder => $purchaseOrder->whereNotNull('sent_at'))),
                Filter::make('overdue')
                    ->label(__('Overdue supplier promises'))
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereNotNull('promised_at')
                        ->whereDate('promised_at', '<', today())
                        ->whereIn('confirmation_status', [
                            SupplierConfirmationStatus::Confirmed->value,
                            SupplierConfirmationStatus::Partial->value,
                        ])
                        ->whereHas('purchaseOrder', static fn (Builder $order): Builder => $order->whereIn('status', [
                            PurchaseOrderStatus::Accepted->value,
                            PurchaseOrderStatus::PartiallyReceived->value,
                        ]))),
            ])
            ->recordActions([
                SupplierConfirmationActions::response()
                    ->label(__('Record supplier response'))
                    ->button(),
                Action::make('reviewAndSendPurchaseOrder')
                    ->label(__('Review & send PO'))
                    ->icon(Heroicon::PaperAirplane)
                    ->button()
                    ->color('primary')
                    ->visible(fn (SupplierConfirmation $record): bool => self::primaryLink($record) === 'send_po')
                    ->url(fn (SupplierConfirmation $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record->purchase_order_id])),
                Action::make('reviewSupplierCommitment')
                    ->label(__('Review supplier commitment'))
                    ->icon(Heroicon::ExclamationTriangle)
                    ->button()
                    ->color('warning')
                    ->visible(fn (SupplierConfirmation $record): bool => self::primaryLink($record) === 'commitment')
                    ->url(fn (SupplierConfirmation $record): string => self::getUrl('view', ['record' => $record])),
                Action::make('openInbound')
                    ->label(__('Open inbound'))
                    ->icon(Heroicon::Truck)
                    ->button()
                    ->color('primary')
                    ->visible(fn (SupplierConfirmation $record): bool => self::primaryLink($record) === 'inbound')
                    ->url(fn (SupplierConfirmation $record): ?string => $record->purchaseOrder?->purchaseInbound instanceof PurchaseInbound
                        ? PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseOrder->purchaseInbound])
                        : null),
                ViewAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageSupplierConfirmations::route('/'),
            'view' => ViewSupplierConfirmation::route('/{record}'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'purchaseOrder',
            'supplier',
            'confirmedBy',
            'items.productVariant.product',
            'items.purchaseOrderLine',
        ]);
    }

    /**
     * The single navigation primary for a confirmation that needs no response
     * form: send the PO, review an overdue commitment, or follow the inbound.
     */
    private static function primaryLink(SupplierConfirmation $confirmation): ?string
    {
        $user = auth()->user();
        $order = $confirmation->purchaseOrder;

        if ($order === null) {
            return null;
        }

        if ($confirmation->confirmation_status === SupplierConfirmationStatus::Pending) {
            return $order->sent_at === null
                && ($user?->can(PurchasePermission::OrderSend->value) ?? false)
                && self::canViewOrder($order)
                    ? 'send_po'
                    : null;
        }

        if (self::isOverdue($confirmation)) {
            return self::canView($confirmation) ? 'commitment' : null;
        }

        if ($confirmation->confirmation_status === SupplierConfirmationStatus::Rejected) {
            return null;
        }

        $inbound = $order->purchaseInbound;

        return $inbound instanceof PurchaseInbound && PurchaseInboundResource::canView($inbound)
            ? 'inbound'
            : null;
    }

    private static function canViewOrder(PurchaseOrder $order): bool
    {
        return PurchaseOrderResource::canView($order);
    }

    private static function isOverdue(SupplierConfirmation $confirmation): bool
    {
        $order = $confirmation->purchaseOrder;

        return $confirmation->promised_at !== null
            && $confirmation->promised_at->isBefore(today())
            && in_array($confirmation->confirmation_status, [
                SupplierConfirmationStatus::Confirmed,
                SupplierConfirmationStatus::Partial,
            ], true)
            && $order !== null
            && in_array($order->status, [
                PurchaseOrderStatus::Accepted,
                PurchaseOrderStatus::PartiallyReceived,
            ], true);
    }

    private static function nextAction(SupplierConfirmation $confirmation): string
    {
        if ($confirmation->confirmation_status === SupplierConfirmationStatus::Pending) {
            return $confirmation->purchaseOrder?->sent_at === null
                ? 'Send Purchase Order to supplier'
                : 'Record supplier response';
        }

        return match ($confirmation->confirmation_status) {
            SupplierConfirmationStatus::Partial => 'Follow up backordered quantity',
            SupplierConfirmationStatus::Confirmed => 'Monitor inbound receiving',
            SupplierConfirmationStatus::Rejected => 'Resolve supplier exception',
        };
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
