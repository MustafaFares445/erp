<?php

declare(strict_types=1);

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Enums\CrmPermission;
use App\Enums\InventoryPermission;
use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\SystemPermission;
use App\Filament\Resources\PricingTiers\Pages\ManagePricingTiers;
use App\Models\AuditLog;
use App\Models\BusinessConstraint;
use App\Models\ConstraintOverride;
use App\Models\PricingTier;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new CrmPermissionSeeder)->run();
    (new SystemPermissionSeeder)->run();
});

/**
 * A pricing manager who may author tiers but may not approve past a limit.
 * Deliberately not an admin: the admin bypass would hide exactly the
 * distinction these tests are about.
 */
function tierAuthor(): User
{
    $author = User::factory()->employee()->create();
    $author->givePermissionTo([
        InventoryPermission::PricingView->value,
        InventoryPermission::PricingManage->value,
        CrmPermission::PricingTierManage->value,
        CrmPermission::PricingTierDiscountManage->value,
    ]);

    return $author;
}

function tierApprover(): User
{
    $approver = tierAuthor();
    $approver->givePermissionTo(SystemPermission::ConstraintManage->value);

    return $approver->refresh();
}

/** @return array<string, mixed> */
function tierFormData(float $discount, array $overrides = []): array
{
    return [
        'name' => 'Wholesale',
        'tier_type' => PricingTierType::General->value,
        'discount_type' => PricingTierDiscountType::Percentage->value,
        'discount_value' => $discount,
        'customer_user_id' => null,
        'is_active' => true,
        ...$overrides,
    ];
}

it('creates a tier at the ceiling without ceremony', function (): void {
    Livewire::actingAs(tierAuthor())
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), tierFormData(25))
        ->assertHasNoActionErrors();

    expect((float) PricingTier::query()->sole()->discount_value)->toBe(25.0);
});

it('refuses a tier past the ceiling for an author who cannot approve', function (): void {
    Livewire::actingAs(tierAuthor())
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), tierFormData(40))
        ->assertNotified();

    expect(PricingTier::query()->count())->toBe(0);
});

it('lets an approver create a tier past the ceiling by giving a reason', function (): void {
    $approver = tierApprover();

    Livewire::actingAs($approver)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), tierFormData(40, [
            'discount_approval_reason' => 'Agreed with the customer for the annual renewal.',
        ]))
        ->assertHasNoActionErrors();

    $override = ConstraintOverride::query()->sole();

    expect((float) PricingTier::query()->sole()->discount_value)->toBe(40.0)
        ->and($override->constraint_key)->toBe(BusinessConstraintKey::MaxDiscountPercent)
        ->and((float) $override->attempted_value)->toBe(40.0)
        ->and((float) $override->limit_value)->toBe(25.0)
        ->and($override->approved_by)->toBe($approver->id)
        ->and($override->reason)->toBe('Agreed with the customer for the annual renewal.')
        ->and(AuditLog::query()->where('description', 'settings.constraint.overridden')->exists())->toBeTrue();
});

it('records no approval when the tier is within the ceiling', function (): void {
    Livewire::actingAs(tierApprover())
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), tierFormData(20))
        ->assertHasNoActionErrors();

    expect(ConstraintOverride::query()->count())->toBe(0);
});

it('bounds the field in the browser only when the ceiling blocks', function (): void {
    // Under the approval mode the field must let the value through, or the
    // operator is refused by the browser and never learns an approval exists.
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 25.0, BusinessConstraintEnforcement::RequireApproval)
        ->create();

    Livewire::actingAs(tierApprover())
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), tierFormData(40, [
            'discount_approval_reason' => 'Renewal.',
        ]))
        ->assertHasNoActionErrors();

    expect((float) PricingTier::query()->sole()->discount_value)->toBe(40.0);
});

it('rejects a value past a blocking ceiling before it reaches the service', function (): void {
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 25.0, BusinessConstraintEnforcement::Block)
        ->create();

    Livewire::actingAs(tierApprover())
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), tierFormData(40))
        ->assertHasActionErrors(['discount_value']);

    expect(PricingTier::query()->count())->toBe(0);
});

it('applies the same ceiling to the discount-only action as to the full form', function (): void {
    // These two disagreed before: the discount-only action carried no upper
    // bound at all, so it was the way around the rule the main form enforced.
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 25.0, BusinessConstraintEnforcement::Block)
        ->create();

    $author = User::factory()->employee()->create();
    $author->givePermissionTo([
        InventoryPermission::PricingView->value,
        CrmPermission::PricingTierDiscountManage->value,
    ]);

    $tier = PricingTier::factory()->create([
        'tier_type' => PricingTierType::General,
        'discount_type' => PricingTierDiscountType::Percentage,
        'discount_value' => 10,
        'is_active' => true,
    ]);

    Livewire::actingAs($author)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('editDiscount')->table($tier), [
            'discount_type' => PricingTierDiscountType::Percentage->value,
            'discount_value' => 40,
        ])
        ->assertHasActionErrors(['discount_value']);

    expect((float) $tier->refresh()->discount_value)->toBe(10.0);
});

it('does not re-demand a reason when editing a tier whose discount is already approved', function (): void {
    // Without this, renaming an approved 40% tier would be refused, and an
    // operator would learn to type a reason without reading it.
    $approver = tierApprover();

    Livewire::actingAs($approver)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), tierFormData(40, [
            'discount_approval_reason' => 'Agreed for the annual renewal.',
        ]))
        ->assertHasNoActionErrors();

    $tier = PricingTier::query()->sole();

    // The create path has no record to attach to yet, so the standing approval
    // is recorded against the tier on its first edit and honoured thereafter.
    Livewire::actingAs($approver)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('edit')->table($tier), tierFormData(40, [
            'name' => 'Wholesale renamed',
            'discount_approval_reason' => 'Agreed for the annual renewal.',
        ]))
        ->assertHasNoActionErrors();

    Livewire::actingAs($approver)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('edit')->table($tier), tierFormData(40, [
            'name' => 'Wholesale renamed twice',
        ]))
        ->assertHasNoActionErrors();

    expect($tier->refresh()->name)->toBe('Wholesale renamed twice')
        ->and((float) $tier->discount_value)->toBe(40.0)
        ->and(ConstraintOverride::query()->count())->toBe(2);
});

it('still demands a fresh approval when the approved discount is then raised', function (): void {
    $approver = tierApprover();

    Livewire::actingAs($approver)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), tierFormData(40, [
            'discount_approval_reason' => 'Agreed for the annual renewal.',
        ]))
        ->assertHasNoActionErrors();

    $tier = PricingTier::query()->sole();

    // A standing approval is for an amount. Raising the discount is a new
    // decision and needs its own.
    Livewire::actingAs($approver)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('edit')->table($tier), tierFormData(55, ['name' => 'Wholesale']))
        ->assertNotified();

    expect((float) $tier->refresh()->discount_value)->toBe(40.0);
});
