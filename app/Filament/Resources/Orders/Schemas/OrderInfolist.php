<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Data\Sales\OrderFulfillmentLineProgress;
use App\Data\Sales\OrderWorkflowProjection;
use App\Enums\OperationStage;
use App\Enums\OrderCloseSource;
use App\Enums\OrderStatus;
use App\Enums\ResolvedPriceSource;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SalesProcurementRequirement;
use App\Models\Shipment;
use App\Models\Unit;
use App\Services\Sales\OrderFinancialProjectionService;
use App\Services\Sales\OrderFulfillmentQuantityService;
use App\Services\Sales\OrderWorkflowService;
use App\Support\MoneyFormatter;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            self::heroSection(),
            self::statusCallout(),
            self::journeyEntry(),
            Grid::make(12)->schema([
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 8])
                    ->schema([
                        self::fulfillmentProgressSection(),
                        self::linesSection(),
                        self::logisticsSection(),
                        self::supplySection(),
                    ]),
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 4])
                    ->schema([
                        self::orderDetailsSection(),
                        self::commercialSummarySection(),
                        self::financialSection(),
                        self::completionSection(),
                    ]),
            ]),
        ]);
    }

    private static function heroSection(): Grid
    {
        return Grid::make(12)->schema([
            Group::make()
                ->columnSpan(['default' => 12, 'md' => 8])
                ->schema([
                    TextEntry::make('order_number')
                        ->hiddenLabel()
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold),
                    TextEntry::make('customer.company_name')
                        ->hiddenLabel()
                        ->color('gray'),
                ]),
            Group::make()
                ->columnSpan(['default' => 12, 'md' => 4])
                ->schema([
                    TextEntry::make('status')
                        ->hiddenLabel()
                        ->badge()
                        ->formatStateUsing(static fn (OrderStatus $state): string => $state->label())
                        ->color(static fn (OrderStatus $state): string => $state->color()),
                    TextEntry::make('grand_total')
                        ->hiddenLabel()
                        ->money()
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold)
                        ->color('success')
                        ->placeholder('—'),
                ]),
        ]);
    }

    private static function statusCallout(): Callout
    {
        return Callout::make(static fn (Order $record): string => self::milestoneMeta($record)['heading'])
            ->description(static fn (Order $record): string => self::milestoneMeta($record)['description'])
            ->status(static fn (Order $record): string => self::milestoneMeta($record)['status']);
    }

    /** @return array{status: string, heading: string, description: string} */
    private static function milestoneMeta(Order $record): array
    {
        $projection = app(OrderWorkflowService::class)->project($record);
        $milestone = $projection->businessMilestone;

        [$status, $heading, $description] = match ($milestone) {
            'Draft' => ['gray', 'Draft', 'This order has not yet been commercially confirmed.'],
            'Awaiting Release' => ['warning', 'Awaiting release to Logistics', 'This order is commercially confirmed, but Logistics has not received it yet.'],
            'Awaiting Logistics Allocation' => ['warning', 'Awaiting logistics allocation', 'Logistics must allocate the remaining order demand to warehouse stock.'],
            'Partially Allocated' => ['warning', 'Partially allocated', 'Some of the order demand has been allocated to stock; the remainder is still waiting.'],
            'Supply Blocked' => ['danger', 'Supply blocked', 'Part of the order cannot currently be fulfilled and requires procurement.'],
            'Ready to Dispatch' => ['info', 'Ready to dispatch', 'All required quantity is prepared and ready to leave the warehouse.'],
            'In Transit' => ['info', 'In transit', 'The shipment has left the warehouse and is awaiting delivery confirmation.'],
            'Invoice Draft', 'Invoice Pending' => ['warning', 'Invoice pending', 'Delivered goods have not yet been fully invoiced.'],
            'Payment Pending' => ['warning', 'Payment pending', 'The order has an outstanding financial balance.'],
            'Auto Close Pending', 'Awaiting Customer Confirmation' => ['info', 'Awaiting customer confirmation', 'Delivery and payment are complete. Waiting for the customer to confirm completion.'],
            'Closed' => ['success', 'Closed', 'The order lifecycle is complete.'],
            'Cancelled' => ['danger', 'Cancelled', 'This order was cancelled and will not proceed further.'],
            default => ['gray', $milestone, ''],
        };

        return [
            'status' => $status,
            'heading' => $heading,
            'description' => mb_trim($description.' '.self::nextStepSentence($projection)),
        ];
    }

    private static function nextStepSentence(OrderWorkflowProjection $projection): string
    {
        if ($projection->nextActionOwner === 'None') {
            return 'No action required.';
        }

        return "Next: {$projection->nextActionOwner} → {$projection->nextActionLabel}.";
    }

    private static function journeyEntry(): TextEntry
    {
        return TextEntry::make('journey')
            ->hiddenLabel()
            ->state(static fn (Order $record): string => self::journeyState($record))
            ->size(TextSize::Small)
            ->color('gray')
            ->visible(static fn (Order $record): bool => $record->status !== OrderStatus::Cancelled);
    }

    private static function journeyState(Order $record): string
    {
        $projection = app(OrderWorkflowService::class)->project($record);
        $stepIndex = self::journeyStepIndex($record, $projection);

        $steps = ['Order', 'Logistics', 'Delivery', 'Invoice', 'Payment', 'Completion'];
        $rendered = ['Quotation '.($record->quotation_id !== null ? '✓' : '—')];

        foreach ($steps as $index => $label) {
            $symbol = match (true) {
                $stepIndex === null => '✓',
                $index < $stepIndex => '✓',
                $index === $stepIndex => '●',
                default => '○',
            };
            $rendered[] = "{$label} {$symbol}";
        }

        return implode('  →  ', $rendered);
    }

    private static function journeyStepIndex(Order $record, OrderWorkflowProjection $projection): ?int
    {
        if ($record->status === OrderStatus::Closed) {
            return null;
        }

        if ($record->status === OrderStatus::Draft) {
            return 0;
        }

        return match ($projection->businessMilestone) {
            'Awaiting Release', 'Awaiting Logistics Allocation', 'Partially Allocated', 'Supply Blocked' => 1,
            'Ready to Dispatch', 'In Transit' => 2,
            'Invoice Draft', 'Invoice Pending' => 3,
            'Payment Pending' => 4,
            default => 5,
        };
    }

    private static function fulfillmentProgressSection(): Section
    {
        return Section::make('Fulfillment progress')
            ->description("Quantities are shown in each product's base unit of measure.")
            ->schema(function (Order $record): array {
                $projection = app(OrderWorkflowService::class)->project($record);
                $hasBlocker = $projection->procurementOutstandingBase > 0.000001;

                return [
                    Grid::make(2)->schema([
                        TextEntry::make('requested')->label('Requested')->state(QuantityFormatter::display($projection->requestedBase))->weight(FontWeight::Bold),
                        TextEntry::make('planned')->label('Planned')->state(QuantityFormatter::display($projection->plannedBase)),
                        TextEntry::make('dispatched')->label('Dispatched')->state(QuantityFormatter::display($projection->dispatchedBase)),
                        TextEntry::make('arrived')->label('Arrived')->state(QuantityFormatter::display($projection->arrivedBase)),
                    ]),
                    Grid::make(2)->schema([
                        TextEntry::make('remaining_to_plan')
                            ->label('Remaining to plan')
                            ->hintIcon(Heroicon::QuestionMarkCircle, 'Ordered quantity, minus any short-close, that Logistics has not yet allocated to stock.')
                            ->state(QuantityFormatter::display($projection->remainingBase))
                            ->color($projection->remainingBase > 0.000001 ? 'warning' : 'success'),
                        TextEntry::make('supply_blocker')
                            ->label('Supply')
                            ->state($hasBlocker
                                ? QuantityFormatter::display($projection->procurementOutstandingBase).' require procurement'
                                : 'No procurement blocker')
                            ->badge()
                            ->color($hasBlocker ? 'danger' : 'success'),
                    ]),
                ];
            });
    }

    private static function orderDetailsSection(): Section
    {
        return Section::make('Order details')
            ->schema([
                TextEntry::make('order_number')->label('Order')->weight(FontWeight::SemiBold),
                TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                TextEntry::make('quotation.quotation_number')
                    ->label(__('admin.sales.fields.source_quotation'))
                    ->placeholder('Not linked to a quotation'),
                TextEntry::make('status')
                    ->label('Order status')
                    ->badge()
                    ->formatStateUsing(static fn (OrderStatus $state): string => $state->label())
                    ->color(static fn (OrderStatus $state): string => $state->color())
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'The commercial lifecycle state of the sales order.'),
                TextEntry::make('current_stage')
                    ->label('Current stage')
                    ->badge()
                    ->state(static fn (Order $record): string => self::milestoneMeta($record)['heading'])
                    ->color(static fn (Order $record): string => self::milestoneMeta($record)['status'])
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Where this order currently sits across Sales, Logistics, Purchasing, Delivery, Invoicing and Payment.'),
                TextEntry::make('scheduled_at')->label('Requested delivery')->date()->placeholder('Not specified'),
                TextEntry::make('paymentTerm.name')->label(__('admin.sales.fields.payment_term'))->placeholder('Not specified'),
            ]);
    }

    private static function commercialSummarySection(): Section
    {
        return Section::make('Commercial summary')
            ->schema([
                TextEntry::make('subtotal')->label(__('admin.sales.fields.subtotal'))->money()->placeholder('—'),
                TextEntry::make('tax_total')->label('Total tax')->money()->placeholder('—'),
                TextEntry::make('grand_total')
                    ->label(__('admin.sales.fields.grand_total'))
                    ->money()
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold)
                    ->color('success')
                    ->placeholder('—'),
            ]);
    }

    private static function linesSection(): Section
    {
        return Section::make(__('admin.sales.fields.lines'))
            ->schema([
                RepeatableEntry::make('lines')
                    ->label('')
                    ->schema([
                        Grid::make(12)->columnSpanFull()->schema([
                            ImageEntry::make('productVariant.main_image')
                                ->label('')
                                ->getStateUsing(static fn (OrderLine $record): ?string => $record->productVariant?->mainImageUrl())
                                ->circular()
                                ->imageSize(48)
                                ->columnSpan(1),
                            TextEntry::make('productVariant.sku')
                                ->label(__('admin.sales.fields.product_variant'))
                                ->state(static fn (OrderLine $record): string => self::productLabel($record))
                                ->weight(FontWeight::SemiBold)
                                ->columnSpan(7),
                            TextEntry::make('line_total')
                                ->label(__('admin.sales.fields.line_total'))
                                ->money()
                                ->weight(FontWeight::Bold)
                                ->columnSpan(4),
                        ]),
                        Grid::make(12)->columnSpanFull()->schema([
                            TextEntry::make('quantity')
                                ->label('Quantity')
                                ->state(static function (OrderLine $record): string {
                                    $unit = $record->unit;

                                    return mb_trim(QuantityFormatter::display($record->quantity).' '.($unit instanceof Unit ? $unit->name : ''));
                                })
                                ->columnSpan(3),
                            TextEntry::make('unit_price')
                                ->label(__('admin.sales.fields.unit_price'))
                                ->state(static fn (OrderLine $record): string => MoneyFormatter::format((int) round((float) $record->unit_price * 100)).' each')
                                ->columnSpan(3),
                            TextEntry::make('fulfillment')
                                ->label('Fulfillment')
                                ->state(static function (OrderLine $record): string {
                                    $line = self::lineFulfillmentProgress($record);

                                    if (! $line instanceof OrderFulfillmentLineProgress) {
                                        return '—';
                                    }

                                    $effective = max(0.0, $line->orderedBase - $line->shortClosedBase);

                                    return QuantityFormatter::display($line->plannedBase).' / '.QuantityFormatter::display($effective).' planned';
                                })
                                ->columnSpan(3),
                            TextEntry::make('tax_amount')
                                ->label(__('admin.sales.fields.tax_amount'))
                                ->money()
                                ->columnSpan(3),
                        ]),
                        TextEntry::make('short_closed_base_quantity')
                            ->label('Short-closed quantity')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state).' short-closed')
                            ->belowContent('This quantity was intentionally removed from the remaining fulfillment commitment.')
                            ->visible(static fn (OrderLine $record): bool => (float) $record->short_closed_base_quantity > 0.000001)
                            ->columnSpanFull(),
                        self::pricingDetailsSection(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function lineFulfillmentProgress(OrderLine $record): ?OrderFulfillmentLineProgress
    {
        $order = $record->order;

        if (! $order instanceof Order) {
            return null;
        }

        return app(OrderFulfillmentQuantityService::class)
            ->forOrder($order)
            ->firstWhere('orderLineId', $record->id);
    }

    private static function pricingDetailsSection(): Section
    {
        return Section::make('Pricing details')
            ->columnSpanFull()
            ->collapsible()
            ->collapsed(static fn (OrderLine $record): bool => ! self::isBelowFloor($record))
            ->schema([
                TextEntry::make('resolved_price_source')
                    ->label(__('admin.sales.fields.resolved_price_source'))
                    ->badge()
                    ->formatStateUsing(static fn (?ResolvedPriceSource $state): ?string => $state?->label())
                    ->placeholder('Legacy / unknown')
                    ->belowContent(static fn (OrderLine $record): ?string => self::priceSourceHelp($record)),
                TextEntry::make('resolvedPriceTier.name')
                    ->label('Pricing tier')
                    ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.pricing_tier'))
                    ->placeholder('—'),
                TextEntry::make('list_price_minor')
                    ->label('List price at order time')
                    ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.list_price_snapshot'))
                    ->state(static fn (OrderLine $record): ?float => $record->list_price_minor === null ? null : $record->list_price_minor / 100)
                    ->money()
                    ->placeholder('—'),
                TextEntry::make('floor_price_minor')
                    ->label('Minimum allowed price')
                    ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.floor_snapshot'))
                    ->state(static fn (OrderLine $record): ?float => $record->floor_price_minor === null ? null : $record->floor_price_minor / 100)
                    ->money()
                    ->placeholder('—'),
                TextEntry::make('unit_price')->label('Quoted unit price')->money(),
                TextEntry::make('floor_override_status')
                    ->label('Pricing status')
                    ->state(static fn (OrderLine $record): string => self::floorOverrideLabel($record))
                    ->badge()
                    ->color(static fn (OrderLine $record): string => self::floorOverrideColor($record))
                    ->belowContent(static fn (OrderLine $record): ?string => self::floorOverrideHelp($record)),
            ]);
    }

    private static function logisticsSection(): Section
    {
        return Section::make('Logistics')
            ->schema([
                TextEntry::make('logistics_empty')
                    ->hiddenLabel()
                    ->state('No deliveries or shipments exist yet. Release this order to Logistics to begin allocation and delivery planning.')
                    ->color('gray')
                    ->visible(static fn (Order $record): bool => $record->deliveries->isEmpty() && $record->shipments->isEmpty()),
                RepeatableEntry::make('deliveries')
                    ->label('Deliveries')
                    ->columns(4)
                    ->visible(static fn (Order $record): bool => $record->deliveries->isNotEmpty())
                    ->schema([
                        TextEntry::make('operation_number')
                            ->label('Delivery')
                            ->weight(FontWeight::SemiBold)
                            ->url(static fn (InventoryOperation $record): string => DeliveryNoteResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('sourceWarehouse.name')->label('Warehouse')->placeholder('—'),
                        TextEntry::make('stage')->badge()->formatStateUsing(static fn (OperationStage $state): string => $state->label()),
                        TextEntry::make('scheduled_at')->dateTime()->placeholder('—'),
                    ]),
                RepeatableEntry::make('shipments')
                    ->label('Shipments')
                    ->columns(3)
                    ->visible(static fn (Order $record): bool => $record->shipments->isNotEmpty())
                    ->schema([
                        TextEntry::make('tracking_number')
                            ->label('Tracking')
                            ->weight(FontWeight::SemiBold)
                            ->url(static fn (Shipment $record): string => ShipmentResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('warehouse.name')->label('Warehouse')->placeholder('—'),
                        TextEntry::make('status')->badge()->formatStateUsing(static fn (ShipmentStatus $state): string => $state->label()),
                    ]),
            ]);
    }

    private static function supplySection(): Section
    {
        return Section::make('Supply')
            ->visible(static fn (Order $record): bool => $record->status !== OrderStatus::Draft)
            ->schema([
                TextEntry::make('supply_ok')
                    ->hiddenLabel()
                    ->state('✓ No procurement requirements are currently blocking this order.')
                    ->color('success')
                    ->visible(static fn (Order $record): bool => self::openProcurementRequirements($record)->isEmpty()),
                RepeatableEntry::make('open_procurement_requirements')
                    ->label('')
                    ->columns(5)
                    ->state(static fn (Order $record): Collection => self::openProcurementRequirements($record))
                    ->visible(static fn (Order $record): bool => self::openProcurementRequirements($record)->isNotEmpty())
                    ->schema([
                        TextEntry::make('productVariant.sku')->label('Product'),
                        TextEntry::make('required_base_quantity')
                            ->label('Required')
                            ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                        TextEntry::make('outstanding')
                            ->label('Outstanding')
                            ->state(static fn (SalesProcurementRequirement $record): string => QuantityFormatter::display($record->outstandingBaseQuantity())),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(static fn (string $state): string => ucfirst($state))
                            ->color(static fn (string $state): string => match ($state) {
                                'open' => 'warning',
                                'fulfilled' => 'success',
                                default => 'gray',
                            }),
                        TextEntry::make('purchaseOrder.purchase_order_number')
                            ->label('Purchase order')
                            ->placeholder('Owned by Purchasing')
                            ->url(static fn (SalesProcurementRequirement $record): ?string => $record->purchaseOrder !== null
                                ? PurchaseOrderResource::getUrl('view', ['record' => $record->purchaseOrder])
                                : null),
                    ]),
            ]);
    }

    /** @return Collection<int, SalesProcurementRequirement> */
    private static function openProcurementRequirements(Order $record): Collection
    {
        return $record->procurementRequirements
            ->reject(static fn (SalesProcurementRequirement $requirement): bool => $requirement->isFulfilled() || $requirement->status === 'cancelled')
            ->values();
    }

    private static function financialSection(): Section
    {
        return Section::make('Financial status')
            ->schema(function (Order $record): array {
                $financial = app(OrderFinancialProjectionService::class)->project($record);

                $entries = [
                    TextEntry::make('order_total')->label('Order total')->state($financial->orderTotal)->money(),
                ];

                if ($financial->issuedInvoiceCount === 0) {
                    $hasDeposit = $financial->customerDepositCollected > 0.0;

                    $entries[] = TextEntry::make('collected')
                        ->label($hasDeposit ? 'Held as customer deposit' : 'Collected')
                        ->state($financial->customerDepositCollected)
                        ->money();
                    $entries[] = TextEntry::make('invoice_state')->label('Invoice')->state('Not issued yet')->color('gray');
                    $entries[] = $hasDeposit
                        ? TextEntry::make('settlement_state')->label('Financial settlement')->state('Waiting for invoice')->badge()->color('warning')
                        : TextEntry::make('settlement_state')->label('Outstanding')->state('Not yet invoiced')->color('gray');

                    return $entries;
                }

                $entries[] = TextEntry::make('invoice_total')->label('Invoice total')->state($financial->issuedInvoiceTotal)->money();
                $entries[] = TextEntry::make('paid_credited')
                    ->label('Paid / credited')
                    ->state($financial->invoicePaidAmount + $financial->invoiceCreditedAmount)
                    ->money();
                $entries[] = TextEntry::make('outstanding')
                    ->label('Outstanding')
                    ->state($financial->invoiceOutstandingAmount)
                    ->money()
                    ->color($financial->invoiceOutstandingAmount > 0.005 ? 'warning' : 'success');
                $entries[] = TextEntry::make('settlement_status')
                    ->label('Financial settlement')
                    ->state($financial->financiallySettled ? '✓ Complete' : 'Payment pending')
                    ->badge()
                    ->color($financial->financiallySettled ? 'success' : 'warning');

                return $entries;
            });
    }

    private static function completionSection(): Section
    {
        return Section::make('Order completion')
            ->visible(static fn (Order $record): bool => in_array($record->status, [OrderStatus::Released, OrderStatus::Closed], true))
            ->schema(function (Order $record): array {
                if ($record->status === OrderStatus::Closed) {
                    return self::completedEntries($record);
                }

                $projection = app(OrderWorkflowService::class)->project($record);

                if ($projection->blockerCode !== null) {
                    $category = self::completionBlockerCategory($projection->blockerCode, $projection->blockerMessage);

                    return [
                        TextEntry::make('completion_status')->hiddenLabel()->state($category['heading'])->weight(FontWeight::SemiBold),
                        TextEntry::make('completion_reason')->label('Reason')->state($category['reason'])->color('gray'),
                    ];
                }

                $entries = [
                    TextEntry::make('completion_status')
                        ->hiddenLabel()
                        ->state('Waiting for customer confirmation.')
                        ->weight(FontWeight::SemiBold),
                ];

                if ($projection->autoCloseDueAt !== null) {
                    $entries[] = TextEntry::make('auto_close')
                        ->label('Auto-close')
                        ->state($projection->autoCloseDueAt->translatedFormat('M j, Y').' · '.$projection->daysUntilAutoClose.' days remaining');
                }

                return $entries;
            });
    }

    /** @return array<int, TextEntry> */
    private static function completedEntries(Order $record): array
    {
        $entries = [
            TextEntry::make('completion_status')
                ->hiddenLabel()
                ->state($record->closed_by_source === OrderCloseSource::System ? '✓ Completed automatically' : '✓ Completed')
                ->badge()
                ->color('success'),
            TextEntry::make('closed_by')->label('Closed by')->state($record->closed_by_source?->label())->placeholder('—'),
            TextEntry::make('closed_at')->label('Closed at')->state($record->closed_at)->dateTime()->placeholder('—'),
        ];

        if ($record->closed_by_source === OrderCloseSource::Customer) {
            $confirmation = $record->completionConfirmation;
            $count = $confirmation?->getMedia('order-completion-evidence')->count() ?? 0;
            $entries[] = TextEntry::make('evidence')->label('Evidence')->state($count.' file'.($count === 1 ? '' : 's'));
        }

        if ($record->closed_by_source === OrderCloseSource::System) {
            $entries[] = TextEntry::make('policy')
                ->label('Policy')
                ->state($record->auto_close_days_snapshot !== null
                    ? "{$record->auto_close_days_snapshot} days after verified delivery"
                    : '—');
        }

        return $entries;
    }

    /** @return array{heading: string, reason: string} */
    private static function completionBlockerCategory(string $blockerCode, ?string $blockerMessage): array
    {
        $fulfillmentCodes = [
            'not_released', 'procurement_open', 'awaiting_logistics_allocation',
            'delivery_waiting_stock', 'shipment_in_transit',
        ];

        if (in_array($blockerCode, $fulfillmentCodes, true)) {
            return ['heading' => 'Not ready for final confirmation.', 'reason' => 'Fulfillment is still in progress.'];
        }

        return ['heading' => 'Waiting for financial settlement.', 'reason' => $blockerMessage ?? 'Delivered goods have not yet been fully invoiced or paid.'];
    }

    private static function productLabel(OrderLine $record): string
    {
        $variant = $record->productVariant;

        if ($variant === null) {
            return '—';
        }

        $name = $variant->product?->name;

        return $name !== null ? "{$name} ({$variant->sku})" : (string) $variant->sku;
    }

    private static function isBelowFloor(OrderLine $record): bool
    {
        $floorPriceMinor = $record->priceProvenanceAttributes()['floor_price_minor'];

        if ($floorPriceMinor === null) {
            return false;
        }

        return (float) $record->unit_price < ($floorPriceMinor / 100);
    }

    private static function priceSourceHelp(OrderLine $record): ?string
    {
        $source = $record->priceProvenanceAttributes()['resolved_price_source'];

        if (! $source instanceof ResolvedPriceSource) {
            return null;
        }

        return (string) __('admin.sales.quotation_view.price_source_help.'.$source->value);
    }

    private static function floorOverrideLabel(OrderLine $record): string
    {
        if ($record->priceProvenanceAttributes()['floor_price_minor'] === null) {
            return 'Not applicable';
        }

        if (! self::isBelowFloor($record)) {
            return '✓ Within allowed pricing range';
        }

        return $record->priceFloorOverride?->approvedBy !== null
            ? '⚠ Approved exception'
            : '⚠ Pending approval';
    }

    private static function floorOverrideColor(OrderLine $record): string
    {
        if ($record->priceProvenanceAttributes()['floor_price_minor'] === null) {
            return 'gray';
        }

        return self::isBelowFloor($record) ? 'warning' : 'success';
    }

    private static function floorOverrideHelp(OrderLine $record): ?string
    {
        if ($record->priceProvenanceAttributes()['floor_price_minor'] === null) {
            return null;
        }

        if (! self::isBelowFloor($record)) {
            return (string) __('admin.sales.quotation_view.within_floor');
        }

        $approver = $record->priceFloorOverride?->approvedBy?->name;

        return $approver !== null
            ? (string) __('admin.sales.quotation_view.below_floor_approved', ['name' => $approver])
            : (string) __('admin.sales.quotation_view.below_floor_pending');
    }
}
