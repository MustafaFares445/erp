<?php

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ModulePlaceholder;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not allow unauthenticated users to access the dashboard page', function () {
    $response = $this->get('/admin');

    $response->assertStatus(302);
    expect((string) $response->headers->get('Location'))->toContain('/admin/login');
});

it('allows an authenticated administrator to access the dashboard page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee(__('admin.dashboard'));
});

it("uses the dashboard page as the admin panel's root route", function () {
    expect(Dashboard::getUrl())->toBe(url('/admin'));
});

it('does not register any default widgets in the admin panel', function () {
    $widgets = Filament::getPanel('admin')->getWidgets();

    expect($widgets)->toBe([])
        ->and($widgets)->not->toContain(AccountWidget::class)
        ->and($widgets)->not->toContain(FilamentInfoWidget::class);
});

it('renders the dashboard page without default widgets', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertDontSee('fi-account-widget', false);
    $response->assertDontSee('fi-filament-info-widget', false);
});

it('renders the dashboard page with no module content of its own', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertDontSeeText(__('admin.empty_module'));
});

it('follows the approved domain order for navigation groups', function () {
    $expectedOrder = [
        'sales',
        'accounting',
        'inventory',
        'purchasing',
        'crm',
        'employees',
        'support',
        'reports',
        'system',
    ];

    expect(array_column(AdminModuleRegistry::groups(), 'key'))->toBe($expectedOrder);

    $expectedLabels = collect(AdminModuleRegistry::groups())
        ->map(fn (array $group): string => __($group['label']))
        ->all();

    $actualLabels = collect(Filament::getPanel('admin')->getNavigationGroups())
        ->map(fn ($group): ?string => $group->getLabel())
        ->all();

    expect($actualLabels)->toBe($expectedLabels);
});

it('resolves no link for a missing class', function () {
    expect(AdminModuleRegistry::resolveLink('App\\Filament\\Resources\\DoesNotExist\\NopeResource'))->toBeNull();
});

it('resolves no link for a class that is not a resource or page', function () {
    expect(AdminModuleRegistry::resolveLink(stdClass::class))->toBeNull();
});

it('renders english labels correctly', function () {
    $user = User::factory()->create();

    app()->setLocale('en');

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertSee('Dashboard');
    $response->assertSee('Sales');
    $response->assertSee('System');
    expect($response->getContent())->toContain('dir="ltr"');
});

it('renders arabic labels correctly with rtl direction', function () {
    $user = User::factory()->create();

    app()->setLocale('ar');

    $response = $this->actingAs($user)->get('/admin');

    app()->setLocale(config('app.locale'));

    $response->assertOk();
    $response->assertSee('لوحة التحكم');
    $response->assertSee('المبيعات');
    $response->assertSee('النظام');
    expect($response->getContent())->toContain('dir="rtl"');
});

it('registers a sidebar navigation item for every module item without a resource yet', function () {
    $navigationItems = Filament::getPanel('admin')->getNavigationItems();

    $itemCount = collect(AdminModuleRegistry::groups())
        ->sum(fn (array $group): int => count($group['items']));

    expect($navigationItems)->toHaveCount($itemCount);

    foreach ($navigationItems as $navigationItem) {
        expect($navigationItem->getUrl())->toContain('/admin/module-placeholder');
    }
});

it('opens a working placeholder page from a sidebar navigation item', function () {
    $user = User::factory()->create();

    $url = ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'quotations']);

    $response = $this->actingAs($user)->get($url);

    $response->assertOk();
    $response->assertSee(__('admin.resources.quotations'));
    $response->assertSeeText(__('admin.empty_module'));
});

it('returns a 404 for a placeholder page with an unknown group or item', function () {
    $user = User::factory()->create();

    $url = ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'does-not-exist']);

    $this->actingAs($user)->get($url)->assertNotFound();
});
