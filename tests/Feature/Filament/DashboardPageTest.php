<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\ModulePlaceholder;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not allow unauthenticated users to access the dashboard page', function (): void {
    $response = $this->get('/admin');

    $response->assertStatus(302);

    expect((string) $response->headers->get('Location'))->toContain('/admin/login');
});

it('redirects an authenticated administrator to the first reachable module page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect(url('/admin/quotations'));
});

it('uses the panel home route to redirect to a module page', function (): void {
    expect(route('filament.admin.home'))->toBe(url('/admin'))
        ->and(url('/admin/quotations'))->not->toBe(url('/admin'));
});

it('registers no global widgets because module dashboards own their widgets', function (): void {
    $widgets = Filament::getPanel('admin')->getWidgets();

    expect($widgets)->toBe([])
        ->and($widgets)->not->toContain(AccountWidget::class)
        ->and($widgets)->not->toContain(FilamentInfoWidget::class);
});

it('does not register the removed standalone dashboard page', function (): void {
    expect(Filament::getPanel('admin')->getPages())
        ->not->toContain('App\\Filament\\Pages\\Dashboard');
});

it('renders module pages without default or unauthorized inventory widgets', function (): void {
    $user = User::factory()->create();

    $response = $this->followingRedirects()->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertDontSee('fi-account-widget', false);
    $response->assertDontSee('fi-filament-info-widget', false);
});

it('does not render the removed standalone dashboard content', function (): void {
    $user = User::factory()->create();

    $response = $this->followingRedirects()->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertDontSeeText('Review the inventory work that needs attention');
});

it('follows the approved domain order for the module switcher', function (): void {
    $expectedOrder = [
        'sales',
        'accounting',
        'inventory',
        'vendors',
        'crm',
        'employees',
        'support',
        'reports',
        'system',
    ];

    expect(array_column(AdminModuleRegistry::groups(), 'key'))->toBe($expectedOrder);

    // The switcher only renders groups the viewer can open, so ordering is
    // asserted against a user who can see all nine of them.
    foreach ([
        InventoryPermissionSeeder::class,
        CrmPermissionSeeder::class,
        EmployeePermissionSeeder::class,
        SupportPermissionSeeder::class,
        AccountingPermissionSeeder::class,
        PurchasePermissionSeeder::class,
        SalesPermissionSeeder::class,
    ] as $seeder) {
        (new $seeder)->run();
    }

    $user = User::factory()->admin()->create();
    $user->syncRoles([DashboardRole::SystemAdmin->value]);

    $response = $this->followingRedirects()->actingAs($user)->get('/admin');

    $response->assertOk();

    $positions = collect(AdminModuleRegistry::groups())
        ->map(fn (array $group): int|false => mb_strpos((string) $response->getContent(), __($group['label'])));

    expect($positions->contains(false))->toBeFalse()
        ->and($positions->values()->all())->toBe($positions->sort()->values()->all());
});

it('shows the active module navigation after the root redirect', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertRedirect(url('/admin/quotations'));
    $this->actingAs($user)->get(url('/admin/quotations'))->assertOk();

    expect(AdminModuleRegistry::activeGroupKey())->toBe('sales');

    $navigationItems = collect(Filament::getPanel('admin')->buildNavigation())
        ->flatMap(fn ($group) => $group->getItems());

    $navigationLabels = $navigationItems
        ->map(fn ($item): string => $item->getLabel())
        ->all();

    expect($navigationLabels)->toContain(__('admin.resources.quotations'));
    expect($navigationLabels)->not->toContain(__('admin.resources.inventory_dashboard'));
});

it('scopes the sidebar to the active module when visiting one of its placeholder pages', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'quotations']));

    expect(AdminModuleRegistry::activeGroupKey())->toBe('sales');

    $salesGroup = collect(AdminModuleRegistry::groups())->firstWhere('key', 'sales');

    // Items the user is denied access to (e.g. Orders, gated behind the
    // delivery.view permission) are hidden entirely, not shown as a
    // placeholder, so they don't count towards the sidebar total.
    $visibleItemCount = collect($salesGroup['items'])
        ->reject(fn (array $item): bool => AdminModuleRegistry::isAccessDenied($item['link']))
        ->count();

    $navigationItems = collect(Filament::getPanel('admin')->buildNavigation())
        ->flatMap(fn ($group) => $group->getItems());

    expect($navigationItems)->toHaveCount($visibleItemCount);
});

it('resolves no link for a missing class', function (): void {
    expect(AdminModuleRegistry::resolveLink('App\\Filament\\Resources\\DoesNotExist\\NopeResource'))->toBeNull();
});

it('resolves no link for a class that is not a resource or page', function (): void {
    expect(AdminModuleRegistry::resolveLink(stdClass::class))->toBeNull();
});

it('renders english labels correctly', function (): void {
    $user = User::factory()->create();

    app()->setLocale('en');

    $response = $this->followingRedirects()->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertSee('Quotations');

    expect($response->getContent())->toContain('dir="ltr"');
});

it('opens a working placeholder page from a sidebar navigation item', function (): void {
    $user = User::factory()->create();

    $url = ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'quotations']);

    $response = $this->actingAs($user)->get($url);

    $response->assertOk();
    $response->assertSee(__('admin.resources.quotations'));
    $response->assertSeeText(__('admin.empty_module'));
});

it('registers vendors and its unfinished workflow placeholders', function (): void {
    $user = User::factory()->create();

    $purchaseOrdersUrl = ModulePlaceholder::getUrl(['group' => 'vendors', 'item' => 'purchase_orders']);
    $supplierConfirmationsUrl = ModulePlaceholder::getUrl(['group' => 'vendors', 'item' => 'supplier_confirmations']);

    expect(AdminModuleRegistry::findItem('vendors', 'suppliers'))->not->toBeNull()
        ->and(AdminModuleRegistry::findItem('vendors', 'purchase_orders'))->not->toBeNull()
        ->and(AdminModuleRegistry::findItem('vendors', 'supplier_confirmations'))->not->toBeNull();

    $this->actingAs($user)->get($purchaseOrdersUrl)->assertOk();
    $this->actingAs($user)->get($supplierConfirmationsUrl)->assertOk();
});

it('returns a 404 for a placeholder page with an unknown group or item', function (): void {
    $user = User::factory()->create();

    $url = ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'does-not-exist']);

    $this->actingAs($user)->get($url)->assertNotFound();
});
