<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\PaymentTerms\Pages\CreatePaymentTerm;
use App\Filament\Resources\PaymentTerms\Pages\EditPaymentTerm;
use App\Filament\Resources\PaymentTerms\Pages\ListPaymentTerms;
use App\Filament\Resources\SalesSettings\Pages\ManageSalesSettings;
use App\Models\ChartAccount;
use App\Models\PaymentTerm;
use App\Models\SalesSetting;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();

    $this->admin = User::factory()->admin()->create();
    $this->admin->assignRole(DashboardRole::SalesManager->value);
});

it('lets System Admin manage sales settings and refuses Sales Manager', function (): void {
    $systemAdmin = User::factory()->admin()->create();

    Livewire::actingAs($systemAdmin)
        ->test(ManageSalesSettings::class)
        ->assertSuccessful();

    // Sales Manager holds sales.setting.view, but not .manage — no page
    // access at all here since the resource requires viewAny.
    expect($this->admin->can('viewAny', SalesSetting::class))->toBeTrue()
        ->and($this->admin->can('create', SalesSetting::class))->toBeFalse();
});

it('creates and edits sales settings through the singleton page', function (): void {
    $systemAdmin = User::factory()->admin()->create();
    $receivable = ChartAccount::factory()->create();

    Livewire::actingAs($systemAdmin)
        ->test(ManageSalesSettings::class)
        ->callAction('create', [
            'default_tax_percent' => 5,
            'default_quotation_validity_days' => 15,
            'receivable_account_id' => $receivable->getKey(),
        ])
        ->assertHasNoActionErrors();

    $settings = SalesSetting::query()->sole();

    expect((float) $settings->default_tax_percent)->toBe(5.0)
        ->and($settings->default_quotation_validity_days)->toBe(15)
        ->and($settings->receivable_account_id)->toBe($receivable->getKey());
});

it('lists and creates payment terms, honouring the single-default invariant through the dashboard', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ListPaymentTerms::class)
        ->assertSuccessful();

    Livewire::actingAs($this->admin)
        ->test(CreatePaymentTerm::class)
        ->fillForm(['name' => 'Net 15', 'due_days' => 15, 'is_default' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::actingAs($this->admin)
        ->test(CreatePaymentTerm::class)
        ->fillForm(['name' => 'Net 30', 'due_days' => 30, 'is_default' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PaymentTerm::query()->where('is_default', true)->count())->toBe(1)
        ->and(PaymentTerm::query()->where('name', 'Net 15')->sole()->is_default)->toBeFalse();
});

it('edits a payment term through the dashboard, still honouring the single-default invariant', function (): void {
    $first = PaymentTerm::factory()->default()->create(['name' => 'Net 15']);
    $second = PaymentTerm::factory()->create(['name' => 'Net 30']);

    Livewire::actingAs($this->admin)
        ->test(EditPaymentTerm::class, ['record' => $second->getKey()])
        ->fillForm(['name' => 'Net 30', 'due_days' => 30, 'is_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue();
});
