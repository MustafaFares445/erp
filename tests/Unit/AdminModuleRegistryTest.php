<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ModulePlaceholder;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

it('declares a key, label, icon, sort, and items for every group', function (): void {
    foreach (AdminModuleRegistry::groups() as $group) {
        expect($group)->toHaveKeys(['key', 'label', 'icon', 'sort', 'items'])
            ->and($group['icon'])->toBeInstanceOf(Heroicon::class)
            ->and($group['items'])->not->toBeEmpty();
    }
});

it('has english translations for every group and item label', function (): void {
    foreach (AdminModuleRegistry::groups() as $group) {
        expect(__($group['label'], [], 'en'))->not->toBe($group['label']);

        foreach ($group['items'] as $item) {
            expect($item)->toHaveKeys(['label', 'link'])
                ->and(__($item['label'], [], 'en'))->not->toBe($item['label']);
        }
    }
});

it('resolves no link when the class does not exist', function (): void {
    $missingResource = 'App\\Filament\\Resources\\Nowhere\\NopeResource';

    expect(AdminModuleRegistry::resolveLink($missingResource))->toBeNull()
        ->and(AdminModuleRegistry::isAccessDenied($missingResource))->toBeFalse();
});

it('resolves no link for classes that are not resources or pages', function (): void {
    expect(AdminModuleRegistry::resolveLink(stdClass::class))->toBeNull()
        ->and(AdminModuleRegistry::isAccessDenied(stdClass::class))->toBeFalse();
});

it('resolves the url of a page the user can access', function (): void {
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
    };

    expect(AdminModuleRegistry::resolveLink($page::class))->toBe('/fake-module-url');
});

it('resolves no link for a page the user cannot access', function (): void {
    $page = new class extends Page
    {
        public static function canAccess(): bool
        {
            return false;
        }
    };

    expect(AdminModuleRegistry::resolveLink($page::class))->toBeNull()
        ->and(AdminModuleRegistry::isAccessDenied($page::class))->toBeTrue();
});

it('resolves no link when canAccess throws', function (): void {
    $page = new class extends Page
    {
        public static function canAccess(): bool
        {
            throw new RuntimeException('canAccess exploded');
        }
    };

    expect(AdminModuleRegistry::resolveLink($page::class))->toBeNull()
        ->and(AdminModuleRegistry::isAccessDenied($page::class))->toBeFalse();
});

it('resolves no link when getUrl throws', function (): void {
    $page = new class extends Page
    {
        public static function canAccess(): bool
        {
            return true;
        }

        public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
        {
            throw new RuntimeException('getUrl exploded');
        }
    };

    expect(AdminModuleRegistry::resolveLink($page::class))->toBeNull();
});

it('resolves an authorized resource record view link', function (): void {
    $resource = new class extends Resource
    {
        public static function canAccess(): bool
        {
            return true;
        }

        public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
        {
            return sprintf('/fake-resource/%s/%s', $name, $parameters['record']);
        }
    };

    expect(AdminModuleRegistry::resolveResourceRecordLink($resource::class, 42))
        ->toBe('/fake-resource/view/42');
});

it('does not resolve an unavailable or unauthorized resource record link', function (): void {
    $unauthorizedResource = new class extends Resource
    {
        public static function canAccess(): bool
        {
            return false;
        }
    };

    $brokenResource = new class extends Resource
    {
        public static function canAccess(): bool
        {
            throw new RuntimeException('canAccess exploded');
        }
    };

    expect(AdminModuleRegistry::resolveResourceRecordLink(stdClass::class, 42))->toBeNull()
        ->and(AdminModuleRegistry::resolveResourceRecordLink($unauthorizedResource::class, 42))->toBeNull()
        ->and(AdminModuleRegistry::resolveResourceRecordLink($brokenResource::class, 42))->toBeNull();
});

it('finds a group and item by their sidebar identifiers', function (): void {
    $resolved = AdminModuleRegistry::findItem('sales', 'quotations');

    expect($resolved)->not->toBeNull()
        ->and($resolved['group']['key'])->toBe('sales')
        ->and($resolved['item']['label'])->toBe('admin.resources.quotations');
});

it('finds nothing for an unknown group or item', function (): void {
    expect(AdminModuleRegistry::findItem('does-not-exist', 'quotations'))->toBeNull()
        ->and(AdminModuleRegistry::findItem('sales', 'does-not-exist'))->toBeNull();
});

it('builds a navigation placeholder for every missing resource or page', function (): void {
    $navigationItems = AdminModuleRegistry::navigationItems();

    $itemCount = collect(AdminModuleRegistry::groups())
        ->sum(fn (array $group): int => collect($group['items'])
            ->filter(fn (array $item): bool => ! class_exists($item['link']))
            ->count());

    expect($navigationItems)->toHaveCount($itemCount);
});

it('builds navigation items for only the requested group', function (): void {
    $navigationItems = AdminModuleRegistry::navigationItems(onlyGroupKey: 'sales');

    $salesGroup = collect(AdminModuleRegistry::groups())->firstWhere('key', 'sales');

    expect($navigationItems)->toHaveCount(count($salesGroup['items']));
});

it('returns no navigation items for an unknown group filter', function (): void {
    expect(AdminModuleRegistry::navigationItems(onlyGroupKey: 'does-not-exist'))->toBeEmpty();
});

it('has no active module when the current route matches nothing', function (): void {
    $this->get('/admin/login');

    expect(AdminModuleRegistry::activeGroupKey())->toBeNull();
});

it('has no active module when the request matches no route at all', function (): void {
    $this->get('/this-route-does-not-exist');

    expect(AdminModuleRegistry::activeGroupKey())->toBeNull();
});

it('has no active module when the matched route has no name', function (): void {
    Route::get('/unnamed-test-route', fn (): string => 'ok');

    $this->get('/unnamed-test-route');

    expect(AdminModuleRegistry::activeGroupKey())->toBeNull();
});

it('resolves the active module from the placeholder query string', function (): void {
    $this->get(ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'quotations']));

    expect(AdminModuleRegistry::activeGroupKey())->toBe('sales');
});

it('resolves the active module from a matching resource route', function (): void {
    $resource = new class extends Resource
    {
        public static function getSlug(?Panel $panel = null): string
        {
            return 'fake-resources';
        }
    };

    Route::get('/fake-resources/{record}', fn (): string => 'ok')
        ->name('filament.admin.resources.fake-resources.edit');

    $groups = [
        [
            'key' => 'sales',
            'label' => 'admin.groups.sales',
            'icon' => Heroicon::OutlinedShoppingCart,
            'sort' => 1,
            'items' => [
                ['label' => 'admin.resources.quotations', 'link' => $resource::class],
            ],
        ],
    ];

    $this->get('/fake-resources/1');

    expect(AdminModuleRegistry::activeGroupKey($groups))->toBe('sales');
});

it('skips a resource item whose slug does not match the current route', function (): void {
    $resource = new class extends Resource
    {
        public static function getSlug(?Panel $panel = null): string
        {
            return 'other-fake-resources';
        }
    };

    Route::get('/fake-resources/{record}', fn (): string => 'ok')
        ->name('filament.admin.resources.fake-resources.edit');

    $groups = [
        [
            'key' => 'sales',
            'label' => 'admin.groups.sales',
            'icon' => Heroicon::OutlinedShoppingCart,
            'sort' => 1,
            'items' => [
                ['label' => 'admin.resources.quotations', 'link' => $resource::class],
            ],
        ],
    ];

    $this->get('/fake-resources/1');

    expect(AdminModuleRegistry::activeGroupKey($groups))->toBeNull();
});

it('resolves the active module from a matching page route', function (): void {
    $page = new class extends Page
    {
        public static function getRouteName(?Panel $panel = null): string
        {
            return 'filament.admin.pages.fake-page';
        }
    };

    Route::get('/fake-page', fn (): string => 'ok')
        ->name('filament.admin.pages.fake-page');

    $groups = [
        [
            'key' => 'sales',
            'label' => 'admin.groups.sales',
            'icon' => Heroicon::OutlinedShoppingCart,
            'sort' => 1,
            'items' => [
                ['label' => 'admin.resources.quotations', 'link' => $page::class],
            ],
        ],
    ];

    $this->get('/fake-page');

    expect(AdminModuleRegistry::activeGroupKey($groups))->toBe('sales');
});

it('resolves the first reachable url for a group, falling back to its placeholder', function (): void {
    $salesGroup = collect(AdminModuleRegistry::groups())->firstWhere('key', 'sales');

    expect(AdminModuleRegistry::firstUrlFor($salesGroup))
        ->toBe(ModulePlaceholder::getUrl(['group' => 'sales', 'item' => 'quotations']));
});

it('resolves the first reachable url for a group directly, when its first item already has a working link', function (): void {
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
    };

    $group = [
        'key' => 'sales',
        'label' => 'admin.groups.sales',
        'icon' => Heroicon::OutlinedShoppingCart,
        'sort' => 1,
        'items' => [
            ['label' => 'admin.resources.quotations', 'link' => $page::class],
        ],
    ];

    expect(AdminModuleRegistry::firstUrlFor($group))->toBe('/fake-module-url');
});

it('returns to the dashboard when every group item is inaccessible', function (): void {
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
        'items' => [
            ['label' => 'admin.resources.quotations', 'link' => $page::class],
        ],
    ];

    expect(AdminModuleRegistry::firstUrlFor($group))->toBe(Dashboard::getUrl());
});

it('collects the navigation items already registered by a resolvable page', function (): void {
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

    $unresolvable = new class extends Page
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
        'items' => [
            ['label' => 'admin.resources.quotations', 'link' => $page::class],
            ['label' => 'admin.resources.orders', 'link' => $unresolvable::class],
        ],
    ];

    $items = AdminModuleRegistry::registeredNavigationItemsFor($group);

    expect($items)->toHaveCount(1)
        ->and($items[0]->getLabel())->toBe('Fake Page');
});

it('skips a group item that already resolves to a real page', function (): void {
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
    };

    $groups = [
        [
            'key' => 'sales',
            'label' => 'admin.groups.sales',
            'icon' => Heroicon::OutlinedShoppingCart,
            'sort' => 1,
            'items' => [
                ['label' => 'admin.resources.quotations', 'link' => $page::class],
            ],
        ],
    ];

    expect(AdminModuleRegistry::navigationItems($groups))->toBeEmpty();
});
