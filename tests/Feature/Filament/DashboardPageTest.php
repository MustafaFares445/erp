<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ModulePlaceholder;
use App\Models\User;
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

it('allows an authenticated administrator to access the dashboard page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee(__('admin.dashboard'));
});

it("uses the dashboard page as the admin panel's root route", function (): void {
    expect(Dashboard::getUrl())->toBe(url('/admin'));
});

it('does not register any default widgets in the admin panel', function (): void {
    $widgets = Filament::getPanel('admin')->getWidgets();

    expect($widgets)->toBe([])
        ->and($widgets)->not->toContain(AccountWidget::class)
        ->and($widgets)->not->toContain(FilamentInfoWidget::class);
});

it('renders the dashboard page without default widgets', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertDontSee('fi-account-widget', false);
    $response->assertDontSee('fi-filament-info-widget', false);
});

it('renders the dashboard page with no module content of its own', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertDontSeeText(__('admin.empty_module'));
});

it('follows the approved domain order for the module switcher', function (): void {
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

    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();

    $positions = collect(AdminModuleRegistry::groups())
        ->map(fn (array $group): int|false => mb_strpos((string) $response->getContent(), __($group['label'])));

    expect($positions->contains(false))->toBeFalse()
        ->and($positions->values()->all())->toBe($positions->sort()->values()->all());
});

it('has no active module on the dashboard, so the sidebar only shows the dashboard link', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin');

    expect(AdminModuleRegistry::activeGroupKey())->toBeNull();

    $navigationItems = collect(Filament::getPanel('admin')->buildNavigation())
        ->flatMap(fn ($group) => $group->getItems());

    expect($navigationItems)->toHaveCount(1)
        ->and($navigationItems->first()->getLabel())->toBe(__('admin.dashboard'));
});

it('scopes the sidebar to the active module when visiting one of its placeholder pages', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'quotations']));

    expect(AdminModuleRegistry::activeGroupKey())->toBe('sales');

    $salesGroup = collect(AdminModuleRegistry::groups())->firstWhere('key', 'sales');

    $navigationItems = collect(Filament::getPanel('admin')->buildNavigation())
        ->flatMap(fn ($group) => $group->getItems());

    expect($navigationItems)->toHaveCount(1 + count($salesGroup['items']));
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

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();
    $response->assertSee('Dashboard');
    $response->assertSee('Sales');
    $response->assertSee('System');

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

it('returns a 404 for a placeholder page with an unknown group or item', function (): void {
    $user = User::factory()->create();

    $url = ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'does-not-exist']);

    $this->actingAs($user)->get($url)->assertNotFound();
});
