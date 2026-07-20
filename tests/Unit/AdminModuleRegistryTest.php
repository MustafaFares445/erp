<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use Filament\Support\Icons\Heroicon;

it('declares a key, label, icon, sort, and items for every group', function () {
    foreach (AdminModuleRegistry::groups() as $group) {
        expect($group)->toHaveKeys(['key', 'label', 'icon', 'sort', 'items'])
            ->and($group['icon'])->toBeInstanceOf(Heroicon::class)
            ->and($group['items'])->not->toBeEmpty();
    }
});

it('has english and arabic translations for every group and item label', function () {
    foreach (AdminModuleRegistry::groups() as $group) {
        expect(__($group['label'], [], 'en'))->not->toBe($group['label'])
            ->and(__($group['label'], [], 'ar'))->not->toBe($group['label']);

        foreach ($group['items'] as $item) {
            expect($item)->toHaveKeys(['label', 'link'])
                ->and(__($item['label'], [], 'en'))->not->toBe($item['label'])
                ->and(__($item['label'], [], 'ar'))->not->toBe($item['label']);
        }
    }
});

it('resolves no link when the class does not exist', function () {
    expect(AdminModuleRegistry::resolveLink('App\\Filament\\Resources\\Nowhere\\NopeResource'))->toBeNull();
});

it('resolves no link for classes that are not resources or pages', function () {
    expect(AdminModuleRegistry::resolveLink(stdClass::class))->toBeNull();
});

it('finds a group and item by their sidebar identifiers', function () {
    $resolved = AdminModuleRegistry::findItem('sales', 'quotations');

    expect($resolved)->not->toBeNull()
        ->and($resolved['group']['key'])->toBe('sales')
        ->and($resolved['item']['label'])->toBe('admin.resources.quotations');
});

it('finds nothing for an unknown group or item', function () {
    expect(AdminModuleRegistry::findItem('does-not-exist', 'quotations'))->toBeNull()
        ->and(AdminModuleRegistry::findItem('sales', 'does-not-exist'))->toBeNull();
});

it('builds a navigation item for every group item that has no resolvable resource yet', function () {
    $navigationItems = AdminModuleRegistry::navigationItems();

    $itemCount = collect(AdminModuleRegistry::groups())
        ->sum(fn (array $group): int => count($group['items']));

    expect($navigationItems)->toHaveCount($itemCount);
});
