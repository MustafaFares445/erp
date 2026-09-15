<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Pages;

use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\SupplierConfirmationService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;

final class ManageSupplierConfirmations extends ManageRecords
{
    use InteractsWithPurchasingServices;

    protected static string $resource = SupplierConfirmationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(function (array $data): SupplierConfirmation {
                $actor = self::purchasingActor();
                if (! $actor instanceof User) {
                    throw new Halt;
                }

                $orderId = self::integerFrom($data['purchase_order_id'] ?? null);
                $order = PurchaseOrder::query()->findOrFail($orderId);

                return self::runPurchasingOperation(
                    fn (): SupplierConfirmation => app(SupplierConfirmationService::class)->recordPurchaseOrder(
                        $actor,
                        $order,
                        self::nullableStringFrom($data['notes'] ?? null),
                    ),
                    'admin.purchasing.notifications.confirmation_recorded',
                );
            }),
        ];
    }
}
