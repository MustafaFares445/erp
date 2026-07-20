<?php

namespace Tests\Feature\Filament;

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\Modules;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModulesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_the_modules_page(): void
    {
        $response = $this->get('/admin');

        $response->assertStatus(302);
        $this->assertStringContainsString('/admin/login', (string) $response->headers->get('Location'));
    }

    public function test_an_authenticated_administrator_can_access_the_modules_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee(__('admin.modules'));
    }

    public function test_the_modules_page_is_the_admin_panels_root_route(): void
    {
        $this->assertSame(url('/admin'), Modules::getUrl());
    }

    public function test_no_default_widgets_are_registered_in_the_admin_panel(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertSame([], $widgets);
        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
    }

    public function test_the_modules_page_renders_without_default_widgets(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
        $response->assertDontSee('fi-account-widget', false);
        $response->assertDontSee('fi-filament-info-widget', false);
    }

    public function test_navigation_groups_follow_the_approved_domain_order(): void
    {
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

        $this->assertSame($expectedOrder, array_column(AdminModuleRegistry::groups(), 'key'));

        $expectedLabels = collect(AdminModuleRegistry::groups())
            ->map(fn (array $group): string => __($group['label']))
            ->all();

        $actualLabels = collect(Filament::getPanel('admin')->getNavigationGroups())
            ->map(fn ($group): ?string => $group->getLabel())
            ->all();

        $this->assertSame($expectedLabels, $actualLabels);
    }

    public function test_missing_resources_do_not_produce_broken_links(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();

        // No business Resources exist yet, so every module falls back to
        // the empty-state message instead of linking to a missing route.
        $response->assertSeeText(__('admin.empty_module'));
    }

    public function test_resolve_link_returns_null_for_a_missing_class(): void
    {
        $this->assertNull(AdminModuleRegistry::resolveLink('App\\Filament\\Resources\\DoesNotExist\\NopeResource'));
    }

    public function test_resolve_link_returns_null_for_a_class_that_is_not_a_resource_or_page(): void
    {
        $this->assertNull(AdminModuleRegistry::resolveLink(\stdClass::class));
    }

    public function test_english_labels_render_correctly(): void
    {
        $user = User::factory()->create();

        app()->setLocale('en');

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
        $response->assertSee('Modules');
        $response->assertSee('Sales');
        $response->assertSee('System');
        $this->assertStringContainsString('dir="ltr"', $response->getContent());
    }

    public function test_arabic_labels_render_correctly_with_rtl_direction(): void
    {
        $user = User::factory()->create();

        app()->setLocale('ar');

        $response = $this->actingAs($user)->get('/admin');

        app()->setLocale(config('app.locale'));

        $response->assertOk();
        $response->assertSee('الوحدات');
        $response->assertSee('المبيعات');
        $response->assertSee('النظام');
        $this->assertStringContainsString('dir="rtl"', $response->getContent());
    }
}
