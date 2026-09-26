<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Widgets;

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
        $confirmedThisMonth = CreditNote::query()->confirmedThisMonth()->count();
        $totalCredited = (float) CreditNote::query()->where('status', CreditNoteStatus::Confirmed->value)->sum('grand_total');
        $currency = app(CurrencyCatalogService::class)->defaultCode();

        return [
            Stat::make('Draft / pending', $draft)
                ->description('Not yet confirmed')
                ->url(CreditNoteResource::getUrl('index', ['activeTab' => 'draft'])),
            Stat::make('Issued this month', $confirmedThisMonth)
                ->url(CreditNoteResource::getUrl('index', ['activeTab' => 'confirmed_this_month'])),
            Stat::make('Total credited', self::formatMoney($totalCredited, $currency))
                ->description('Confirmed credit notes')
                ->url(CreditNoteResource::getUrl('index', ['activeTab' => 'confirmed'])),
        ];
    }

    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = Number::currency($amount, $currency);

        return $formatted === false ? "{$currency} {$amount}" : $formatted;
    }
}
