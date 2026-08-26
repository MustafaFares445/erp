<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountsReceivable\Pages;

use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use Filament\Resources\Pages\ListRecords;

final class ListAccountsReceivable extends ListRecords
{
    protected static string $resource = AccountsReceivableResource::class;
}
