<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\SalesSettings\SalesSettingResource;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

it('declares valid canonical groups and translated items', function (): void {
    foreach (AdminModuleRegistry::groups() as $group) {
        expect($group)->toHaveKeys(['key', 'label', 'icon', 'sort', 'items'])
            ->and($group['icon'])->toBeInstanceOf(Heroicon::class)
            ->and($group['items'])->not->toBeEmpty()
            ->and(__($group['label'], [], 'en'))->not->toBe($group['label']);

        foreach ($group['items'] as $item) {
            expect($item)->toHaveKeys(['label', 'link'])
                ->and(__($item['label'], [], 'en'))->not->toBe($item['label'])
                ->and(class_exists($item['link']))->toBeTrue("{$item['label']} ({$item['link']}) does not exist.")
                ->and(is_subclass_of($item['link'], Resource::class) || is_subclass_of($item['link'], Page::class))->toBeTrue();
        }
    }
});

it('has removed the module placeholder compatibility page', function (): void {
    expect(class_exists('App\\Filament\\Pages\\ModulePlaceholder'))->toBeFalse()
        ->and(AdminModuleRegistry::navigationItems())->toBeEmpty();
});

it('omits unresolved or unauthorized entries instead of creating fallback navigation', function (): void {
    $missing = 'App\\Filament\\Resources\\Nowhere\\NopeResource';

    expect(AdminModuleRegistry::resolveLink($missing))->toBeNull()
        ->and(AdminModuleRegistry::isAccessDenied($missing))->toBeFalse()
        ->and(AdminModuleRegistry::navigationItems([
            [
                'key' => 'inventory',
                'label' => 'admin.groups.inventory',
                'icon' => Heroicon::OutlinedCube,
                'sort' => 3,
                'items' => [['label' => 'admin.resources.products', 'link' => $missing]],
            ],
        ]))->toBeEmpty();
});

it('resolves an accessible page and hides an inaccessible page', function (): void {
    $accessible = new class extends Page
    {
        public static function canAccess(): bool
        {
            return true;
        }

        public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
        {
            return '/fake-module-url';
        }
    };

    $denied = new class extends Page
    {
        public static function canAccess(): bool
        {
            return false;
        }
    };

    expect(AdminModuleRegistry::resolveLink($accessible::class))->toBe('/fake-module-url')
        ->and(AdminModuleRegistry::resolveLink($denied::class))->toBeNull()
        ->and(AdminModuleRegistry::isAccessDenied($denied::class))->toBeTrue();
});

it('finds canonical items and keeps module placement invariants', function (): void {
    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');
    $purchasing = collect(AdminModuleRegistry::groups())->firstWhere('key', 'purchasing');
    $crm = collect(AdminModuleRegistry::groups())->firstWhere('key', 'crm');

    expect(AdminModuleRegistry::findItem('sales', 'quotations'))->not->toBeNull()
        ->and(AdminModuleRegistry::findItem('does-not-exist', 'quotations'))->toBeNull()
        ->and(collect($inventory['items'])->pluck('label'))->not->toContain('admin.resources.suppliers')
        ->and(collect($purchasing['items'])->pluck('label'))->toContain('admin.resources.suppliers')
        ->and(collect($crm['items'])->pluck('label'))->toContain('admin.resources.pricing_tiers');
});

it('resolves tax definitions to the canonical sales settings resource', function (): void {
    $resolved = AdminModuleRegistry::findItem('system', 'tax_definitions');

    expect($resolved)->not->toBeNull()
        ->and($resolved['item']['link'])->toBe(SalesSettingResource::class);
});

it('registers no navigation label in more than one group', function (): void {
    $labels = collect(AdminModuleRegistry::groups())
        ->flatMap(fn (array $group): array => collect($group['items'])->pluck('label')->all());

    expect($labels->all())->toBe($labels->unique()->all());
});

it('resolves active groups from canonical resource and page routes', function (): void {
    $resource = new class extends Resource
    {
        public static function getSlug(?Panel $panel = null): string
        {
            return 'fake-resources';
        }
    };

    Route::get('/fake-resources/{record}', fn (): string => 'ok')
        ->name('filament.admin.resources.fake-resources.edit');

    $groups = [[
        'key' => 'sales',
        'label' => 'admin.groups.sales',
        'icon' => Heroicon::OutlinedShoppingCart,
        'sort' => 1,
        'items' => [['label' => 'admin.resources.quotations', 'link' => $resource::class]],
    ]];

    $this->get('/fake-resources/1');

    expect(AdminModuleRegistry::activeGroupKey($groups))->toBe('sales');
});

it('falls back to the dashboard when a group has no reachable canonical item', function (): void {
    $page = new class extends Page
    {
        public static function canAccess(): bool
        {
            return false;
        }
    };

    $group = [
        'key' => 'sales',
        'label' => 'admin.groups.sales',
        'icon' => Heroicon::OutlinedShoppingCart,
        'sort' => 1,
        'items' => [['label' => 'admin.resources.quotations', 'link' => $page::class]],
    ];

    expect(AdminModuleRegistry::firstUrlFor($group))->toBe(Dashboard::getUrl());
});

it('collects registered navigation only from resolvable canonical entries', function (): void {
    $page = new class extends Page
    {
        public static function canAccess(): bool
        {
            return true;
        }

        public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
        {
            return '/fake-module-url';
        }

        public static function getNavigationItems(): array
        {
            return [NavigationItem::make('Fake Page')];
        }
    };

    $items = AdminModuleRegistry::registeredNavigationItemsFor([
        'key' => 'sales',
        'label' => 'admin.groups.sales',
        'icon' => Heroicon::OutlinedShoppingCart,
        'sort' => 1,
        'items' => [['label' => 'admin.resources.quotations', 'link' => $page::class]],
    ]);

    expect($items)->toHaveCount(1)
        ->and($items[0]->getLabel())->toBe('Fake Page');
});
