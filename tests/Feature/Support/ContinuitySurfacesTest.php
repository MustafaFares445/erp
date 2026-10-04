<?php

declare(strict_types=1);

use App\Enums\EquipmentLoanStatus;
use App\Enums\ExternalRepairStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\SerializedCustodyType;
use App\Enums\StockCondition;
use App\Enums\SupportPermission;
use App\Events\SupportContinuityMilestone;
use App\Filament\Resources\SupportEquipment\Pages\ViewSupportEquipment;
use App\Filament\Widgets\SupportOverdueLoaners;
use App\Filament\Widgets\SupportSupplierRepairs;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceExternalRepair;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Support\EquipmentLoanService;
use App\Services\Support\ExternalRepairEvidenceService;
use App\Services\Support\ExternalRepairService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ContinuityFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ContinuityFixtures::seedPermissions();
    Storage::fake('local');
    config(['support.loaner_equipment_enabled' => true, 'support.external_repair_enabled' => true]);
});

function continuityView(User $user, SerializedInventoryUnit $unit)
{
    return Livewire::actingAs($user)->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()]);
}

it("shows the loaner on the customer's unit and the loan history on the loaner unit in Equipment 360", function (): void {
    [, $original, $record] = ContinuityFixtures::repairScenario();
    $loaner = ContinuityFixtures::loanerFor($original);
    $operator = ContinuityFixtures::operator();
    $service = app(EquipmentLoanService::class);
    $loan = $service->issue($service->reserve($record, $loaner, $operator), $operator, now()->addDays(4));
    $customerLabel = $record->customer->company_name;

    continuityView($operator, $original)
        ->assertSeeHtml('data-testid="equipment-loaner-section"')
        ->assertSee('Temporary Replacement')
        ->assertSee($loaner->serial_number)
        ->assertSee('Issued')
        ->assertSeeHtml('data-testid="equipment-loan-as-original"');

    // The borrowing customer holds the loaner, so its own 360 shows the loan history.
    continuityView($operator, $loaner)
        ->assertSeeHtml('data-testid="equipment-loan-history"')
        ->assertSee('Loan history')
        ->assertSee($customerLabel)
        ->assertSee('Issued');

    expect($loan->fresh()->status)->toBe(EquipmentLoanStatus::Issued);
});

it('hides the loaner and RMA sections without data or while their flags are off', function (): void {
    [, $original] = ContinuityFixtures::repairScenario();
    $operator = ContinuityFixtures::operator();

    continuityView($operator, $original)
        ->assertDontSeeHtml('data-testid="equipment-loaner-section"')
        ->assertDontSeeHtml('data-testid="equipment-rma-section"');

    $loan = EquipmentLoan::factory()->issued()->create();
    $repair = MaintenanceExternalRepair::factory()->create();

    config(['support.loaner_equipment_enabled' => false, 'support.external_repair_enabled' => false]);

    continuityView($operator, $loan->originalUnit)->assertDontSeeHtml('data-testid="equipment-loaner-section"');
    continuityView($operator, $repair->serializedInventoryUnit)->assertDontSeeHtml('data-testid="equipment-rma-section"');
});

it('shows the supplier repair, its status and the replacement relationship in both directions', function (): void {
    [, $original, $record] = ContinuityFixtures::repairScenario();
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairService::class);
    $repair = $service->approve($service->request($record, Supplier::factory()->create(['name' => 'Dental OEM']), $operator, ['rma_number' => 'RMA-360']), $operator);

    continuityView($operator, $original)
        ->assertSeeHtml('data-testid="equipment-rma-section"')
        ->assertSee('Dental OEM')
        ->assertSee('RMA-360')
        ->assertSee('Approved')
        ->assertDontSeeHtml('data-testid="equipment-replaced-by"');

    $replacement = ContinuityFixtures::warehouseUnit(ProductVariant::query()->findOrFail($original->product_variant_id));
    $replacement->forceFill(['custody_type' => SerializedCustodyType::Customer, 'custody_reference_id' => $record->customer_id, 'warehouse_id' => null])->save();
    $repair->forceFill(['replacement_serialized_inventory_unit_id' => $replacement->id])->save();

    continuityView($operator, $original)->assertSee('Replaced by '.$replacement->serial_number);
    continuityView($operator, $replacement)
        ->assertSeeHtml('data-testid="equipment-replacement-for"')
        ->assertSee('Replacement for '.$original->serial_number);
});

it('lists issued loaners past their return date in the operational queue', function (): void {
    $overdue = EquipmentLoan::factory()->issued()->create();
    $overdue->update(['expected_return_at' => now()->subDays(2)]);

    $onTime = EquipmentLoan::factory()->issued()->create();
    $reserved = EquipmentLoan::factory()->create(['expected_return_at' => now()->subDay()]);
    $returned = EquipmentLoan::factory()->issued()->create();
    $returned->update(['expected_return_at' => now()->subDay(), 'status' => EquipmentLoanStatus::Returned]);

    Livewire::actingAs(ContinuityFixtures::operator())->test(SupportOverdueLoaners::class)
        ->assertSuccessful()
        ->assertSee('Overdue loaners')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$onTime, $reserved, $returned]);
});

it('lists open supplier repairs, with overdue ones highlighted', function (): void {
    $open = MaintenanceExternalRepair::factory()->create(['status' => ExternalRepairStatus::Repairing, 'estimated_return_on' => now()->subDay()]);
    $undated = MaintenanceExternalRepair::factory()->create();
    $done = MaintenanceExternalRepair::factory()->create(['status' => ExternalRepairStatus::ReturnedToCompany]);
    $cancelled = MaintenanceExternalRepair::factory()->create(['status' => ExternalRepairStatus::Cancelled]);

    Livewire::actingAs(ContinuityFixtures::operator())->test(SupportSupplierRepairs::class)
        ->assertSuccessful()
        ->assertSee('Waiting for supplier')
        ->assertCanSeeTableRecords([$open, $undated])
        ->assertCanNotSeeTableRecords([$done, $cancelled]);
});

it('gates the continuity queues on permission and feature flag', function (): void {
    $user = User::factory()->employee()->create();
    $this->actingAs($user);

    expect(SupportOverdueLoaners::canView())->toBeFalse()->and(SupportSupplierRepairs::canView())->toBeFalse();

    $user->givePermissionTo(SupportPermission::LoanView->value, SupportPermission::RmaView->value);

    expect(SupportOverdueLoaners::canView())->toBeTrue()->and(SupportSupplierRepairs::canView())->toBeTrue();

    config(['support.loaner_equipment_enabled' => false, 'support.external_repair_enabled' => false]);

    expect(SupportOverdueLoaners::canView())->toBeFalse()->and(SupportSupplierRepairs::canView())->toBeFalse();
});

/** @return array{0: array<string, list<string>>} deliveries per template as "class:id:channel" */
function continuityDeliveries(NotificationEventKey $key): array
{
    return NotificationDelivery::query()
        ->where('template_key', $key->value)
        ->orderBy('id')
        ->get()
        ->map(fn (NotificationDelivery $d): string => class_basename($d->notifiable_type).':'.$d->notifiable_id.':'.$d->channel->value)
        ->all();
}

it('notifies the customer and loan managers once about an overdue loaner and never repeats', function (): void {
    (new NotificationTemplateSeeder)->run();
    Notification::fake();
    [$customer, $original, $record] = ContinuityFixtures::repairScenario();
    $customerUser = User::factory()->customer()->create();
    $customer->update(['user_id' => $customerUser->id]);
    $operator = ContinuityFixtures::operator();
    $service = app(EquipmentLoanService::class);
    $loan = $service->issue($service->reserve($record, ContinuityFixtures::loanerFor($original), $operator), $operator, now()->addDay());
    $loan->update(['expected_return_at' => now()->subDay()]);

    $this->artisan('support:loaners:notify-overdue')->expectsOutputToContain('1 overdue loaner notification')->assertSuccessful();

    $rows = continuityDeliveries(NotificationEventKey::LoanerOverdue);

    expect($rows)->toContain('User:'.$customerUser->id.':mail', 'User:'.$operator->id.':mail', 'User:'.$operator->id.':database')
        ->and(count($rows))->toBe(count(array_unique($rows)))
        ->and($loan->fresh()->overdue_notified_at)->not->toBeNull()
        ->and(NotificationDelivery::query()->where('template_key', 'loaner.overdue')->first()->variables)->toMatchArray([
            'maintenance_reference' => '#'.$record->id,
            'loaner_serial' => $loan->loanerUnit->serial_number,
        ]);

    $before = NotificationDelivery::query()->count();
    $this->artisan('support:loaners:notify-overdue')->expectsOutputToContain('0 overdue loaner notification')->assertSuccessful();

    expect(NotificationDelivery::query()->count())->toBe($before);
});

it('skips the overdue sweep while the loaner flag is off and ignores loans that are not overdue', function (): void {
    (new NotificationTemplateSeeder)->run();
    $onTime = EquipmentLoan::factory()->issued()->create();

    $this->artisan('support:loaners:notify-overdue')->expectsOutputToContain('0 overdue')->assertSuccessful();

    config(['support.loaner_equipment_enabled' => false]);
    $onTime->update(['expected_return_at' => now()->subDay()]);

    $this->artisan('support:loaners:notify-overdue')->expectsOutputToContain('disabled')->assertSuccessful();

    expect($onTime->fresh()->overdue_notified_at)->toBeNull()
        ->and(NotificationDelivery::query()->count())->toBe(0);
});

it('notifies RMA managers of status changes, and inventory staff when equipment comes back', function (): void {
    (new NotificationTemplateSeeder)->run();
    Notification::fake();
    [, $unit, $record] = ContinuityFixtures::repairScenario();
    $operator = ContinuityFixtures::operator();
    $supportOnly = ContinuityFixtures::supportOnly();
    $warehouseOnly = User::factory()->admin()->create();
    $warehouseOnly->assignRole('Warehouse Manager');

    $service = app(ExternalRepairService::class);

    $repair = $service->request($record, Supplier::factory()->create(), $operator);
    $first = continuityDeliveries(NotificationEventKey::RmaStatusChanged);

    expect($first)->toHaveCount(4)->and(count(array_unique($first)))->toBe(4);

    $repair = $service->approve($repair, $operator);
    ContinuityFixtures::receiveIntoWarehouse($unit);
    $repair = $service->recordRepaired($service->startRepair($service->markReceivedBySupplier($service->ship($repair, $operator), $operator), $operator), $operator, 'Fixed');

    $changed = continuityDeliveries(NotificationEventKey::RmaStatusChanged);

    expect($changed)->toContain('User:'.$operator->id.':mail', 'User:'.$supportOnly->id.':mail')
        ->and($changed)->not->toContain('User:'.$warehouseOnly->id.':mail');

    $service->returnToCompany($repair, ContinuityFixtures::warehouse()->id, StockCondition::Saleable, $operator);

    $returned = continuityDeliveries(NotificationEventKey::EquipmentReturnedFromSupplier);

    expect($returned)->toContain('User:'.$warehouseOnly->id.':mail', 'User:'.$supportOnly->id.':database')
        ->and(NotificationDelivery::query()->where('template_key', 'rma.equipment_returned')->first()->variables)->toMatchArray([
            'rma_status' => 'Returned to company',
            'supplier_name' => $repair->supplier->name,
        ]);
});

it('respects the loaner and RMA flags in the notification listener', function (): void {
    (new NotificationTemplateSeeder)->run();
    $loan = EquipmentLoan::factory()->issued()->create();
    $repair = MaintenanceExternalRepair::factory()->create();

    config(['support.loaner_equipment_enabled' => false, 'support.external_repair_enabled' => false]);

    SupportContinuityMilestone::dispatch($loan->maintenanceRecord, NotificationEventKey::LoanerOverdue, $loan->id);
    SupportContinuityMilestone::dispatch($repair->maintenanceRecord, NotificationEventKey::RmaStatusChanged, $repair->id);

    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('ships english and arabic templates for every continuity event and uses the recipient language', function (): void {
    (new NotificationTemplateSeeder)->run();
    Notification::fake();

    foreach ([NotificationEventKey::LoanerOverdue, NotificationEventKey::RmaStatusChanged, NotificationEventKey::EquipmentReturnedFromSupplier] as $key) {
        foreach (['en', 'ar'] as $locale) {
            foreach ([NotificationChannel::Mail, NotificationChannel::Database] as $channel) {
                expect(NotificationTemplate::query()->where(['key' => $key->value, 'locale' => $locale, 'channel' => $channel->value, 'is_active' => true])->exists())
                    ->toBeTrue("missing {$locale}/{$channel->value} template for {$key->value}");
            }

            expect($key->label())->not->toStartWith('enums.')
                ->and(__('notification_templates.events.'.str_replace('.', '_', $key->value).'.name', [], $locale))->not->toStartWith('notification_templates.');
        }
    }

    $operator = ContinuityFixtures::operator();
    $operator->forceFill(['locale' => 'ar'])->save();
    $repair = MaintenanceExternalRepair::factory()->create();
    app(ExternalRepairService::class)->approve($repair, $operator);

    expect(NotificationDelivery::query()->where('template_key', 'rma.status_changed')->where('notifiable_id', $operator->id)->where('channel', 'mail')->firstOrFail()->locale)->toBe('ar');
});

it('streams RMA evidence only to authorized users through the private media routes', function (): void {
    $repair = MaintenanceExternalRepair::factory()->create();
    $other = MaintenanceExternalRepair::factory()->create();
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairEvidenceService::class);
    $path = UploadedFile::fake()->image('awb.jpg')->storeAs('rma-evidence', 'awb.jpg', 'local');
    $service->attach($repair, MaintenanceExternalRepair::MEDIA_SHIPPING, [$path], $operator);
    $media = $repair->fresh()->getFirstMedia(MaintenanceExternalRepair::MEDIA_SHIPPING);
    $parameters = ['repair' => $repair, 'media' => $media];

    $this->actingAs($operator)->get(route('admin.external-repairs.media.preview', $parameters))->assertOk()->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);
    $this->actingAs($operator)->get(route('admin.external-repairs.media.download', $parameters))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
    $this->actingAs($operator)->get(route('admin.external-repairs.media.preview', ['repair' => $other, 'media' => $media]))->assertNotFound();
    $this->actingAs(User::factory()->customer()->create())->get(route('admin.external-repairs.media.preview', $parameters))->assertForbidden();

    config(['support.external_repair_enabled' => false]);

    $this->actingAs($operator)->get(route('admin.external-repairs.media.download', $parameters))->assertForbidden();
});

it('validates RMA evidence uploads like every other evidence collection', function (): void {
    $repair = MaintenanceExternalRepair::factory()->create();
    $operator = ContinuityFixtures::operator();
    $service = app(ExternalRepairEvidenceService::class);

    Storage::disk('local')->put('rma-evidence/notes.txt', 'plain');
    Storage::disk('local')->put('secrets/x.pdf', 'x');
    Storage::disk('local')->put('rma-evidence/big.pdf', str_repeat('a', ExternalRepairEvidenceService::MaximumFileSizeInBytes + 1));

    foreach ([
        ['secrets/x.pdf', MaintenanceExternalRepair::MEDIA_RMA],
        ['rma-evidence/../secrets/x.pdf', MaintenanceExternalRepair::MEDIA_RMA],
        ['rma-evidence/missing.jpg', MaintenanceExternalRepair::MEDIA_RMA],
        ['rma-evidence/notes.txt', MaintenanceExternalRepair::MEDIA_RMA],
        ['rma-evidence/big.pdf', MaintenanceExternalRepair::MEDIA_RMA],
        [UploadedFile::fake()->image('ok.jpg')->storeAs('rma-evidence', 'ok.jpg', 'local'), 'calibration-evidence'],
    ] as [$path, $collection]) {
        expect(fn () => $service->attach($repair, $collection, [$path], $operator))->toThrow(ValidationException::class);
    }

    $good = UploadedFile::fake()->image('good.jpg')->storeAs('rma-evidence', 'good.jpg', 'local');

    expect($service->attach($repair, MaintenanceExternalRepair::MEDIA_RMA, [$good, '', 5], $operator))->toBe(1)
        ->and($service->counts($repair))->toBe([MaintenanceExternalRepair::MEDIA_RMA => 1, MaintenanceExternalRepair::MEDIA_SUPPLIER_REPORTS => 0, MaintenanceExternalRepair::MEDIA_SHIPPING => 0]);

    $media = $repair->fresh()->getFirstMedia(MaintenanceExternalRepair::MEDIA_RMA);

    expect(fn () => $service->remove(MaintenanceExternalRepair::factory()->create(), $media, $operator))->toThrow(ValidationException::class);

    $service->remove($repair, $media, $operator);

    expect($repair->fresh()->media)->toHaveCount(0);
});

it('keeps unit custody under Inventory: the Support services never assign custody columns', function (): void {
    $source = file_get_contents(base_path('app/Services/Support/EquipmentLoanService.php')).file_get_contents(base_path('app/Services/Support/ExternalRepairService.php'));

    expect(preg_match('/custody_(type|reference_type|reference_id).\s*=>/', $source))->toBe(0)
        ->and(SerializedCustodyType::Supplier->value)->toBe('supplier');
});
