<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\EquipmentLoanStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\UserType;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyRecoveryStatus;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use App\Services\Support\EquipmentLoanService;
use App\Services\Support\ExternalRepairService;
use App\Services\Support\WarrantyClaimService;
use App\Services\Support\WarrantyRecoveryService;
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

        $previousLoaner = config('support.loaner_equipment_enabled');
        $previousExternalRepair = config('support.external_repair_enabled');

        config(['support.loaner_equipment_enabled' => true, 'support.external_repair_enabled' => true]);

        try {
            $operator = $this->operator();

            $this->seedWarrantyRecovery($context, $operator, $repair);
            $this->seedLoan($context, $operator, $original, $repair);
            $this->seedExternalRepair($context, $operator, $repair);

            $this->note('Seeded the loaner, supplier repair, and outstanding supplier-warranty recovery scenario.');
        } finally {
            config([
                'support.loaner_equipment_enabled' => $previousLoaner,
                'support.external_repair_enabled' => $previousExternalRepair,
            ]);
        }
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

    private function seedWarrantyRecovery(DemoContext $context, User $operator, MaintenanceRecord $repair): void
    {
        $supplier = Supplier::query()->where('code', 'DEMO-SUP-005')->first()
            ?? Supplier::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        $claims = app(WarrantyClaimService::class);
        $recovery = app(WarrantyRecoveryService::class);

        if ($repair->diagnosed_at === null) {
            $context->at('2026-10-03 12:10:00');
            $repair = $claims->recordDiagnosis($repair->refresh(), [
                'diagnosis_summary' => 'Failed calibration confirmed excessive bearing wear and speed loss under load.',
                'root_cause' => 'Premature bearing wear consistent with a supplier-covered component defect.',
                'failure_category' => WarrantyFailureCategory::ManufacturingDefect->value,
            ], $operator);
        }

        if ($repair->coverage_decision !== WarrantyClaimDecision::ThirdPartyWarranty) {
            $context->at('2026-10-03 12:20:00');
            $repair = $claims->decideCoverage($repair->refresh(), [
                'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty->value,
                'coverage_source' => WarrantyCoverageSource::SupplierWarranty->value,
                'coverage_reason' => 'The failed handpiece component is covered by the supplier warranty.',
                'customer_coverage_explanation' => null,
                'coverage_lines' => [[
                    'category' => WarrantyLineCategory::ThirdParty->value,
                    'description' => 'Supplier repair of the failed handpiece bearing assembly',
                    'amount_minor' => 85000,
                ]],
            ], $operator);
        }

        $claim = $repair->warrantyRecoveryClaim()->first();

        if ($claim === null) {
            $context->at('2026-10-03 12:30:00');
            $claim = $recovery->create($repair->refresh(), [
                'supplier_id' => $supplier->getKey(),
                'currency' => app(CurrencyCatalogService::class)->defaultCode(),
                'claimed_amount_minor' => 85000,
                'external_reference' => 'WR-DEMO-0001',
                'notes' => 'Supplier warranty recovery for the failed calibration follow-up repair; customer responsibility remains zero.',
            ], $operator);
        }

        if ($claim->status === WarrantyRecoveryStatus::Draft) {
            $context->at('2026-10-03 12:40:00');
            $claim = $recovery->submit($claim, $operator, 'WR-DEMO-0001');
        }

        if ($claim->status === WarrantyRecoveryStatus::Submitted) {
            $context->at('2026-10-03 12:50:00');
            $recovery->approve($claim, 85000, $operator);
        }
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
