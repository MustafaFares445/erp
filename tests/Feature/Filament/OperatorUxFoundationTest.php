<?php

declare(strict_types=1);

use App\Enums\SystemPermission;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\SavedTableView;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\UiPreferences\UserUiPreferenceService;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds saved-view sharing permissions only into the system-admin system catalogue', function (): void {
    (new SystemPermissionSeeder)->run();

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toContain(
            SystemPermission::TableViewShare->value,
            SystemPermission::TableViewManagePublic->value,
        )
        ->and(Role::findByName('System Admin')->permissions->pluck('name')->all())
        ->toContain(
            SystemPermission::TableViewShare->value,
            SystemPermission::TableViewManagePublic->value,
        )
        ->and(Role::findByName('Reviewer')->permissions->pluck('name')->all())
        ->not->toContain(
            SystemPermission::TableViewShare->value,
            SystemPermission::TableViewManagePublic->value,
        );
});

it('persists and replaces presentation preferences per user and stable key', function (): void {
    $user = User::factory()->create();
    $service = app(UserUiPreferenceService::class);

    $service->put($user, 'table', 'sales.invoices', ['columns' => ['invoice_number']]);

    expect($service->get($user, 'table', 'sales.invoices'))
        ->toBe(['columns' => ['invoice_number']]);

    $service->put($user, 'table', 'sales.invoices', ['columns' => ['customer']]);

    expect(UserUiPreference::query()->count())->toBe(1)
        ->and($service->get($user, 'table', 'sales.invoices'))
        ->toBe(['columns' => ['customer']]);

    $service->forget($user, 'table', 'sales.invoices');

    expect($service->get($user, 'table', 'sales.invoices'))->toBeNull();
});

it('saves current invoice table state without weakening preset tabs', function (): void {
    (new SystemPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('System Admin');

    $component = Livewire::actingAs($actor)->test(ListInvoices::class);
    $component->assertSuccessful();
    $component->set('activeTab', 'overdue')->assertSuccessful();
    $component->set('tableSearch', 'INV-42')->assertSuccessful();
    $component->callAction('saveSavedTableView', data: [
        'name' => 'Overdue follow-up',
        'is_public' => true,
    ])->assertHasNoActionErrors();

    $view = SavedTableView::query()->sole();

    expect($view->user_id)->toBe($actor->id)
        ->and($view->page_key)->toBe('sales.invoices')
        ->and($view->is_public)->toBeTrue()
        ->and($view->state['preset_tab'])->toBe('overdue')
        ->and($view->state['tableSearch'])->toBe('INV-42');
});

it('filters the unified settings registry by live search text', function (): void {
    $actor = User::factory()->admin()->create();

    $page = Livewire::actingAs($actor)->test(Settings::class);
    $allLabels = collect($page->instance()->cards())->pluck('label');

    expect($allLabels)->toContain('Document Templates');

    $page->set('search', 'document');

    expect(collect($page->instance()->cards())->pluck('label'))
        ->toContain('Document Templates')
        ->not->toContain('Inventory Settings');
});

it('configures command-k global search and exposes invoice identifiers', function (): void {
    expect(Filament::getPanel('admin')->getGlobalSearchKeyBindings())
        ->toBe(['command+k', 'ctrl+k'])
        ->and(InvoiceResource::getGloballySearchableAttributes())
        ->toContain('invoice_number', 'customer.company_name');
});
