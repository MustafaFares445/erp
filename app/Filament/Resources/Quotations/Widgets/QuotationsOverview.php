<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Widgets;

use App\Enums\SalesPermission;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Services\Settings\CurrencyCatalogService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

final class QuotationsOverview extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return (bool) (auth()->user()?->can(SalesPermission::QuotationView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $openValue = (float) Quotation::query()->open()->sum('grand_total');
        $awaitingDecision = Quotation::query()->awaitingDecision()->count();
        $acceptedNotConverted = Quotation::query()->acceptedNotConverted()->count();
        $expiringSoon = Quotation::query()->expiringSoon()->count();
        $currency = app(CurrencyCatalogService::class)->defaultCode();

        return [
            Stat::make(__('Open quotation value'), self::formatMoney($openValue, $currency))
                ->description(__('Non-final quotations, current default currency'))
                ->url(QuotationResource::getUrl('index', ['tab' => 'open'])),
            Stat::make(__('Awaiting customer decision'), $awaitingDecision)
                ->description(__('Sent, no decision recorded yet'))
                ->url(QuotationResource::getUrl('index', ['tab' => 'awaiting_decision'])),
            Stat::make(__('Accepted, not converted'), $acceptedNotConverted)
                ->description(__('Needs conversion to an order'))
                ->url(QuotationResource::getUrl('index', ['tab' => 'accepted'])),
            Stat::make(__('Expiring soon'), $expiringSoon)
                ->description(__('Active, expiring within 7 days'))
                ->url(QuotationResource::getUrl('index', ['tab' => 'expiring_soon'])),
        ];
    }

    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = Number::currency($amount, $currency);

        return $formatted === false ? "{$currency} {$amount}" : $formatted;
    }
}
