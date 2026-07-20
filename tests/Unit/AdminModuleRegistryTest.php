<?php

namespace Tests\Unit;

use App\Filament\AdminModuleRegistry;
use Filament\Support\Icons\Heroicon;
use Tests\TestCase;

class AdminModuleRegistryTest extends TestCase
{
    public function test_every_group_declares_a_key_label_icon_sort_and_items(): void
    {
        foreach (AdminModuleRegistry::groups() as $group) {
            $this->assertArrayHasKey('key', $group);
            $this->assertArrayHasKey('label', $group);
            $this->assertArrayHasKey('icon', $group);
            $this->assertArrayHasKey('sort', $group);
            $this->assertArrayHasKey('items', $group);
            $this->assertInstanceOf(Heroicon::class, $group['icon']);
            $this->assertNotEmpty($group['items']);
        }
    }

    public function test_every_group_and_item_label_has_english_and_arabic_translations(): void
    {
        foreach (AdminModuleRegistry::groups() as $group) {
            $this->assertNotSame($group['label'], __($group['label'], [], 'en'));
            $this->assertNotSame($group['label'], __($group['label'], [], 'ar'));

            foreach ($group['items'] as $item) {
                $this->assertArrayHasKey('label', $item);
                $this->assertArrayHasKey('link', $item);
                $this->assertNotSame($item['label'], __($item['label'], [], 'en'));
                $this->assertNotSame($item['label'], __($item['label'], [], 'ar'));
            }
        }
    }

    public function test_resolve_link_returns_null_when_the_class_does_not_exist(): void
    {
        $this->assertNull(AdminModuleRegistry::resolveLink('App\\Filament\\Resources\\Nowhere\\NopeResource'));
    }

    public function test_resolve_link_returns_null_for_classes_that_are_not_resources_or_pages(): void
    {
        $this->assertNull(AdminModuleRegistry::resolveLink(\stdClass::class));
    }
}
