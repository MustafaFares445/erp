<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\SavedTableView;
use App\Models\TableViewPreference;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function coverage64User(): User
{
    (new PurchasePermissionSeeder)->run();

    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::PurchasingManager->value);

    return $user;
}

/** @param array<string,mixed> $state */
function coverage64View(User $user, string $name, array $state = []): SavedTableView
{
    return SavedTableView::query()->create([
        'user_id' => $user->id,
        'page_key' => 'purchasing.purchase-orders',
        'name' => $name,
        'is_public' => false,
        'state' => $state,
        'state_version' => ListPurchaseOrders::SAVED_TABLE_VIEW_STATE_VERSION,
    ]);
}

it('boots the default saved table view and applies its state', function (): void {
    $user = coverage64User();
    $view = coverage64View($user, 'Default coverage view', [
        'preset_tab' => 'all',
        'tableSearch' => 'COVERAGE-64',
        'tableGrouping' => 'status',
        'tableSort' => 'id',
        'tableRecordsPerPage' => 25,
        'tableFilters' => [],
        'tableColumnSearches' => [],
    ]);

    TableViewPreference::query()->create([
        'user_id' => $user->id,
        'page_key' => 'purchasing.purchase-orders',
        'view_type' => 'saved',
        'view_key' => (string) $view->id,
        'is_default' => true,
        'is_favorite' => true,
    ]);

    $component = Livewire::actingAs($user)->test(ListPurchaseOrders::class);
    $page = $component->instance();
    $defaultId = new ReflectionMethod(ListPurchaseOrders::class, 'defaultSavedTableViewId')->invoke($page);
    expect($defaultId)->toBe($view->id);

    $page->savedTableViewInitialized = false;
    $page->activeSavedTableView = null;
    $page->bootedInteractsWithTable();

    expect($page->tableSearch)->toBe('COVERAGE-64')
        ->and($page->tableGrouping)->toBe('status')
        ->and($page->tableSort)->toBe('id')
        ->and($page->tableRecordsPerPage)->toBe(25);
});

it('covers saved-view load replace default and delete action closures', function (): void {
    $user = coverage64User();
    $this->actingAs($user);

    $load = coverage64View($user, 'Load coverage', [
        'preset_tab' => 'all',
        'tableSearch' => 'LOAD-64',
    ]);
    $replace = coverage64View($user, 'Replace coverage');
    $delete = coverage64View($user, 'Delete coverage');

    $component = Livewire::actingAs($user)->test(ListPurchaseOrders::class);
    $page = $component->instance();

    $loadAction = new ReflectionMethod(ListPurchaseOrders::class, 'loadSavedTableViewAction')->invoke($page);
    $loadAction->getActionFunction()(['view_id' => (string) $load->id]);

    expect($page->activeSavedTableView)->toBe($load->id)
        ->and($page->tableSearch)->toBe('LOAD-64');

    $page->tableSearch = 'REPLACED-64';
    $replaceAction = new ReflectionMethod(ListPurchaseOrders::class, 'replaceSavedTableViewAction')->invoke($page);
    $replaceAction->getActionFunction()(['view_id' => (string) $replace->id]);

    expect($replace->refresh()->state['tableSearch'])->toBe('REPLACED-64')
        ->and($replace->state_version)->toBe(ListPurchaseOrders::SAVED_TABLE_VIEW_STATE_VERSION)
        ->and($page->activeSavedTableView)->toBe($replace->id);

    TableViewPreference::query()->create([
        'user_id' => $user->id,
        'page_key' => 'purchasing.purchase-orders',
        'view_type' => 'saved',
        'view_key' => (string) $load->id,
        'is_default' => true,
        'is_favorite' => true,
    ]);

    $defaultAction = new ReflectionMethod(ListPurchaseOrders::class, 'setDefaultSavedTableViewAction')->invoke($page);
    $defaultAction->getActionFunction()(['view_id' => (string) $replace->id]);

    expect(TableViewPreference::query()
        ->where('view_key', (string) $load->id)
        ->value('is_default'))->toBeFalse()
        ->and(TableViewPreference::query()
            ->where('view_key', (string) $replace->id)
            ->value('is_default'))->toBeTrue();

    TableViewPreference::query()->updateOrCreate([
        'user_id' => $user->id,
        'page_key' => 'purchasing.purchase-orders',
        'view_type' => 'saved',
        'view_key' => (string) $delete->id,
    ], [
        'is_default' => false,
        'is_favorite' => true,
    ]);

    $page->activeSavedTableView = $delete->id;
    $deleteAction = new ReflectionMethod(ListPurchaseOrders::class, 'deleteSavedTableViewAction')->invoke($page);
    $deleteAction->getActionFunction()(['view_id' => (string) $delete->id]);

    expect($page->activeSavedTableView)->toBeNull()
        ->and(SavedTableView::query()->whereKey($delete->id)->exists())->toBeFalse()
        ->and(TableViewPreference::query()->where('view_key', (string) $delete->id)->exists())->toBeFalse();
});

it('covers saved-view state normalization and helper fallbacks', function (): void {
    $user = coverage64User();
    $this->actingAs($user);

    $component = Livewire::actingAs($user)->test(ListPurchaseOrders::class);
    $page = $component->instance();

    $filterKeys = array_keys($page->getTable()->getFilters());
    $stateFilters = $filterKeys === [] ? [] : [
        $filterKeys[0] => ['value' => 'coverage'],
        'not-a-real-filter' => ['value' => 'drop'],
    ];

    $view = coverage64View($user, 'Normalization coverage', [
        'preset_tab' => 'not-a-real-tab',
        'tableFilters' => $stateFilters,
        'tableGrouping' => 'status',
        'tableSearch' => ['not-a-string'],
        'tableColumnSearches' => 'not-an-array',
        'tableSort' => str_repeat('x', 300),
        'tableRecordsPerPage' => '50',
    ]);

    new ReflectionMethod(ListPurchaseOrders::class, 'applySavedTableView')->invoke($page, $view);

    expect($page->activeTab)->toBe((string) $page->getDefaultActiveTab())
        ->and($page->tableGrouping)->toBe('status')
        ->and($page->tableSearch)->toBe('')
        ->and($page->tableColumnSearches)->toBe([])
        ->and(mb_strlen((string) $page->tableSort))->toBe(255)
        ->and($page->tableRecordsPerPage)->toBe(50);

    $savedTableViewId = new ReflectionMethod(ListPurchaseOrders::class, 'savedTableViewId');
    expect($savedTableViewId->invoke($page, ['view_id' => '123']))->toBe(123);

    auth()->logout();
    $defaultId = new ReflectionMethod(ListPurchaseOrders::class, 'defaultSavedTableViewId');
    expect($defaultId->invoke($page))->toBeNull();
});
