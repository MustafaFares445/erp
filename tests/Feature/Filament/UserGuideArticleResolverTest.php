<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\UserGuideArticleResolver;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

final class GuideResolverRoutelessPage extends Page
{
    protected string $view = 'filament.pages.dashboard';

    public static function getRouteName(?Panel $panel = null): string
    {
        throw new RuntimeException('page has no route');
    }
}

final class GuideResolverBrokenResource extends Resource
{
    public static function getPages(): array
    {
        throw new RuntimeException('resource pages cannot be resolved');
    }
}

final class GuideResolverNonPageResource extends Resource
{
    public static function getPages(): array
    {
        return ['index' => new PageRegistration(stdClass::class, static fn (): ?Route => null)];
    }
}

/**
 * Replaces the module registry with one group made of the given member classes.
 *
 * @param  list<string>  $members
 */
function useGuideResolverRegistry(array $members): void
{
    $property = new ReflectionProperty(AdminModuleRegistry::class, 'groupDefinitions');

    $property->setValue(null, [[
        'key' => 'guide',
        'label' => 'Guide coverage',
        'icon' => Heroicon::OutlinedBookOpen,
        'sort' => 1,
        'items' => array_map(
            static fn (string $class): array => ['label' => class_basename($class), 'link' => $class],
            $members,
        ),
    ]]);
    AdminModuleRegistry::forgetMemoized();
}

beforeEach(function (): void {
    Config::set('app.user_guide_url', '/guide/');
    $this->originalGroups = new ReflectionProperty(AdminModuleRegistry::class, 'groupDefinitions')->getValue();
});

afterEach(function (): void {
    new ReflectionProperty(AdminModuleRegistry::class, 'groupDefinitions')->setValue(null, $this->originalGroups);
    AdminModuleRegistry::forgetMemoized();
});

it('skips registry members that are not loadable classes when resolving a route', function (): void {
    useGuideResolverRegistry(['App\Filament\Pages\DoesNotExistPage', QuotationResource::class]);

    expect(UserGuideArticleResolver::forRouteName('filament.admin.resources.quotations.index', 'guide', 'en'))
        ->toBe('/guide/screens/guide/quotation');
});

it('skips pages whose route cannot be generated and still finds later members', function (): void {
    useGuideResolverRegistry([GuideResolverRoutelessPage::class, SalesDashboard::class]);

    expect(UserGuideArticleResolver::forRouteName('filament.admin.pages.sales-dashboard', 'guide', 'en'))
        ->toBe('/guide/screens/guide/dashboard');
});

it('only scopes contextual help to real Filament pages of loadable registry members', function (): void {
    useGuideResolverRegistry([
        'App\Filament\Pages\DoesNotExistPage',
        GuideResolverNonPageResource::class,
        GuideResolverBrokenResource::class,
        QuotationResource::class,
        SalesDashboard::class,
    ]);

    $scopes = UserGuideArticleResolver::pageScopes();

    expect($scopes)
        ->not->toHaveKey(stdClass::class)
        ->toHaveKey(SalesDashboard::class)
        ->and($scopes[SalesDashboard::class])->toBe(['group' => 'guide', 'article_class' => SalesDashboard::class])
        ->and(collect($scopes)->pluck('article_class')->unique()->values()->all())
        ->toBe([QuotationResource::class, SalesDashboard::class]);
});
