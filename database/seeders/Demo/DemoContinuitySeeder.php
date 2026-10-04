<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\EquipmentLoanStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\UserType;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Support\EquipmentLoanService;
use App\Services\Support\ExternalRepairService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Deterministic service-continuity scenario for the failed handpiece
 * calibration: the customer gets a loaner handpiece from the warehouse while
 * the original goes through a supplier repair (RMA). Every step goes through
 * the same Support and Inventory services as the UI; re-running skips what
 * exists. The loaner and external-repair flags are enabled for the duration of
 * the run only; turn them on in `.env` to see the sections afterwards.
 */
final class DemoContinuitySeeder extends DemoSeeder
{
    public const string OperatorEmail = 'demo.support.continuity@ierp.test';

    public const string LoanerReceipt = 'DEMO-RCPT-LOANER-001';

    public const string RmaNumber = 'RMA-DEMO-0001';

    protected function seed(DemoContext $context): void
    {
        $failed = EquipmentCalibration::query()
            ->whereNotNull('follow_up_maintenance_record_id')
            ->orderBy('id')
            ->first();

        if (! $failed instanceof EquipmentCalibration) {
            $this->note('Skipping the loaner / supplier repair scenario (no failed calibration follow-up repair in the demo data).');

            return;
        }

        $original = SerializedInventoryUnit::query()->with('productVariant')->findOrFail($failed->serialized_inventory_unit_id);
        $repair = MaintenanceRecord::query()->findOrFail($failed->follow_up_maintenance_record_id);

        config(['support.loaner_equipment_enabled' => true, 'support.external_repair_enabled' => true]);

        $operator = $this->operator();

        $this->seedLoan($context, $operator, $original, $repair);
        $this->seedExternalRepair($context, $operator, $repair);

        $this->note('Seeded the loaner and supplier repair scenario (issued loaner handpiece; approved supplier repair).');
    }

    private function operator(): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::OperatorEmail],
            ['name' => 'Demo Support Continuity Lead', 'password' => Hash::make(DemoContext::Password), 'user_type' => UserType::Admin],
        );

        foreach (['Support Manager', 'Warehouse Manager'] as $role) {
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        return $user;
    }

    private function seedLoan(DemoContext $context, User $operator, SerializedInventoryUnit $original, MaintenanceRecord $repair): void
    {
        if (EquipmentLoan::query()->where('maintenance_record_id', $repair->getKey())->exists()) {
            return;
        }

        $kit = DemoSalesKit::make($context);

        $context->at('2026-10-03 11:00:00');
        $kit->inventory->receive(
            $context->as('operations'),
            $kit->inventory->warehouse('WH-MAIN'),
            [['variant' => $this->variant($original), 'quantity' => 1, 'tag' => 'LOANER']],
            self::LoanerReceipt,
            $kit->inventory->supplier('DEMO-SUP-005'),
            '[DEMO-LOAN] Loaner handpiece received for customer service continuity.',
        );

        $loaner = SerializedInventoryUnit::query()
            ->where('product_variant_id', $original->product_variant_id)
            ->where('custody_type', SerializedCustodyType::Warehouse->value)
            ->whereKeyNot($original->getKey())
            ->orderByDesc('id')
            ->firstOrFail();

        $service = app(EquipmentLoanService::class);

        $context->at('2026-10-03 11:30:00');
        $context->as('support_manager');

        $loan = $service->reserve($repair, $loaner, $operator, Carbon::parse('2026-10-09 17:00:00', config()->string('app.timezone')), 'Loaner handpiece while the customer unit is repaired.');

        $context->at('2026-10-03 12:00:00');
        $service->issue($loan, $operator, Carbon::parse('2026-10-09 17:00:00', config()->string('app.timezone')));
    }

    private function seedExternalRepair(DemoContext $context, User $operator, MaintenanceRecord $repair): void
    {
        if (MaintenanceExternalRepair::query()->where('maintenance_record_id', $repair->getKey())->exists()) {
            return;
        }

        $supplier = Supplier::query()->where('code', 'DEMO-SUP-005')->first() ?? Supplier::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $service = app(ExternalRepairService::class);

        $context->at('2026-10-03 13:00:00');
        $external = $service->request($repair, $supplier, $operator, [
            'reason' => 'Bearing replacement cannot be done in-house; send the handpiece to the manufacturer service centre.',
            'estimated_return_on' => Carbon::parse('2026-10-20', config()->string('app.timezone')),
        ]);

        $context->at('2026-10-03 14:00:00');
        $service->approve($external, $operator, self::RmaNumber);
    }

    private function variant(SerializedInventoryUnit $unit): ProductVariant
    {
        return ProductVariant::query()->findOrFail($unit->product_variant_id);
    }

    /** @return list<string> the statuses the demo leaves behind, for the verification report */
    public static function expectedLoanStatuses(): array
    {
        return [EquipmentLoanStatus::Issued->value];
    }
}
