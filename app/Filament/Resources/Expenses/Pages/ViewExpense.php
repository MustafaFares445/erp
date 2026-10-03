<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
