<?php

namespace App\Filament\Pages;

use App\Filament\AdminModuleRegistry;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

class Modules extends BaseDashboard
{
    protected string $view = 'filament.pages.modules';

    public static function getNavigationLabel(): string
    {
        return __('admin.modules');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.modules');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'description' => __('admin.modules_description'),
            'groups' => $this->resolveGroups(),
        ];
    }

    /**
     * @return Collection<int, array{key: string, label: string, icon: mixed, items: Collection<int, array{label: string, url: string}>}>
     */
    protected function resolveGroups(): Collection
    {
        return collect(AdminModuleRegistry::groups())
            ->map(fn (array $group): array => [
                'key' => $group['key'],
                'label' => __($group['label']),
                'icon' => $group['icon'],
                'items' => collect($group['items'])
                    ->map(fn (array $item): array => [
                        'label' => __($item['label']),
                        'url' => AdminModuleRegistry::resolveLink($item['link']),
                    ])
                    ->filter(fn (array $item): bool => filled($item['url']))
                    ->values(),
            ])
            ->values();
    }
}
