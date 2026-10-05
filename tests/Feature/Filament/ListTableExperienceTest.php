<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseRfqStatus;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseRfqs\Pages\ListPurchaseRfqs;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRfq;
use App\Models\RecordFavorite;
use App\Models\SavedTableView;
use App\Models\TableViewPreference;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $this->admin = User::factory()->admin()->create();
    $this->admin->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->admin);
});

function listTableRfq(User $requester, PurchaseRfqStatus $status = PurchaseRfqStatus::Draft): PurchaseRfq
{
    static $sequence = 0;
    $sequence++;

    return PurchaseRfq::query()->forceCreate([
        'rfq_number' => sprintf('RFQ-T-%04d', $sequence),
        'status' => $status,
        'currency_code' => 'AED',
        'requested_by' => $requester->getKey(),
    ]);
}

/** @param  array<string, mixed>  $state */
function listTableSavedView(User $owner, string $name, array $state = [], bool $isPublic = false): SavedTableView
{
    return SavedTableView::query()->create([
        'user_id' => $owner->getKey(),
        'page_key' => 'purchasing.purchase-orders',
        'name' => $name,
        'is_public' => $isPublic,
        'state' => $state,
        'state_version' => 1,
    ]);
}

it('gives resource tables the shared column manager, slide-over filters and page sizes', function (): void {
    $table = Livewire::test(ListPurchaseOrders::class)->instance()->getTable();

    expect($table->hasColumnManager())->toBeTrue()
        ->and($table->hasReorderableColumns())->toBeTrue()
        ->and($table->hasDeferredColumnManager())->toBeFalse()
        ->and($table->getFiltersLayout())->toBe(FiltersLayout::Modal)
        ->and($table->getFiltersTriggerAction()->isModalSlideOver())->toBeTrue()
        ->and($table->hasDeferredFilters())->toBeTrue()
        ->and($table->getPaginationPageOptions())->toBe([10, 25, 50, 100])
        ->and($table->getColumn('is_favorited')->isToggleable())->toBeFalse()
        ->and($table->getColumn('total_amount')->isToggleable())->toBeTrue();
});

it('stars and unstars a record for the current user only', function (): void {
    $order = PurchaseOrder::factory()->create();
    $colleague = User::factory()->admin()->create();
    $order->toggleFavoriteFor($colleague);

    $component = Livewire::test(ListPurchaseOrders::class)
        ->callTableColumnAction('is_favorited', $order);

    expect($order->isFavoritedBy($this->admin))->toBeTrue()
        ->and(PurchaseOrder::query()->favoritedBy($this->admin)->pluck('id')->all())->toBe([$order->id]);

    $component->callTableColumnAction('is_favorited', $order);

    expect($order->isFavoritedBy($this->admin))->toBeFalse()
        ->and($order->isFavoritedBy($colleague))->toBeTrue();
});

it("lists only the current user's starred records under the Starred tab", function (): void {
    [$starred, $other, $starredByColleague] = PurchaseOrder::factory()->count(3)->create()->all();
    $starred->toggleFavoriteFor($this->admin);
    $starredByColleague->toggleFavoriteFor(User::factory()->admin()->create());

    Livewire::test(ListPurchaseOrders::class)
        ->call('selectTableView', 'preset', 'starred')
        ->assertSet('activeTab', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other, $starredByColleague]);
});

it('groups purchase orders by their translated commercial status', function (): void {
    $order = PurchaseOrder::factory()->create();

    Livewire::test(ListPurchaseOrders::class)
        ->set('tableGrouping', 'status')
        ->assertSuccessful()
        ->assertSee($order->status->label())
        ->assertCanSeeTableRecords([$order]);
});

it('removes favorites together with the user', function (): void {
    $order = PurchaseOrder::factory()->create();
    $colleague = User::factory()->admin()->create();
    $order->toggleFavoriteFor($colleague);

    $colleague->delete();

    expect(RecordFavorite::query()->count())->toBe(0)
        ->and($order->favorites()->exists())->toBeFalse();
});

it('renders presets, Starred and own saved views as one in-card tab bar', function (): void {
    $own = listTableSavedView($this->admin, 'My overdue orders');
    $shared = listTableSavedView(User::factory()->admin()->create(), 'Team receiving', isPublic: true);

    $component = Livewire::test(ListPurchaseOrders::class)
        ->assertSee(__('Ready to send'))
        ->assertSee(__('Starred'))
        ->assertSee('My overdue orders')
        ->assertDontSee('Team receiving')
        ->assertSeeHtml("selectTableView('preset', 'starred')")
        ->assertSeeHtml("selectTableView('saved', '{$own->id}')");

    expect($component->instance()->getTabBarSavedTableViews()->pluck('id')->all())->toBe([$own->id]);

    $component
        ->callAction('manageTableViewTabs', data: ['view_ids' => [$shared->id]])
        ->assertHasNoActionErrors();

    expect($component->instance()->getTabBarSavedTableViews()->pluck('id')->all())->toBe([$shared->id])
        ->and(TableViewPreference::query()->where('view_key', (string) $own->id)->value('is_favorite'))->toBeFalse();
});

it('switches between saved views and presets from the tab bar', function (): void {
    $view = listTableSavedView($this->admin, 'Receiving search', [
        'preset_tab' => 'receiving',
        'tableSearch' => 'PO-77',
    ]);

    Livewire::test(ListPurchaseOrders::class)
        ->call('selectTableView', 'saved', (string) $view->id)
        ->assertSet('activeSavedTableView', $view->id)
        ->assertSet('activeTab', 'receiving')
        ->assertSet('tableSearch', 'PO-77')
        ->call('selectTableView', 'preset', 'all')
        ->assertSet('activeSavedTableView', null)
        ->assertSet('activeTab', 'all')
        ->assertSet('tableSearch', '')
        ->call('selectTableView', 'preset', 'overdue')
        ->assertSet('activeTab', 'overdue');
});

it('rejects unknown presets and saved views the user cannot see', function (): void {
    $private = listTableSavedView(User::factory()->admin()->create(), 'Private view');

    Livewire::test(ListPurchaseOrders::class)
        ->call('selectTableView', 'preset', 'not-a-tab')
        ->assertStatus(404);

    Livewire::test(ListPurchaseOrders::class)
        ->call('selectTableView', 'saved', (string) $private->id)
        ->assertStatus(404)
        ->assertSet('activeSavedTableView', null);
});

it('filters the RFQ list with presets and query-builder rules', function (): void {
    $colleague = User::factory()->admin()->create();
    $mineDraft = listTableRfq($this->admin);
    $mineClosed = listTableRfq($this->admin, PurchaseRfqStatus::Closed);
    $theirsSent = listTableRfq($colleague, PurchaseRfqStatus::Sent);

    Livewire::test(ListPurchaseRfqs::class)
        ->assertCanSeeTableRecords([$mineDraft, $mineClosed, $theirsSent])
        ->call('selectTableView', 'preset', 'mine')
        ->assertCanSeeTableRecords([$mineDraft, $mineClosed])
        ->assertCanNotSeeTableRecords([$theirsSent])
        ->call('selectTableView', 'preset', 'open')
        ->assertCanSeeTableRecords([$mineDraft, $theirsSent])
        ->assertCanNotSeeTableRecords([$mineClosed])
        ->call('selectTableView', 'preset', 'all')
        ->filterTable('queryBuilder', [
            'rules' => [
                'status-rule' => [
                    'type' => 'status',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [PurchaseRfqStatus::Sent->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$theirsSent])
        ->assertCanNotSeeTableRecords([$mineDraft, $mineClosed]);
});
