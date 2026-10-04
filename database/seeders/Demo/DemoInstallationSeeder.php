<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\InstallationCheckResult;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\ProductType;
use App\Enums\ServiceAppointmentStatus;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyStartTrigger;
use App\Models\EmployeeProfile;
use App\Models\EquipmentInstallation;
use App\Models\InventoryOperation;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\Shipment;
use App\Models\Unit;
use App\Models\User;
use App\Models\WarrantyPolicy;
use App\Services\Inventory\ProductVariantUomService;
use App\Services\Sales\SalesOrderService;
use App\Services\Support\EquipmentInstallationService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\ServiceAppointmentService;
use App\Services\Support\ServiceRecordService;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One complete, deterministic dental-laboratory equipment installation.
 *
 * A sintering furnace is received from its supplier, sold to a customer, delivered and
 * confirmed on arrival through the real Inventory/Sales/Logistics services; the customer
 * then gets an installation request, a field visit, a passed checklist, successful
 * commissioning and a signed acceptance, and the commissioning-trigger warranty activates.
 * Every step goes through the same domain services as the UI; re-running skips what exists.
 */
final class DemoInstallationSeeder extends DemoSeeder
{
    public const string Marker = '[DEMO-INSTALL]';

    public const string Sku = 'DEMO-P021-FURNACE';

    public const string PolicyCode = 'DEMO-COMMISSIONING-24M';

    protected function seed(DemoContext $context): void
    {
        $manager = $context->actor('support_manager');
        $technician = EmployeeProfile::query()->where('email', 'demo.support.agent1@ierp.test')->firstOrFail();
        $technicianUser = $technician->user;

        if (! $technicianUser instanceof User) {
            throw new LogicException('The demo field technician has no login.');
        }

        $variant = $this->seedCatalogue($context);
        $unit = $this->seedDelivery($context, $variant);
        $record = $this->seedInstallationRequest($context, $manager, $technician, $unit);

        $this->seedInstallationWork($context, $manager, $technicianUser, $record);

        $this->note('Seeded the dental furnace installation scenario (delivered, installed, commissioned, accepted).');
    }

    private function seedCatalogue(DemoContext $context): ProductVariant
    {
        $context->at('2026-09-14 09:00:00');
        $context->as('admin');

        $each = Unit::query()->where('code', 'EA')->firstOrFail();
        $category = ProductCategory::query()->updateOrCreate(
            ['name' => 'Dental Laboratory Equipment'],
            ['name_ar' => 'معدات مختبرات الأسنان', 'is_active' => true],
        );
        $policy = WarrantyPolicy::query()->updateOrCreate(['code' => self::PolicyCode], [
            'name' => 'Laboratory Equipment Warranty (from commissioning)',
            'duration_value' => 24,
            'duration_unit' => WarrantyDurationUnit::Months,
            'start_trigger' => WarrantyStartTrigger::Commissioning,
            'covers_parts' => true,
            'covers_labour' => true,
            'covers_travel' => true,
            'covers_consumables' => false,
            'covers_third_party' => false,
            'transferable' => false,
            'replacement_rule' => 'remaining_original_term',
            'is_active' => true,
        ]);
        $product = Product::query()->updateOrCreate(['name' => 'Dental Sintering Furnace'], [
            'name_ar' => 'فرن تلبيد أسنان',
            'description' => 'P021 — High-temperature zirconia sintering furnace (demo catalogue).',
            'category_id' => $category->getKey(),
            'product_type' => ProductType::Machine,
            'status' => 'active',
            'is_active' => true,
        ]);
        $product->addAllowedUnit($each);

        $variant = ProductVariant::query()->updateOrCreate(['sku' => self::Sku], [
            ...ProductType::Machine->trackingFlags(),
            'product_id' => $product->getKey(),
            'name' => 'Dental Sintering Furnace, 1600 C',
            'name_ar' => 'فرن تلبيد أسنان، 1600 درجة مئوية',
            'unit_id' => $each->getKey(),
            'cost_price' => 29000.0,
            'base_price' => 38500.0,
            'min_price' => 30800.0,
            'markup_percent' => 32.76,
            'warranty_policy_id' => $policy->getKey(),
            'status' => 'active',
            'is_active' => true,
        ]);

        app(ProductVariantUomService::class)->sync($variant, [[
            'unit_id' => $each->getKey(),
            'is_base' => true,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => true,
            'factor_to_base' => '1',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ]]);

        return $variant;
    }

    private function seedDelivery(DemoContext $context, ProductVariant $variant): SerializedInventoryUnit
    {
        $existing = SerializedInventoryUnit::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('custody_type', 'customer')
            ->orderBy('id')
            ->first();

        if ($existing instanceof SerializedInventoryUnit) {
            return $existing;
        }

        $kit = DemoSalesKit::make($context);
        $inventory = $kit->inventory;
        $customer = $kit->customer('C09');

        $context->at('2026-09-15 10:00:00');
        $operations = $context->as('operations');
        $inventory->receive(
            $operations,
            $inventory->warehouse('WH-MAIN'),
            [['variant' => $variant, 'quantity' => 1, 'tag' => 'FURNACE']],
            'DEMO-RCPT-FURNACE-001',
            $inventory->supplier('DEMO-SUP-005'),
            '[DEMO-INSTALL] Dental sintering furnace received from the supplier.',
        );

        $context->at('2026-09-16 09:30:00');
        $salesManager = $context->as('sales_manager');
        $orders = app(SalesOrderService::class);
        $order = $orders->createDraft($salesManager, [
            'customer_id' => $customer->getKey(),
            'payment_term_id' => $kit->term('Net 30')->getKey(),
            'notes' => '[DEMO-INSTALL] Dental sintering furnace with on-site installation and commissioning.',
        ], $kit->lines(['P021-FURNACE' => 1]));
        $order = $orders->confirm($salesManager, $order->refresh());

        $context->at('2026-09-16 14:00:00');
        $order = $orders->release($salesManager, $order->refresh());

        $context->at('2026-09-22 09:00:00');
        $delivery = $this->singleDelivery($kit->plan('operations', $order, ['P021-FURNACE' => 1]));
        $delivery = $kit->prepare('operations', $delivery);

        $context->at('2026-09-22 15:00:00');
        $kit->dispatch('operations', $delivery, 'TRK-DEMO-INST-0001');
        $context->at('2026-09-23 11:00:00');
        $kit->arrive('admin', $delivery, 'Delivered, uncrated and signed for by the laboratory manager.');

        return SerializedInventoryUnit::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('custody_type', 'customer')
            ->firstOrFail();
    }

    /** @param  iterable<int, InventoryOperation>  $deliveries */
    private function singleDelivery(iterable $deliveries): InventoryOperation
    {
        foreach ($deliveries as $delivery) {
            return $delivery;
        }

        throw new LogicException('The furnace order produced no delivery.');
    }

    private function seedInstallationRequest(DemoContext $context, User $manager, EmployeeProfile $technician, SerializedInventoryUnit $unit): MaintenanceRecord
    {
        $existing = MaintenanceRecord::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->where('maintenance_kind', MaintenanceKind::Installation->value)
            ->first();

        if ($existing instanceof MaintenanceRecord) {
            return $existing;
        }

        $context->at('2026-09-24 09:00:00');
        $context->as('support_manager');

        $record = app(MaintenanceRecordService::class)->createStandalone([
            'customer_id' => $unit->custody_reference_id,
            'serialized_inventory_unit_id' => $unit->getKey(),
            'maintenance_kind' => MaintenanceKind::Installation,
            'description' => self::Marker.' Install and commission the dental sintering furnace at the customer laboratory.',
        ], $manager);

        $task = app(ServiceRecordService::class)->create($record, [
            'title' => 'Furnace installation and commissioning visit',
            'description' => 'Uncrate, position, connect power and exhaust, run the functional test and train the operator.',
            'employee_id' => $technician->getKey(),
        ], $manager);

        app(ServiceAppointmentService::class)->createScheduled(
            $task,
            $technician,
            Carbon::parse('2026-09-29 09:00:00', config()->string('app.timezone')),
            Carbon::parse('2026-09-29 13:00:00', config()->string('app.timezone')),
            null,
            $manager,
            'Installation visit: confirm utilities are ready before the technician arrives.',
        );

        return $record;
    }

    private function seedInstallationWork(DemoContext $context, User $manager, User $technicianUser, MaintenanceRecord $record): void
    {
        $service = app(EquipmentInstallationService::class);
        $installation = EquipmentInstallation::query()->where('maintenance_record_id', $record->getKey())->first();

        if ($installation instanceof EquipmentInstallation) {
            $this->closeRequest($context, $manager, $record);

            return;
        }

        $appointment = ServiceAppointment::query()
            ->whereHas('serviceRecord', static fn ($query) => $query->where('maintenance_record_id', $record->getKey()))
            ->firstOrFail();
        $appointments = app(ServiceAppointmentService::class);

        if ($appointment->status === ServiceAppointmentStatus::Planned) {
            $context->at('2026-09-28 16:00:00');
            $appointments->dispatch($appointment, $manager);
        }

        if ($appointment->refresh()->status === ServiceAppointmentStatus::Dispatched) {
            $context->at('2026-09-29 08:30:00');
            $appointments->markEnRoute($appointment, $technicianUser);
        }

        if ($appointment->refresh()->status === ServiceAppointmentStatus::EnRoute) {
            $context->at('2026-09-29 09:05:00');
            $appointments->checkIn($appointment, $technicianUser);
        }

        $shipment = $service->eligibleShipments($record)->first();

        if (! $shipment instanceof Shipment) {
            throw new LogicException('The furnace delivery shipment was not found.');
        }

        $context->at('2026-09-29 09:10:00');
        $installation = $service->createForDeliveredEquipment($record, $manager, [
            'shipment_id' => $shipment->id,
            'installation_location' => 'Zirconia milling room, ground floor',
            'notes' => 'Dedicated 32 A circuit and extraction duct prepared by the customer.',
        ]);

        $measurements = [
            'unpack_inspection' => [InstallationCheckResult::Passed, null, null, 'No transport damage; muffle intact.'],
            'placement_levelling' => [InstallationCheckResult::Passed, '0.2', 'deg', 'Levelled on the steel bench.'],
            'utilities_connection' => [InstallationCheckResult::Passed, '230', 'V', 'Supply voltage within tolerance.'],
            'functional_test' => [InstallationCheckResult::Passed, '1600', 'C', 'Reached 1600 C and held for 10 minutes.'],
            'safety_check' => [InstallationCheckResult::Passed, null, null, 'Door interlock and over-temperature cut-out verified.'],
            'customer_training' => [InstallationCheckResult::Passed, null, null, 'Two operators trained on the sintering programs.'],
        ];

        $context->at('2026-09-29 11:30:00');
        foreach ($measurements as $key => [$result, $value, $unit, $notes]) {
            $service->recordCheck($installation, $key, $result, $technicianUser, $value, $unit, $notes);
        }

        $context->at('2026-09-29 12:00:00');
        $service->completeInstallation($installation->refresh(), $technicianUser);

        $context->at('2026-09-29 12:40:00');
        $service->completeCommissioning($installation->refresh(), $technicianUser);

        $context->at('2026-09-29 12:55:00');
        $appointments->complete($appointment->refresh(), $technicianUser, 'Eng. Khaled Mansour', null, null, 'Furnace installed, commissioned and accepted on site.');

        $context->at('2026-09-29 13:00:00');
        $service->acceptByCustomer($installation->refresh(), 'Eng. Khaled Mansour', $manager);

        $this->closeRequest($context, $manager, $record);
    }

    private function closeRequest(DemoContext $context, User $manager, MaintenanceRecord $record): void
    {
        $context->at('2026-09-30 09:00:00');
        $records = app(MaintenanceRecordService::class);
        $task = MaintenanceTask::query()->where('maintenance_record_id', $record->getKey())->firstOrFail();

        $tasks = app(ServiceRecordService::class);
        $path = [MaintenanceStatus::InProgress, MaintenanceStatus::QualityAssurance, MaintenanceStatus::Closed];

        // Starting the visit task already moves the request to In Progress, so each step is
        // applied only while it is still a legal next edge (re-runs skip what is done).
        foreach ($path as $status) {
            if ($task->refresh()->status->canTransitionTo($status)) {
                $tasks->transition($task, $status, $manager);
            }
        }

        foreach ($path as $status) {
            if ($record->refresh()->status->canTransitionTo($status)) {
                $records->transition($record, $status, $manager);
            }
        }
    }
}
