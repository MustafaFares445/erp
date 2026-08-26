<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments\Pages;

use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use Filament\Resources\Pages\EditRecord;

final class EditSupplierPayment extends EditRecord
{
    protected static string $resource = SupplierPaymentResource::class;
}
