<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\ModulePlaceholder;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\User;
use App\Providers\Filament\AdminPanelServiceProvider;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/** @return list<array<string, mixed>> */
function navigationCoverageSalesGroups(): array
{
    $resource = new class extends Resource
    {
        public static function getSlug(?Panel $panel = null): string
        {
            return 'navigation-fake-resources';
        }
    };

    return [
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
}

/** @param  list<array<string, mixed>>  $groups */
function navigationCoverageWithGroups(array $groups, Closure $callback): mixed
{
    $property = new ReflectionProperty(AdminModuleRegistry::class, 'groupDefinitions');
    $original = $property->getValue();
    $property->setValue(null, $groups);
    AdminModuleRegistry::forgetMemoized();

    try {
        return $callback();
    } finally {
        $property->setValue(null, $original);
        AdminModuleRegistry::forgetMemoized();
    }
}

it('treats a Livewire request identified only by its header as a Livewire update', function (): void {
    Route::get('/navigation-fake-resources/{record}', fn (): string => 'ok')
        ->name('filament.admin.resources.navigation-fake-resources.edit');
    Route::post('/registry-header-update', fn (): string => 'ok')->name('registry.header-update');

    $groups = navigationCoverageSalesGroups();

    $this->withHeaders([
        'X-Livewire' => 'true',
        'Referer' => url('/navigation-fake-resources/1'),
    ])->post('/registry-header-update');

    expect(AdminModuleRegistry::activeGroupKey($groups))->toBe('sales');

    // The same route without the Livewire header is an ordinary request and has no active module.
    $this->withoutHeader('X-Livewire');
    $this->withHeaders(['Referer' => url('/navigation-fake-resources/1')])->post('/registry-header-update');

    expect(AdminModuleRegistry::activeGroupKey($groups))->toBeNull();
});

it('refuses to resolve the active module from a referer on another host', function (): void {
    Route::get('/navigation-fake-resources/{record}', fn (): string => 'ok')
        ->name('filament.admin.resources.navigation-fake-resources.edit');
    Route::post('/livewire/registry-foreign-host', fn (): string => 'ok')->name('livewire.registry-foreign-host');

    $this->withHeader('Referer', 'https://attacker.example.net/navigation-fake-resources/1')
        ->post('/livewire/registry-foreign-host');

    expect(AdminModuleRegistry::activeGroupKey(navigationCoverageSalesGroups()))->toBeNull();
});

it('has no active module when the Livewire referer is an unnamed route', function (): void {
    Route::get('/registry-unnamed-referer', fn (): string => 'ok');
    Route::post('/livewire/registry-unnamed', fn (): string => 'ok')->name('livewire.registry-unnamed');

    $this->withHeader('Referer', url('/registry-unnamed-referer'))->post('/livewire/registry-unnamed');

    expect(AdminModuleRegistry::activeGroupKey(navigationCoverageSalesGroups()))->toBeNull();
});

it('has no active module when the Livewire referer matches no route', function (): void {
    Route::post('/livewire/registry-missing-referer', fn (): string => 'ok')->name('livewire.registry-missing-referer');

    $this->withHeader('Referer', url('/no-such-page-anywhere'))->post('/livewire/registry-missing-referer');

    expect(AdminModuleRegistry::activeGroupKey(navigationCoverageSalesGroups()))->toBeNull();
});

it('builds an empty module sidebar for a module key that is not registered', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $this->get(ModulePlaceholder::getUrl(['group' => 'ghost-module', 'item' => 'anything']));

    $provider = new AdminPanelServiceProvider(app());
    $navigation = new ReflectionMethod($provider, 'navigation');

    expect(AdminModuleRegistry::activeGroupKey())->toBe('ghost-module')
        ->and($navigation->invoke($provider, new NavigationBuilder)->getNavigation())->toBe([]);
});

it('lists a module without sections as a flat sidebar', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $groups = [
        [
            'key' => 'flat',
            'label' => 'admin.groups.sales',
            'icon' => Heroicon::OutlinedShoppingCart,
            'sort' => 1,
            'items' => [
                ['label' => 'admin.resources.quotations', 'link' => QuotationResource::class],
            ],
        ],
    ];

    $labels = navigationCoverageWithGroups($groups, function (): array {
        $this->get(ModulePlaceholder::getUrl(['group' => 'flat', 'item' => 'anything']));

        $provider = new AdminPanelServiceProvider(app());
        $builder = new ReflectionMethod($provider, 'navigation')->invoke($provider, new NavigationBuilder);

        return collect($builder->getNavigation())
            ->flatMap(static fn ($entry): array => method_exists($entry, 'getItems') ? collect($entry->getItems())->all() : [$entry])
            ->map(static fn ($item): string => (string) $item->getLabel())
            ->all();
    });

    expect($labels)->toContain((string) __('admin.resources.quotations'));
});
