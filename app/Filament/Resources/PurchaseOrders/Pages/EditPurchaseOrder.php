<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Policies\PurchaseOrderPolicy;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Reachable only for a draft: {@see PurchaseOrderPolicy::update()} refuses an
 * order that has left draft regardless of permission, so the route existing is
 * harmless.
 *
 * Header fields are written by Filament directly, because editing a draft is not
 * a committing operation — nothing has been promised to the supplier yet. The
 * service's own status guard is the backstop if that assumption is ever wrong.
 */
final class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            PurchaseOrderActions::submit(),
            DeleteAction::make(),
        ];
    }
}
