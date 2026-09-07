<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('timeline')
                ->label('Timeline')
                ->icon('heroicon-o-clock')
                ->url(fn (): string => CustomerResource::getUrl('timeline', ['record' => $this->getRecord()])),
            EditAction::make(),
        ];
    }
}
