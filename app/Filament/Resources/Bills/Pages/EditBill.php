<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use Filament\Resources\Pages\EditRecord;

final class EditBill extends EditRecord
{
    protected static string $resource = BillResource::class;
}
