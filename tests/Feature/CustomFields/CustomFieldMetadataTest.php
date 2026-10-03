<?php

declare(strict_types=1);

use App\Enums\CustomFieldDataType;
use App\Enums\CustomFieldEntityType;
use App\Enums\SystemPermission;
use App\Filament\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\CustomerProfile;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lead;
use App\Models\MaintenanceRecord;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxRecognitionEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CustomFields\CustomFieldService;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CrmPermissionSeeder)->run();
    (new SystemPermissionSeeder)->run();
});

function customFieldActor(): User
{
    $actor = User::factory()->create();
    $actor->assignRole('CRM Manager');

    return $actor;
}

it('stores customer metadata in typed value columns through the owning record policy', function (): void {
    $actor = customFieldActor();
    Auth::login($actor);
    $customer = CustomerProfile::factory()->create();

    $text = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => ' installation_zone ',
        'name' => 'Installation zone',
        'data_type' => CustomFieldDataType::Text,
        'is_required' => true,
    ]);
    $number = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => 'site_score',
        'name' => 'Site score',
        'data_type' => CustomFieldDataType::Number,
    ]);
    $date = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => 'commissioned_on',
        'name' => 'Commissioned on',
        'data_type' => CustomFieldDataType::Date,
    ]);
    $boolean = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => 'requires_induction',
        'name' => 'Requires induction',
        'data_type' => CustomFieldDataType::Boolean,
    ]);
    $select = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => 'service_tier',
        'name' => 'Service tier',
        'data_type' => CustomFieldDataType::Select,
        'options' => [' Gold ', 'Silver', 'Gold'],
    ]);

    app(CustomFieldService::class)->sync($actor, $customer, [
        $text->id => 'North warehouse',
        $number->id => '12.5',
        $date->id => '2026-10-03',
        $boolean->id => false,
        $select->id => 'Gold',
    ]);

    $values = $customer->customFieldValues()->with('definition')->get()->keyBy('custom_field_definition_id');
    $textValue = $values->get($text->id);
    $numberValue = $values->get($number->id);
    $dateValue = $values->get($date->id);
    $booleanValue = $values->get($boolean->id);
    $selectValue = $values->get($select->id);

    assert($textValue instanceof CustomFieldValue);
    assert($numberValue instanceof CustomFieldValue);
    assert($dateValue instanceof CustomFieldValue);
    assert($booleanValue instanceof CustomFieldValue);
    assert($selectValue instanceof CustomFieldValue);

    expect($text->refresh()->code)->toBe('installation_zone')
        ->and($select->refresh()->options)->toBe(['Gold', 'Silver'])
        ->and($values)->toHaveCount(5)
        ->and($textValue->value_text)->toBe('North warehouse')
        ->and($numberValue->value_number)->toBe('12.500000')
        ->and($dateValue->value_date?->toDateString())->toBe('2026-10-03')
        ->and($booleanValue->value_boolean)->toBeFalse()
        ->and($selectValue->value_text)->toBe('Gold');
});

it('enforces required and select invariants and prevents schema-shape changes after values exist', function (): void {
    $actor = customFieldActor();
    Auth::login($actor);
    $customer = CustomerProfile::factory()->create();
    $service = app(CustomFieldService::class);

    $required = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => 'required_note',
        'name' => 'Required note',
        'data_type' => CustomFieldDataType::Text,
        'is_required' => true,
    ]);
    $select = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => 'support_band',
        'name' => 'Support band',
        'data_type' => CustomFieldDataType::Select,
        'options' => ['A', 'B'],
    ]);

    expect(fn () => $service->sync($actor, $customer, [$select->id => 'A']))
        ->toThrow(DomainException::class, 'Required note');

    $service->sync($actor, $customer, [
        $required->id => 'Present',
        $select->id => 'A',
    ]);

    expect(fn () => $service->sync($actor, $customer, [
        $required->id => 'Present',
        $select->id => 'C',
    ]))->toThrow(DomainException::class, 'invalid option');

    $select->options = ['B'];
    expect(fn () => $select->save())->toThrow(DomainException::class, 'in use');

    $required->data_type = CustomFieldDataType::Number;
    expect(fn () => $required->save())->toThrow(DomainException::class, 'cannot change');
});

it('keeps the custom field allow-list away from accounting, payments and inventory ledger facts', function (): void {
    $allowed = collect(CustomFieldEntityType::cases())->map(fn (CustomFieldEntityType $type): string => $type->modelClass())->all();

    expect($allowed)->toEqualCanonicalizing([
        CustomerProfile::class,
        Supplier::class,
        Product::class,
        Lead::class,
        Ticket::class,
        MaintenanceRecord::class,
    ]);

    foreach ([
        Invoice::class,
        Payment::class,
        TaxRecognitionEntry::class,
        JournalEntry::class,
        InventoryStock::class,
        InventoryMovement::class,
    ] as $forbidden) {
        expect(in_array($forbidden, $allowed, true))->toBeFalse();
    }

    expect(fn () => app(CustomFieldService::class)->definitionsFor(new Invoice))
        ->toThrow(DomainException::class, 'not allowed');
});

it('requires the owning record update permission before custom values can change', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->assignRole('Reviewer');

    $customer = CustomerProfile::factory()->create();
    $definition = CustomFieldDefinition::query()->create([
        'entity_type' => CustomFieldEntityType::Customer,
        'code' => 'reviewer_blocked',
        'name' => 'Reviewer blocked',
        'data_type' => CustomFieldDataType::Text,
    ]);

    expect(fn () => app(CustomFieldService::class)->sync($reviewer, $customer, [
        $definition->id => 'must not write',
    ]))->toThrow(AuthorizationException::class)
        ->and($customer->customFieldValues()->count())->toBe(0);
});

it('grants definition management only to the system administrator role', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('System Admin');

    $reviewer = User::factory()->create();
    $reviewer->assignRole('Reviewer');

    expect($admin->can(SystemPermission::CustomFieldManage->value))->toBeTrue()
        ->and($reviewer->can(SystemPermission::CustomFieldManage->value))->toBeFalse();
});

it('does not use runtime schema mutation for custom metadata', function (): void {
    foreach ([
        'app/Services/CustomFields/CustomFieldService.php',
        'app/Models/CustomFieldDefinition.php',
        'app/Models/CustomFieldValue.php',
    ] as $path) {
        $source = file_get_contents(base_path($path));
        expect(is_string($source))->toBeTrue();
        assert(is_string($source));
        expect(str_contains($source, 'Schema::table'))->toBeFalse()
            ->and(str_contains($source, 'Schema::create'))->toBeFalse()
            ->and(str_contains($source, 'ALTER TABLE'))->toBeFalse();
    }
});

it('exposes custom fields only on the six approved business resources', function (): void {
    foreach ([
        CustomerResource::class,
        SupplierResource::class,
        ProductResource::class,
        LeadResource::class,
        TicketResource::class,
        MaintenanceRequestResource::class,
    ] as $resource) {
        expect($resource::getRelations())->toContain(CustomFieldsRelationManager::class);
    }
});
