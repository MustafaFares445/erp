<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductReferences\Pages;

use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageSupplierProductReferences extends ManageRecords
{
    protected static string $resource = SupplierProductReferenceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(SupplierProductReferenceResource::getUrl('calendar')), CreateAction::make()];
    }
}
