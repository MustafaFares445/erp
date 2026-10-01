<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Schemas;

use App\Enums\QuotationStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationResponse;
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

final class QuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            self::heroSection(),
            self::workflowEntry(),
            self::statusCallout(),
            Grid::make(['default' => 1, 'md' => 2, 'lg' => 4])->schema([
                self::quotationDetailsSection(),
                self::customerSection(),
                self::customerDecisionSection(),
                self::commercialSummarySection(),
            ]),
            self::linesSection(),
            self::responseHistorySection(),
        ]);
    }

    private static function heroSection(): Grid
    {
        return Grid::make(12)->schema([
            Group::make()
                ->columnSpan(['default' => 12, 'md' => 8])
                ->schema([
                    TextEntry::make('quotation_number')
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
                        ->formatStateUsing(static fn (QuotationStatus $state): string => $state->label())
                        ->color(static fn (QuotationStatus $state): string => $state->color())
                        ->belowContent(static fn (Quotation $record): string => self::statusHelp($record)),
                    TextEntry::make('grand_total')
                        ->hiddenLabel()
                        ->money()
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold)
                        ->color('success'),
                ]),
        ]);
    }

    private static function workflowEntry(): TextEntry
    {
        return TextEntry::make('workflow_summary')
            ->hiddenLabel()
            ->state(static fn (Quotation $record): string => self::workflowState($record))
            ->size(TextSize::Small)
            ->color('gray');
    }

    private static function workflowState(Quotation $record): string
    {
        $quotation = __('admin.sales.quotation_view.workflow_quotation');
        $order = __('admin.sales.quotation_view.workflow_order');

        if ($record->converted_order_id !== null) {
            return "{$quotation} ✓  →  {$order} ✓ ({$record->convertedOrder?->order_number})";
        }

        if ($record->status === QuotationStatus::Accepted) {
            return "{$quotation} ✓  →  {$order} · ".__('admin.sales.quotation_view.workflow_next');
        }

        return "{$quotation}  →  {$order}";
    }

    private static function statusCallout(): Callout
    {
        return Callout::make(static fn (Quotation $record): ?string => self::calloutHeading($record))
            ->description(static fn (Quotation $record): ?string => self::calloutDescription($record))
            ->status(static fn (Quotation $record): ?string => self::calloutStatus($record))
            ->visible(static fn (Quotation $record): bool => self::calloutStatus($record) !== null);
    }

    private static function calloutStatus(Quotation $record): ?string
    {
        return match (true) {
            $record->converted_order_id !== null => 'info',
            $record->status === QuotationStatus::Accepted => 'success',
            $record->status === QuotationStatus::ChangesRequested => 'warning',
            $record->isExpired() => 'warning',
            default => null,
        };
    }

    private static function calloutHeading(Quotation $record): ?string
    {
        return match (self::calloutStatus($record)) {
            'success' => (string) __('admin.sales.quotation_view.accepted_callout_heading'),
            'info' => (string) __('admin.sales.quotation_view.converted_callout_heading'),
            'warning' => (string) ($record->status === QuotationStatus::ChangesRequested
                ? __('admin.sales.quotation_view.changes_requested_callout_heading')
                : __('admin.sales.quotation_view.expired_callout_heading')),
            default => null,
        };
    }

    private static function calloutDescription(Quotation $record): ?string
    {
        return match (self::calloutStatus($record)) {
            'success' => (string) __('admin.sales.quotation_view.accepted_callout_description'),
            'info' => (string) __('admin.sales.quotation_view.converted_callout_description', ['order' => (string) $record->convertedOrder?->order_number]),
            'warning' => (string) ($record->status === QuotationStatus::ChangesRequested
                ? __('admin.sales.quotation_view.changes_requested_callout_description')
                : __('admin.sales.quotation_view.expired_callout_description')),
            default => null,
        };
    }

    private static function statusHelp(Quotation $record): string
    {
        $key = $record->isExpired() && $record->status === QuotationStatus::Sent
            ? 'expired'
            : $record->status->value;

        return (string) __('admin.sales.quotation_view.status_help.'.$key);
    }

    private static function quotationDetailsSection(): Section
    {
        return Section::make(__('Quotation details'))
            ->schema([
                TextEntry::make('quotation_number')->label(__('admin.sales.fields.quotation_number')),
                TextEntry::make('issue_date')->label(__('admin.sales.fields.issue_date'))->date(),
                TextEntry::make('expires_at')->label(__('admin.sales.fields.expires_at'))->date()->placeholder(__('—')),
            ]);
    }

    private static function customerSection(): Section
    {
        return Section::make(__('Customer'))
            ->schema([
                TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                TextEntry::make('customerQuotationRequest.request_number')
                    ->label(__('Linked quote request'))
                    ->hintIcon(Heroicon::QuestionMarkCircle, "The customer's inbound quote request that this quotation was created to answer.")
                    ->visible(static fn (Quotation $record): bool => $record->customerQuotationRequest !== null),
            ]);
    }

    private static function customerDecisionSection(): Section
    {
        return Section::make(__('Customer decision'))
            ->visible(static fn (Quotation $record): bool => $record->decided_at !== null)
            ->schema([
                TextEntry::make('decision_status')
                    ->label(__('Decision'))
                    ->state(static fn (Quotation $record): QuotationStatus => $record->status)
                    ->badge()
                    ->formatStateUsing(static fn (QuotationStatus $state): string => $state->label())
                    ->color(static fn (QuotationStatus $state): string => $state->color()),
                TextEntry::make('decided_at')->label(__('admin.sales.fields.decided_at'))->date(),
                TextEntry::make('decidedBy.name')->label(__('admin.sales.fields.decided_by'))->placeholder(__('—')),
                TextEntry::make('decision_note')
                    ->label(__('admin.sales.fields.decision_note'))
                    ->visible(static fn (Quotation $record): bool => filled($record->decision_note)),
            ]);
    }

    private static function linesSection(): Section
    {
        return Section::make(__('admin.sales.fields.lines'))
            ->schema([
                RepeatableEntry::make('lines')
                    ->label('')
                    ->columns(12)
                    ->schema([
                        ImageEntry::make('productVariant.main_image')
                            ->label('')
                            ->getStateUsing(static fn (QuotationLine $record): ?string => $record->productVariant?->mainImageUrl())
                            ->circular()
                            ->imageSize(48)
                            ->columnSpan(1),
                        TextEntry::make('productVariant.sku')
                            ->label(__('admin.sales.fields.product_variant'))
                            ->state(static fn (QuotationLine $record): string => self::productLabel($record))
                            ->weight(FontWeight::SemiBold)
                            ->columnSpan(3),
                        TextEntry::make('quantity')
                            ->label(__('admin.sales.fields.quantity'))
                            ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state))
                            ->columnSpan(1),
                        TextEntry::make('unit.name')
                            ->label(__('admin.sales.fields.unit'))
                            ->placeholder(__('—'))
                            ->columnSpan(1),
                        TextEntry::make('unit_price')->label(__('admin.sales.fields.unit_price'))->money()->columnSpan(2),
                        TextEntry::make('tax_amount')->label(__('Line tax'))->money()->columnSpan(2),
                        TextEntry::make('line_total')
                            ->label(__('admin.sales.fields.line_total'))
                            ->money()
                            ->weight(FontWeight::Bold)
                            ->columnSpan(2),
                        TextEntry::make('description')
                            ->label(__('admin.sales.fields.description'))
                            ->html()
                            ->columnSpanFull()
                            ->visible(static fn (QuotationLine $record): bool => filled($record->description)),
                        self::pricingDetailsSection(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function pricingDetailsSection(): Section
    {
        return Section::make(__('Pricing details'))
            ->columnSpanFull()
            ->collapsible()
            ->collapsed(static fn (QuotationLine $record): bool => ! self::isBelowFloor($record))
            ->schema([
                TextEntry::make('resolved_price_source')
                    ->label(__('admin.sales.fields.resolved_price_source'))
                    ->badge()
                    ->formatStateUsing(static fn (?ResolvedPriceSource $state): ?string => $state?->label())
                    ->placeholder(__('Legacy / unknown'))
                    ->belowContent(static fn (QuotationLine $record): ?string => self::priceSourceHelp($record)),
                TextEntry::make('resolvedPriceTier.name')
                    ->label(__('Pricing tier'))
                    ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.pricing_tier'))
                    ->placeholder(__('—')),
                TextEntry::make('list_price_minor')
                    ->label(__('List price at quotation time'))
                    ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.list_price_snapshot'))
                    ->state(static fn (QuotationLine $record): ?float => $record->list_price_minor === null ? null : $record->list_price_minor / 100)
                    ->money()
                    ->placeholder(__('—')),
                TextEntry::make('floor_price_minor')
                    ->label(__('Minimum allowed price'))
                    ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.sales.hints.floor_snapshot'))
                    ->state(static fn (QuotationLine $record): ?float => $record->floor_price_minor === null ? null : $record->floor_price_minor / 100)
                    ->money()
                    ->placeholder(__('—')),
                TextEntry::make('unit_price')->label(__('Quoted unit price'))->money(),
                TextEntry::make('floor_override_status')
                    ->label(__('Override'))
                    ->state(static fn (QuotationLine $record): string => self::floorOverrideLabel($record))
                    ->badge()
                    ->color(static fn (QuotationLine $record): string => self::isBelowFloor($record) ? 'warning' : 'gray')
                    ->belowContent(static fn (QuotationLine $record): ?string => self::floorOverrideHelp($record)),
            ]);
    }

    private static function responseHistorySection(): Section
    {
        return Section::make(__('Response history'))
            ->visible(static fn (Quotation $record): bool => $record->responses->isNotEmpty())
            ->schema([
                RepeatableEntry::make('responses')
                    ->label('')
                    ->schema([
                        TextEntry::make('response_summary')
                            ->hiddenLabel()
                            ->state(static fn (QuotationResponse $record): string => $record->responded_at->translatedFormat('M j, Y').' · '.$record->response_type->label())
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('recordedBy.name')
                            ->hiddenLabel()
                            ->state(static fn (QuotationResponse $record): ?string => $record->recordedBy?->name !== null ? "Recorded by {$record->recordedBy->name}" : null)
                            ->visible(static fn (QuotationResponse $record): bool => $record->recordedBy !== null)
                            ->size(TextSize::Small)
                            ->color('gray'),
                        TextEntry::make('note')
                            ->hiddenLabel()
                            ->visible(static fn (QuotationResponse $record): bool => filled($record->note))
                            ->color('gray'),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function commercialSummarySection(): Section
    {
        return Section::make(__('Commercial summary'))
            ->schema([
                TextEntry::make('subtotal')->label(__('admin.sales.fields.subtotal'))->money(),
                TextEntry::make('tax_total')->label(__('Total tax'))->money(),
                TextEntry::make('grand_total')
                    ->label(__('admin.sales.fields.grand_total'))
                    ->money()
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold)
                    ->color('success'),
            ]);
    }

    private static function productLabel(QuotationLine $record): string
    {
        $variant = $record->productVariant;

        if ($variant === null) {
            return '—';
        }

        $name = $variant->product?->name;

        return $name !== null ? "{$name} ({$variant->sku})" : (string) $variant->sku;
    }

    private static function isBelowFloor(QuotationLine $record): bool
    {
        $floorPriceMinor = $record->priceProvenanceAttributes()['floor_price_minor'];

        if ($floorPriceMinor === null) {
            return false;
        }

        return (float) $record->unit_price < ($floorPriceMinor / 100);
    }

    private static function priceSourceHelp(QuotationLine $record): ?string
    {
        $source = $record->priceProvenanceAttributes()['resolved_price_source'];

        if (! $source instanceof ResolvedPriceSource) {
            return null;
        }

        return (string) __('admin.sales.quotation_view.price_source_help.'.$source->value);
    }

    private static function floorOverrideLabel(QuotationLine $record): string
    {
        if ($record->priceProvenanceAttributes()['floor_price_minor'] === null) {
            return 'Not applicable';
        }

        return self::isBelowFloor($record) ? 'Approved exception' : 'Not required';
    }

    private static function floorOverrideHelp(QuotationLine $record): ?string
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
