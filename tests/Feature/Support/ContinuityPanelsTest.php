<?php

declare(strict_types=1);

use App\Enums\EquipmentLoanStatus;
use App\Enums\ExternalRepairStatus;
use App\Enums\MaintenanceKind;
use App\Enums\SerializedCustodyType;
use App\Enums\StockCondition;
use App\Enums\WarrantyRecoveryOutcome;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ExternalRepairRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\LoanRelationManager;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\EquipmentLoanService;
use App\Services\Support\ExternalRepairEvidenceService;
use App\Services\Support\ExternalRepairService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ContinuityFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ContinuityFixtures::seedPermissions();
    Storage::fake('local');
    config(['support.loaner_equipment_enabled' => true, 'support.external_repair_enabled' => true]);
});

function loanPanel(User $user, MaintenanceRecord $record)
{
    return Livewire::actingAs($user)->test(LoanRelationManager::class, ['ownerRecord' => $record, 'pageClass' => ViewMaintenanceRequest::class]);
}

function rmaPanel(User $user, MaintenanceRecord $record)
{
    return Livewire::actingAs($user)->test(ExternalRepairRelationManager::class, ['ownerRecord' => $record, 'pageClass' => ViewMaintenanceRequest::class]);
}

it('shows the loaner section only on corrective requests while the loaner flag is on', function (): void {
    [, , $record] = ContinuityFixtures::repairScenario();

    expect(LoanRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeTrue()
        ->and(LoanRelationManager::getTitle($record, ViewMaintenanceRequest::class))->toBe('Temporary Replacement Equipment');

    $record->update(['maintenance_kind' => MaintenanceKind::Installation]);
    expect(LoanRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeFalse();

    $record->update(['maintenance_kind' => MaintenanceKind::Corrective]);
    config(['support.loaner_equipment_enabled' => false]);
    expect(LoanRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeFalse();
});

it('reserves, issues, and records the return of a loaner from the panel', function (): void {
    [, $original, $record] = ContinuityFixtures::repairScenario();
    $loaner = ContinuityFixtures::loanerFor($original);
    $operator = ContinuityFixtures::operator();
    $warehouse = ContinuityFixtures::warehouse();

    loanPanel($operator, $record)
        ->callAction(TestAction::make('reserveLoaner')->table(), ['loaner_id' => $loaner->id, 'expected_return_at' => now()->addDays(5)->toDateTimeString(), 'notes' => 'Five day repair'])
        ->assertHasNoFormErrors();

    $loan = EquipmentLoan::query()->sole();

    expect($loan->status)->toBe(EquipmentLoanStatus::Reserved);

    loanPanel($operator, $record)
        ->assertSee($loaner->serial_number)
        ->assertSee('Reserved')
        ->assertTableActionVisible('issueLoaner', $loan)
        ->assertTableActionHidden('recordReturn', $loan)
        ->callTableAction('issueLoaner', $loan, ['expected_return_at' => now()->addDays(5)->toDateTimeString()]);

    expect($loan->fresh()->status)->toBe(EquipmentLoanStatus::Issued)
        ->and($loaner->fresh()->custody_type)->toBe(SerializedCustodyType::Customer);

    loanPanel($operator, $record)
        ->assertTableActionHidden('issueLoaner', $loan)
        ->assertTableActionHidden('cancelLoan', $loan)
        ->callTableAction('recordReturn', $loan, ['warehouse_id' => $warehouse->id, 'condition_in' => StockCondition::Damaged->value, 'notes' => 'Cracked housing']);

    expect($loan->fresh()->status)->toBe(EquipmentLoanStatus::Returned)
        ->and($loan->fresh()->condition_in)->toBe(StockCondition::Damaged);

    loanPanel($operator, $record)->assertSee('Returned')->assertSee('Damaged');
});

it('cancels a reservation and explains a domain failure in a notification', function (): void {
    [, $original, $record] = ContinuityFixtures::repairScenario();
    $loaner = ContinuityFixtures::loanerFor($original);
    $operator = ContinuityFixtures::operator();
    $loan = app(EquipmentLoanService::class)->reserve($record, $loaner, $operator);

    // No expected return date yet: the service refuses, the panel notifies instead of failing.
    loanPanel($operator, $record)
        ->callTableAction('issueLoaner', $loan, ['expected_return_at' => now()->subDay()->toDateTimeString()])
        ->assertNotified('Unable to update the loan');

    loanPanel($operator, $record)->callTableAction('cancelLoan', $loan, ['reason' => 'Customer collected']);

    expect($loan->fresh()->status)->toBe(EquipmentLoanStatus::Cancelled);

    $loaner->forceFill(['stock_condition' => StockCondition::Damaged])->save();
    loanPanel($operator, $record)
        ->callAction(TestAction::make('reserveLoaner')->table(), ['loaner_id' => $loaner->id])
        ->assertHasFormErrors(['loaner_id']);
});

it('keeps stock-moving loan actions away from users without the Inventory permission', function (): void {
    [, $original, $record] = ContinuityFixtures::repairScenario();
    $loan = app(EquipmentLoanService::class)->reserve($record, ContinuityFixtures::loanerFor($original), ContinuityFixtures::operator());
    $supportOnly = ContinuityFixtures::supportOnly();

    loanPanel($supportOnly, $record)
        ->assertTableActionHidden('issueLoaner', $loan)
        ->assertTableActionVisible('cancelLoan', $loan)
        ->assertActionVisible(TestAction::make('reserveLoaner')->table());

    $issued = EquipmentLoan::factory()->issued()->create();

    loanPanel($supportOnly, $issued->maintenanceRecord)->assertTableActionHidden('recordReturn', $issued);
});

it('hides loaner actions from read-only reviewers', function (): void {
    [, $original, $record] = ContinuityFixtures::repairScenario();
    $loan = app(EquipmentLoanService::class)->reserve($record, ContinuityFixtures::loanerFor($original), ContinuityFixtures::operator());
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    loanPanel($reviewer, $record)
        ->assertSee($loan->loanerUnit->serial_number)
        ->assertActionHidden(TestAction::make('reserveLoaner')->table())
        ->assertTableActionHidden('issueLoaner', $loan)
        ->assertTableActionHidden('cancelLoan', $loan);
});

it('shows an overdue issued loan with its warning', function (): void {
    $loan = EquipmentLoan::factory()->issued()->create();
    $loan->update(['expected_return_at' => now()->subDay()]);

    loanPanel(ContinuityFixtures::operator(), $loan->maintenanceRecord)->assertSee('Overdue');
});

it('shows the supplier repair section while the RMA flag is on', function (): void {
    [, , $record] = ContinuityFixtures::repairScenario();

    expect(ExternalRepairRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeTrue()
        ->and(ExternalRepairRelationManager::getTitle($record, ViewMaintenanceRequest::class))->toBe('Supplier Repair (RMA)');

    config(['support.external_repair_enabled' => false]);

    expect(ExternalRepairRelationManager::canViewForRecord($record, ViewMaintenanceRequest::class))->toBeFalse();
});

it('drives a supplier repair through every step from the panel', function (): void {
    [, $unit, $record] = ContinuityFixtures::repairScenario();
    $operator = ContinuityFixtures::operator();
    $supplier = Supplier::factory()->create(['name' => 'Dental OEM Service']);
    $warehouse = ContinuityFixtures::warehouse();

    rmaPanel($operator, $record)
        ->callAction(TestAction::make('requestRepair')->table(), ['supplier_id' => $supplier->id, 'rma_number' => 'RMA-9', 'reason' => 'Bearing', 'estimated_return_on' => now()->addDays(9)->toDateString()])
        ->assertHasNoFormErrors();

    $repair = MaintenanceExternalRepair::query()->sole();

    rmaPanel($operator, $record)
        ->assertSee('Dental OEM Service')
        ->assertSee('RMA-9')
        ->assertTableActionVisible('approveRepair', $repair)
        ->assertTableActionHidden('shipToSupplier', $repair)
        ->callTableAction('approveRepair', $repair, ['rma_number' => 'RMA-10']);

    expect($repair->fresh()->rma_number)->toBe('RMA-10');

    // A unit still at the customer cannot be shipped; the panel reports it.
    rmaPanel($operator, $record)->callTableAction('shipToSupplier', $repair, ['outbound_reference' => 'AWB-1'])->assertNotified('Unable to update the supplier repair');

    ContinuityFixtures::receiveIntoWarehouse($unit);
    rmaPanel($operator, $record)->callTableAction('shipToSupplier', $repair, ['outbound_reference' => 'AWB-1']);

    expect($repair->fresh()->status)->toBe(ExternalRepairStatus::ShippedToSupplier)
        ->and($unit->fresh()->custody_type)->toBe(SerializedCustodyType::Supplier);

    rmaPanel($operator, $record)->callTableAction('receivedBySupplier', $repair, ['supplier_reference' => 'SUP-2']);
    rmaPanel($operator, $record)->callTableAction('startRepair', $repair, ['diagnosis' => 'Worn bearing', 'estimated_return_on' => now()->addDays(5)->toDateString()]);
    rmaPanel($operator, $record)->callTableAction('markRepaired', $repair, ['resolution' => 'Bearing replaced']);

    expect($repair->fresh()->status)->toBe(ExternalRepairStatus::Repaired);

    rmaPanel($operator, $record)
        ->assertSee('Worn bearing')
        ->assertSee('Bearing replaced')
        ->callTableAction('returnToCompany', $repair, ['warehouse_id' => $warehouse->id, 'condition' => StockCondition::Saleable->value, 'inbound_reference' => 'IN-1']);

    expect($repair->fresh()->status)->toBe(ExternalRepairStatus::ReturnedToCompany)
        ->and($unit->fresh()->custody_type)->toBe(SerializedCustodyType::Warehouse);

    foreach (['approveRepair', 'shipToSupplier', 'returnToCompany', 'cancelRepair', 'markRepaired'] as $action) {
        rmaPanel($operator, $record)->assertTableActionHidden($action, $repair);
    }
});

it('takes the replacement path and links the unit that arrived at the customer', function (): void {
    [, $original, $record] = ContinuityFixtures::repairScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);
    $repair = $service->approve($service->request($record, Supplier::factory()->create(), $operator), $operator);
    ContinuityFixtures::receiveIntoWarehouse($original);
    $repair = $service->startRepair($service->markReceivedBySupplier($service->ship($repair, $operator), $operator), $operator);

    rmaPanel($operator, $record)->callTableAction('approveReplacement', $repair, ['resolution' => 'Unrepairable']);

    $replacement = ContinuityFixtures::warehouseUnit(ProductVariant::query()->findOrFail($original->product_variant_id));
    $replacement->forceFill(['custody_type' => SerializedCustodyType::Customer, 'custody_reference_id' => $record->customer_id, 'warehouse_id' => null])->save();
    $notYet = ContinuityFixtures::warehouseUnit(ProductVariant::query()->findOrFail($original->product_variant_id));

    foreach ([$notYet, $original] as $invalid) {
        rmaPanel($operator, $record)
            ->callTableAction('receiveReplacement', $repair, ['replacement_id' => $invalid->id])
            ->assertHasTableActionErrors(['replacement_id']);
    }

    rmaPanel($operator, $record)->callTableAction('receiveReplacement', $repair, ['replacement_id' => $replacement->id]);

    expect($repair->fresh()->replacement_serialized_inventory_unit_id)->toBe($replacement->id);

    rmaPanel($operator, $record)->assertSee($replacement->serial_number);
});

it('cancels a requested repair, links a recovery claim and records its outcome', function (): void {
    [, , $record] = ContinuityFixtures::repairScenario();
    $operator = ContinuityFixtures::operator();
    $repair = app(ExternalRepairService::class)->request($record, Supplier::factory()->create(), $operator);
    $claim = WarrantyRecoveryClaim::factory()->create(['maintenance_record_id' => $record->id, 'external_reference' => 'CLM-7']);
    WarrantyRecoveryClaim::factory()->create();

    rmaPanel($operator, $record)
        ->assertTableActionVisible('linkRecoveryClaim', $repair)
        ->assertTableActionHidden('recoveryOutcome', $repair)
        ->callTableAction('linkRecoveryClaim', $repair, ['claim_id' => $claim->id]);

    expect($repair->fresh()->warranty_recovery_claim_id)->toBe($claim->id);

    rmaPanel($operator, $record)
        ->assertTableActionHidden('linkRecoveryClaim', $repair)
        ->callTableAction('recoveryOutcome', $repair, ['outcome' => WarrantyRecoveryOutcome::CreditNote->value]);

    expect($claim->fresh()->recovery_outcome)->toBe(WarrantyRecoveryOutcome::CreditNote);

    rmaPanel($operator, $record)->assertSee('Credit note');

    rmaPanel($operator, $record)->callTableAction('cancelRepair', $repair, ['reason' => 'Supplier refused the claim']);

    expect($repair->fresh()->status)->toBe(ExternalRepairStatus::Cancelled)
        ->and($claim->fresh()->recovery_outcome)->toBe(WarrantyRecoveryOutcome::CreditNote);
});

it('keeps custody-moving RMA steps from users without the Inventory permission and hides actions from reviewers', function (): void {
    [, , $record] = ContinuityFixtures::repairScenario();
    $supportOnly = ContinuityFixtures::supportOnly();
    $repair = app(ExternalRepairService::class)->approve(app(ExternalRepairService::class)->request($record, Supplier::factory()->create(), $supportOnly), $supportOnly);

    rmaPanel($supportOnly, $record)
        ->assertTableActionHidden('shipToSupplier', $repair)
        ->assertTableActionVisible('cancelRepair', $repair);

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $panel = rmaPanel($reviewer, $record);
    foreach (['cancelRepair', 'shipToSupplier', 'linkRecoveryClaim', 'uploadRmaDocument'] as $action) {
        $panel->assertTableActionHidden($action, $repair);
    }
    $panel->assertTableActionVisible('viewEvidence', $repair)->assertActionHidden(TestAction::make('requestRepair')->table());
});

it('uploads RMA documents, supplier reports and shipping documents and shows them after a reload', function (): void {
    [, , $record] = ContinuityFixtures::repairScenario();
    $operator = ContinuityFixtures::operator();
    $repair = app(ExternalRepairService::class)->request($record, Supplier::factory()->create(), $operator);

    foreach ([
        'uploadRmaDocument' => MaintenanceExternalRepair::MEDIA_RMA,
        'uploadSupplierReport' => MaintenanceExternalRepair::MEDIA_SUPPLIER_REPORTS,
        'uploadShippingDocument' => MaintenanceExternalRepair::MEDIA_SHIPPING,
    ] as $action => $collection) {
        rmaPanel($operator, $record)->callTableAction($action, $repair, ['files' => [UploadedFile::fake()->image($collection.'.jpg')]]);

        expect($repair->fresh()->getMedia($collection))->toHaveCount(1);
    }

    rmaPanel($operator, $record)
        ->mountTableAction('viewEvidence', $repair)
        ->assertMountedActionModalSee('RMA documents')
        ->assertMountedActionModalSee('Supplier reports')
        ->assertMountedActionModalSee('Shipping documents')
        ->assertMountedActionModalSeeHtml('/admin/external-repairs/'.$repair->id.'/media/');

    expect(ExternalRepairEvidenceService::Collections)->toHaveCount(3);
});

it('shows an overdue repair with its warning', function (): void {
    $repair = MaintenanceExternalRepair::factory()->create(['estimated_return_on' => now()->subDay()]);

    rmaPanel(ContinuityFixtures::operator(), $repair->maintenanceRecord)->assertSee('Overdue');
});
