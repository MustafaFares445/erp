<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\CustomerPricingTierPolicy;
use App\Policies\CustomerProfilePolicy;
use App\Policies\PriceFloorOverridePolicy;
use App\Policies\PricingTierPolicy;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies the fixed CRM role matrix to customer pricing tier and floor actions', function (): void {
    (new CrmPermissionSeeder)->run();
    $tier = PricingTier::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $crmManager = User::factory()->admin()->create();
    $crmManager->assignRole('CRM Manager');

    $pricingManager = User::factory()->admin()->create();
    $pricingManager->assignRole('Pricing Manager');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    expect($systemAdmin->can(InventoryPermission::PriceFloorApprove->value))->toBeTrue()
        ->and($crmManager->can('update', $customer))->toBeTrue()
        ->and($crmManager->can('delete', $tier))->toBeTrue()
        ->and($pricingManager->can('updateDiscount', $tier))->toBeTrue()
        ->and($pricingManager->can('delete', $tier))->toBeFalse()
        ->and($reviewer->can('view', $tier))->toBeTrue()
        ->and($reviewer->can('update', $customer))->toBeFalse();
});

it('covers immutable CRM policy operations and inventory permission fallbacks', function (): void {
    (new CrmPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
    $inventoryActor = User::factory()->employee()->create();
    $inventoryActor->givePermissionTo([
        InventoryPermission::PricingView->value,
        InventoryPermission::PricingManage->value,
    ]);
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $unauthorized = User::factory()->customer()->create();
    $auditLog = AuditLog::factory()->create();
    $auditPolicy = app(AuditLogPolicy::class);
    $assignmentPolicy = app(CustomerPricingTierPolicy::class);
    $floorPolicy = app(PriceFloorOverridePolicy::class);
    $tierPolicy = app(PricingTierPolicy::class);
    $customerPolicy = app(CustomerProfilePolicy::class);

    expect($assignmentPolicy->viewAny($reviewer))->toBeTrue()
        ->and($assignmentPolicy->viewAny($inventoryActor))->toBeTrue()
        ->and($assignmentPolicy->viewAny($unauthorized))->toBeFalse()
        ->and($assignmentPolicy->update())->toBeFalse()
        ->and($assignmentPolicy->delete())->toBeFalse()
        ->and($assignmentPolicy->restore())->toBeFalse()
        ->and($assignmentPolicy->forceDelete())->toBeFalse()
        ->and($floorPolicy->viewAny($inventoryActor))->toBeTrue()
        ->and($floorPolicy->create())->toBeFalse()
        ->and($floorPolicy->update())->toBeFalse()
        ->and($floorPolicy->delete())->toBeFalse()
        ->and($floorPolicy->restore())->toBeFalse()
        ->and($floorPolicy->forceDelete())->toBeFalse()
        ->and($tierPolicy->create($inventoryActor))->toBeTrue()
        ->and($tierPolicy->manageLinks($inventoryActor))->toBeTrue()
        ->and($tierPolicy->forceDelete())->toBeFalse()
        ->and($customerPolicy->deleteAny($reviewer))->toBeFalse()
        ->and($customerPolicy->restoreAny($reviewer))->toBeFalse()
        ->and($auditPolicy->view($reviewer, new AuditLog))->toBeFalse()
        ->and($auditPolicy->create())->toBeFalse()
        ->and($auditPolicy->update())->toBeFalse()
        ->and($auditPolicy->delete())->toBeFalse()
        ->and($auditPolicy->restore())->toBeFalse()
        ->and($auditPolicy->forceDelete())->toBeFalse()
        ->and($reviewer->can('view', $auditLog))->toBeTrue();
});
