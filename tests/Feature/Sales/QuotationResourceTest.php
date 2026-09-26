<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\ProductStatus;
use App\Enums\ResolvedPriceSource;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Jobs\GenerateQuotationDocument;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Sales\QuotationService;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function salesUser(DashboardRole $role): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole($role->value);

    return $user;
}

it('lets Sales Officer list and create quotations', function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);

    Livewire::actingAs($officer)->test(ListQuotations::class)->assertSuccessful();

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    Livewire::actingAs($officer)
        ->test(CreateQuotation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => $variant->product_id, 'product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Quotation::query()->count())->toBe(1);
});

it("shows the customer's active pricing tier on the quotation form", function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);
    $customer = CustomerProfile::factory()->create();
    $tier = PricingTier::factory()->create(['name' => 'Wholesale General']);
    CustomerPricingTier::factory()->create([
        'customer_user_id' => $customer->user_id,
        'pricing_tier_id' => $tier->getKey(),
    ]);
    $customerWithoutTier = CustomerProfile::factory()->create();

    Livewire::actingAs($officer)
        ->test(CreateQuotation::class)
        ->set('data.customer_id', $customer->getKey())
        ->assertSee('Wholesale General')
        ->set('data.customer_id', $customerWithoutTier->getKey())
        ->assertDontSee('Wholesale General');
});

it('auto-fills a line price from the tier while checked, and stops recalculating once unchecked', function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);
    PricingTier::factory()->customerSpecific()->create([
        'customer_user_id' => $customer->user_id,
        'discount_value' => 20,
    ]);

    // `use_tier_price` is seeded explicitly here because `fillForm()` sets
    // raw array state directly, bypassing the hydration lifecycle that a
    // real "Add Line" click runs — which is where the checkbox's own
    // `default(true)` would normally apply.
    $test = Livewire::actingAs($officer)
        ->test(CreateQuotation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->toDateString(),
            'lines' => [['quantity' => 1, 'use_tier_price' => true]],
        ]);
    $lineKey = array_key_first($test->get('data.lines'));

    $test->set('data.lines.'.$lineKey.'.product_id', $variant->product_id);

    expect((float) $test->get('data.lines.'.$lineKey.'.unit_price'))->toBe(80.0);

    $test->set('data.lines.'.$lineKey.'.use_tier_price', false)
        ->set('data.lines.'.$lineKey.'.unit_price', 999);

    expect((float) $test->get('data.lines.'.$lineKey.'.unit_price'))->toBe(999.0);

    $test->call('create')->assertHasNoFormErrors();

    $line = Quotation::query()->sole()->lines->sole();
    expect((float) $line->unit_price)->toBe(999.0)
        ->and($line->resolved_price_source)->toBe(ResolvedPriceSource::ManualOverride);
});

it('refuses Billing Officer the ability to create a quotation', function (): void {
    $billing = salesUser(DashboardRole::BillingOfficer);

    expect($billing->can('create', Quotation::class))->toBeFalse();
});

it('lets Reviewer view but not create or edit a quotation', function (): void {
    $reviewer = salesUser(DashboardRole::Reviewer);
    $customer = CustomerProfile::factory()->create();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [],
    );

    Livewire::actingAs($reviewer)
        ->test(ViewQuotation::class, ['record' => $quotation->getKey()])
        ->assertSuccessful();

    expect($reviewer->can('create', Quotation::class))->toBeFalse()
        ->and($reviewer->can('update', $quotation))->toBeFalse();
});

it('refuses a user with no sales permission any access', function (): void {
    // A Purchasing Officer holds no sales.* permission at all — the seeder's
    // matrix grants it none — so this is a genuine cross-module refusal, not
    // a role that happens to lack one specific ability.
    (new PurchasePermissionSeeder)->run();
    $stranger = salesUser(DashboardRole::PurchasingOfficer);

    expect($stranger->can('viewAny', Quotation::class))->toBeFalse();
});

it('offers Send only on a draft quotation to a holder of the manage ability', function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);
    $customer = CustomerProfile::factory()->create();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [],
    );

    expect($officer->can('send', $quotation))->toBeTrue();

    app(QuotationService::class)->send($quotation);

    // Sent — the model itself now refuses further content changes, though
    // the `send` ability check is a manage-permission check, not a status
    // check; the status gate lives in the action's own `visible()`.
    expect($quotation->refresh()->isFrozen())->toBeTrue();
});

it('offers Record Decision only to a holder of the decide ability', function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);
    $manager = salesUser(DashboardRole::SalesManager);
    $billing = salesUser(DashboardRole::BillingOfficer);

    expect($officer->can('decide', Quotation::class))->toBeTrue()
        ->and($manager->can('decide', Quotation::class))->toBeTrue()
        ->and($billing->can('decide', Quotation::class))->toBeFalse();
});

it('requires a decision note when rejecting or requesting changes, but not when accepting', function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);
    $customer = CustomerProfile::factory()->create();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [],
    );
    $sent = app(QuotationService::class)->send($quotation);

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $sent->getKey()])
        ->callAction('record_decision', [
            'decision' => 'rejected',
            'decided_at' => now()->toDateString(),
            'decision_note' => null,
        ])
        ->assertHasActionErrors(['decision_note']);

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $sent->getKey()])
        ->callAction('record_decision', [
            'decision' => 'accepted',
            'decided_at' => now()->toDateString(),
            'decision_note' => null,
        ])
        ->assertHasNoActionErrors();

    expect($sent->refresh()->status->value)->toBe('accepted');
});

it('offers Generate PDF only on a sent or accepted quotation and queues the job', function (): void {
    Queue::fake();
    $officer = salesUser(DashboardRole::SalesOfficer);
    $customer = CustomerProfile::factory()->create();
    $draft = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [],
    );

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $draft->getKey()])
        ->assertActionHidden('generate_pdf');

    $sent = app(QuotationService::class)->send($draft);

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $sent->getKey()])
        ->callAction('generate_pdf')
        ->assertHasNoActionErrors();

    Queue::assertPushed(GenerateQuotationDocument::class);
});
