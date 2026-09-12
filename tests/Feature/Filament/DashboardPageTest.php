<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\Dashboard;
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
        ->assertSee(__('admin.dashboard'))
        ->assertSeeText('Review the inventory work that needs attention');
});

it("uses the dashboard page as the admin panel's root route", function (): void {
    expect(Dashboard::getUrl())->toBe(url('/admin'));
});

it('registers no global widgets because module dashboards own their widgets', function (): void {
    $widgets = Filament::getPanel('admin')->getWidgets();

    expect($widgets)->toBe([])
        ->and($widgets)->not->toContain(AccountWidget::class)
        ->and($widgets)->not->toContain(FilamentInfoWidget::class);
});

it('renders the dashboard without default or placeholder module content', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk()
        ->assertDontSee('fi-account-widget', false)
        ->assertDontSee('fi-filament-info-widget', false)
        ->assertDontSeeText(__('admin.empty_module'));
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
    $response = $this->actingAs($user)->get('/admin')->assertOk();

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

it('contains only real registered module classes and no compatibility page', function (): void {
    expect(class_exists('App\\Filament\\Pages\\ModulePlaceholder'))->toBeFalse();

    foreach (AdminModuleRegistry::groups() as $group) {
        foreach ($group['items'] as $item) {
            expect(class_exists($item['link']))->toBeTrue("{$item['label']} ({$item['link']}) does not exist.");
        }
    }
});

it('resolves no link for a missing or invalid class', function (): void {
    expect(AdminModuleRegistry::resolveLink('App\\Filament\\Resources\\DoesNotExist\\NopeResource'))->toBeNull()
        ->and(AdminModuleRegistry::resolveLink(stdClass::class))->toBeNull();
});

it('renders english labels correctly', function (): void {
    $user = User::factory()->create();
    app()->setLocale('en');

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk()->assertSee('Dashboard');
    expect($response->getContent())->toContain('dir="ltr"');
});
