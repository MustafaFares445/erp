<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankStatements\Pages;

use App\Filament\Resources\BankStatements\BankStatementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBankStatements extends ListRecords
{
    protected static string $resource = BankStatementResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Import statement'))];
    }
}
