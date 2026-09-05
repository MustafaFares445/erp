<?php

declare(strict_types=1);

namespace App\Filament\Resources\Taxes\Pages;

use App\Filament\Resources\Taxes\TaxResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The flat, unfiltered register (AC-11's trace to any figure's cause).
 * {@see ViewTaxRegister} is the period report built from these same rows.
 */
final class ListTaxes extends ListRecords
{
    protected static string $resource = TaxResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_register')
                ->label('Open tax register report')
                ->url(fn (): string => ViewTaxRegister::getUrl()),
        ];
    }
}
