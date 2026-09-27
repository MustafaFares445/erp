<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Widgets;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Enums\SalesPermission;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use App\Services\Settings\CurrencyCatalogService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

final class CreditNotesOverview extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return (bool) (auth()->user()?->can(SalesPermission::CreditNoteView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $draft = CreditNote::query()->where('status', CreditNoteStatus::Draft->value)->count();
        $confirmedThisMonth = (float) CreditNote::query()->confirmedThisMonth()->sum('grand_total');
        $salesReturns = CreditNote::query()
            ->where('status', CreditNoteStatus::Confirmed->value)
            ->where('reason_category', CreditNoteReason::SalesReturn->value);
        $salesReturnTotal = (float) (clone $salesReturns)->sum('grand_total');
        $salesReturnCount = (clone $salesReturns)->count();
        $adjustments = CreditNote::query()
            ->where('status', CreditNoteStatus::Confirmed->value)
            ->whereIn('reason_category', [
                CreditNoteReason::PricingAdjustment->value,
                CreditNoteReason::TaxAdjustment->value,
                CreditNoteReason::CommercialDiscount->value,
            ]);
        $adjustmentTotal = (float) (clone $adjustments)->sum('grand_total');
        $adjustmentCount = (clone $adjustments)->count();
        $reversed = CreditNote::query()->where('status', CreditNoteStatus::Reversed->value)->count();
        $currency = app(CurrencyCatalogService::class)->defaultCode();

        return [
            Stat::make(__('admin.sales.credit_note_tabs.draft'), $draft)
                ->description(__('admin.sales.credit_note_ui.draft_description'))
                ->url(CreditNoteResource::getUrl('index', ['activeTab' => 'draft'])),
            Stat::make(__('admin.sales.credit_note_tabs.confirmed_this_month'), self::formatMoney($confirmedThisMonth, $currency))
                ->url(CreditNoteResource::getUrl('index', ['activeTab' => 'confirmed_this_month'])),
            Stat::make(__('admin.sales.credit_note_ui.sales_return_credits'), self::formatMoney($salesReturnTotal, $currency))
                ->description(__('admin.sales.credit_note_ui.confirmed_note_count', ['count' => $salesReturnCount]))
                ->url(CreditNoteResource::getUrl('index', ['activeTab' => 'sales_returns'])),
            Stat::make(__('admin.sales.credit_note_ui.adjustments'), self::formatMoney($adjustmentTotal, $currency))
                ->description(__('admin.sales.credit_note_ui.confirmed_note_count', ['count' => $adjustmentCount]))
                ->url(CreditNoteResource::getUrl('index', ['activeTab' => 'confirmed'])),
            Stat::make(__('admin.sales.credit_note_tabs.reversed_cancelled'), $reversed)
                ->url(CreditNoteResource::getUrl('index', ['activeTab' => 'reversed_cancelled'])),
        ];
    }

    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = Number::currency($amount, $currency);

        return $formatted === false ? "{$currency} {$amount}" : $formatted;
    }
}
