<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use App\Support\QuantityFormatter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

final class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder(__('PO, supplier, product, SKU…'))
            ->defaultSort('ordered_at', 'desc')
            ->columns([
                FavoriteColumn::make(),
                ImageColumn::make('supplier.logo_path')
                    ->label(__('Logo'))
                    ->disk('public')
                    ->circular()
                    ->imageHeight(38),
                TextColumn::make('purchase_order_number')
                    ->label(__('Purchase Order'))
                    ->description(static fn (PurchaseOrder $record): string => $record->supplier->name)
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->where('purchase_order_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', static fn (Builder $supplier): Builder => $supplier
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%"))
                        ->orWhereHas('lines', static fn (Builder $lines): Builder => $lines
                            ->where('supplier_item_number', 'like', "%{$search}%")
                            ->orWhereHas('productVariant', static fn (Builder $variant): Builder => $variant
                                ->where('sku', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")
                                ->orWhereHas('product', static fn (Builder $product): Builder => $product
                                    ->where('name', 'like', "%{$search}%")))))
                    ->sortable(),
                TextColumn::make('workflow_stage')
                    ->label(__('Stage'))
                    ->getStateUsing(fn (PurchaseOrder $record): string => self::projection($record)->businessState)
                    ->badge()
                    ->color(fn (PurchaseOrder $record): string => self::stageColor(self::projection($record))),
                TextColumn::make('receiving_progress')
                    ->label(__('Fulfillment'))
                    ->getStateUsing(function (PurchaseOrder $record): string {
                        $projection = self::projection($record);

                        return QuantityFormatter::display($projection->receivedBaseQuantity)
                            .' / '.QuantityFormatter::display($projection->confirmedBaseQuantity);
                    })
                    ->description(fn (PurchaseOrder $record): string => self::receivingDescription(self::projection($record))),
                TextColumn::make('total_amount')
                    ->label(__('Total'))
                    ->money(static fn (PurchaseOrder $record): string => $record->currency_code)
                    ->sortable(),
                TextColumn::make('expected_at')
                    ->label(__('Expected'))
                    ->date()
                    ->placeholder(__('—'))
                    ->color(fn (PurchaseOrder $record): string => self::isOverdue($record) ? 'danger' : 'gray')
                    ->icon(fn (PurchaseOrder $record): ?Heroicon => self::isOverdue($record) ? Heroicon::ExclamationTriangle : null)
                    ->sortable(),
                IconColumn::make('attention')
                    ->label(__('Attention'))
                    ->state(fn (PurchaseOrder $record): bool => self::projection($record)->blocker !== null)
                    ->boolean()
                    ->trueIcon(Heroicon::ExclamationTriangle)
                    ->falseIcon(Heroicon::CheckCircle)
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn (PurchaseOrder $record): string => self::projection($record)->blocker ?? 'No active blocker')
                    ->url(fn (PurchaseOrder $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record])),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Commercial status'))
                        ->options(static fn (): array => self::statusOptions())
                        ->multiple(),
                    TextConstraint::make('purchase_order_number')->label(__('Reference')),
                    RelationshipConstraint::make('supplier')
                        ->label(__('Supplier'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    NumberConstraint::make('total_amount')->label(__('Total')),
                    TextConstraint::make('currency_code')->label(__('Currency')),
                    DateConstraint::make('ordered_at')->label(__('Ordered at')),
                    DateConstraint::make('expected_at')->label(__('Expected')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
                Filter::make('ready_to_send')
                    ->label(__('Ready to send'))
                    ->query(static fn (Builder $query): Builder => $query
                        ->where('status', PurchaseOrderStatus::Accepted->value)
                        ->whereNull('sent_at')),
                Filter::make('awaiting_supplier')
                    ->label(__('Awaiting supplier'))
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereNotNull('sent_at')
                        ->whereHas('confirmations', static fn (Builder $confirmation): Builder => $confirmation->where('confirmation_status', 'pending'))),
                Filter::make('overdue')
                    ->label(__('Overdue'))
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereDate('expected_at', '<', today())
                        ->whereNotIn('status', [
                            PurchaseOrderStatus::Received->value,
                            PurchaseOrderStatus::Closed->value,
                            PurchaseOrderStatus::Cancelled->value,
                        ])),
                Filter::make('accounting_issues')
                    ->label(__('Accounting issues'))
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereIn('status', [PurchaseOrderStatus::Received->value, PurchaseOrderStatus::PartiallyReceived->value])
                        ->whereDoesntHave('bills')),
                TrashedFilter::make(),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Commercial status'))
                    ->getTitleFromRecordUsing(static fn (PurchaseOrder $record): string => $record->status->label()),
                Group::make('supplier.name')->label(__('Supplier')),
                Group::make('currency_code')->label(__('Currency')),
                Group::make('ordered_at')->label(__('Ordered at'))->date(),
            ])
            ->recordActions([
                Action::make('continue')
                    ->label(fn (PurchaseOrder $record): string => self::nextActionLabel($record))
                    ->icon(Heroicon::ArrowRight)
                    ->button()
                    ->color('primary')
                    ->url(fn (PurchaseOrder $record): string => self::nextActionUrl($record)),
                ViewAction::make()->label(__('Details')),
            ]);
    }

    private static function projection(PurchaseOrder $record): PurchaseOrderWorkflowData
    {
        /** @var WeakMap<PurchaseOrder, PurchaseOrderWorkflowData>|null $cache */
        static $cache = null;

        $cache ??= new WeakMap;

        $cached = $cache[$record] ?? null;

        if ($cached instanceof PurchaseOrderWorkflowData) {
            return $cached;
        }

        $projection = app(PurchaseOrderWorkflowService::class)->project($record);
        $cache[$record] = $projection;

        return $projection;
    }

    private static function stageColor(PurchaseOrderWorkflowData $projection): string
    {
        if ($projection->nextOwner === 'None') {
            return 'success';
        }

        if ($projection->blocker !== null) {
            return 'warning';
        }

        return match ($projection->nextOwner) {
            'Purchasing' => 'info',
            'Inventory' => 'primary',
            'Accounting' => 'warning',
            default => 'gray',
        };
    }

    private static function receivingDescription(PurchaseOrderWorkflowData $projection): string
    {
        if ((float) $projection->confirmedBaseQuantity <= 0.000001) {
            return $projection->supplierState;
        }

        if ((float) $projection->receivedBaseQuantity + 0.000001 >= (float) $projection->confirmedBaseQuantity) {
            return 'Fully received';
        }

        if ((float) $projection->receivedBaseQuantity > 0.000001) {
            return 'Partially received';
        }

        return 'Awaiting receipt';
    }

    private static function isOverdue(PurchaseOrder $record): bool
    {
        return $record->expected_at !== null
            && $record->expected_at->isPast()
            && ! $record->status->isTerminal();
    }

    private static function nextActionLabel(PurchaseOrder $record): string
    {
        $projection = self::projection($record);
        $action = mb_strtolower($projection->nextAction);

        return match (true) {
            $projection->nextOwner === 'None' => 'View',
            str_contains($action, 'bill') => 'Review bill',
            str_contains($action, 'payment') || str_contains($action, 'payable') => 'Review payment',
            str_contains($action, 'receive') || str_contains($action, 'receipt') => 'Receive',
            str_contains($action, 'allocate') => 'Allocate',
            str_contains($action, 'approve') => 'Review & approve',
            str_contains($action, 'send') => 'Review & send',
            str_contains($action, 'supplier') || str_contains($action, 'commitment') => 'Supplier response',
            default => 'Continue',
        };
    }

    private static function nextActionUrl(PurchaseOrder $record): string
    {
        $projection = self::projection($record);

        if ($projection->nextOwner === 'Inventory' && $record->purchaseInbound !== null) {
            return PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseInbound]);
        }

        if ($projection->nextOwner === 'Accounting') {
            $bill = $record->bills->sortByDesc('id')->first();

            if ($bill instanceof Bill) {
                return BillResource::getUrl('view', ['record' => $bill]);
            }

            return BillResource::getUrl('index', [
                'action' => 'create',
                'actionArguments' => ['purchase_order_id' => $record->id],
            ]);
        }

        if ($projection->nextOwner === 'Purchasing') {
            $confirmation = $record->confirmations
                ->where('confirmation_status', 'pending')
                ->sortByDesc('id')
                ->first();

            if ($confirmation !== null) {
                return SupplierConfirmationResource::getUrl('view', ['record' => $confirmation]);
            }
        }

        return PurchaseOrderResource::getUrl('view', ['record' => $record]);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (PurchaseOrderStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
