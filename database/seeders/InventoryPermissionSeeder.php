<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InventoryPermission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class InventoryPermissionSeeder extends Seeder
{
    /**
     * Seed the `inventory.*` permission catalogue. Idempotent: running this
     * seeder repeatedly creates each permission at most once.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (InventoryPermission::values() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('System Admin', 'web')
            ->givePermissionTo(InventoryPermission::values());

        Role::findOrCreate('Warehouse Manager', 'web')
            ->syncPermissions($this->warehouseManagerPermissions());

        $administrator = User::query()->where('email', 'admin@ierp.com')->first();

        if ($administrator instanceof User) {
            $administrator->syncPermissions(InventoryPermission::values());
        }
    }

    /** @return list<string> */
    private function warehouseManagerPermissions(): array
    {
        return [
            InventoryPermission::WarehouseView->value,
            InventoryPermission::WarehouseManage->value,
            InventoryPermission::ReplenishmentPolicyView->value,
            InventoryPermission::ReplenishmentPolicyManage->value,
            InventoryPermission::InboundAllocate->value,
            InventoryPermission::StockView->value,
            InventoryPermission::MovementView->value,
            InventoryPermission::ReceiptView->value,
            InventoryPermission::ReceiptCreate->value,
            InventoryPermission::ReceiptConfirm->value,
            InventoryPermission::DeliveryView->value,
            InventoryPermission::DeliveryCreate->value,
            InventoryPermission::DeliveryConfirm->value,
            InventoryPermission::TransferView->value,
            InventoryPermission::TransferCreate->value,
            InventoryPermission::TransferConfirm->value,
            InventoryPermission::ReservationView->value,
            InventoryPermission::ReservationRelease->value,
            InventoryPermission::AdjustmentView->value,
            InventoryPermission::AdjustmentCreate->value,
            InventoryPermission::AdjustmentConfirm->value,
            InventoryPermission::ConditionChangeView->value,
            InventoryPermission::ConditionChangeCreate->value,
            InventoryPermission::ConditionChangePost->value,
            InventoryPermission::ConditionChangeCancel->value,
            InventoryPermission::ReturnView->value,
            InventoryPermission::ReturnCreate->value,
            InventoryPermission::ReturnInspect->value,
            InventoryPermission::ReturnPost->value,
            InventoryPermission::ReturnCancel->value,
            InventoryPermission::CorrectionView->value,
            InventoryPermission::CorrectionCreate->value,
            InventoryPermission::CorrectionPost->value,
            InventoryPermission::CorrectionCancel->value,
            InventoryPermission::CountView->value,
            InventoryPermission::CountOpen->value,
            InventoryPermission::CountRecord->value,
            InventoryPermission::CountConfirm->value,
            InventoryPermission::CatalogView->value,
            InventoryPermission::ProductView->value,
            InventoryPermission::ProductManage->value,
            InventoryPermission::PackageView->value,
            InventoryPermission::PackageManage->value,
            InventoryPermission::ReportView->value,
            InventoryPermission::Export->value,
            InventoryPermission::AlertView->value,
            InventoryPermission::ShipmentView->value,
            InventoryPermission::ShipmentConfirm->value,
        ];
    }
}
