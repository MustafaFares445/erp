<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\AdminModuleRegistry;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

final class ModulePlaceholder extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.module-placeholder';

    #[Url]
    public string $group = '';

    #[Url]
    public string $item = '';

    /**
     * @var array{group: array{key: string, label: string}, item: array{label: string}}
     */
    protected array $resolved;

    public function mount(): void
    {
        $resolved = AdminModuleRegistry::findItem($this->group, $this->item);

        abort_unless($resolved !== null, 404);
        abort_if(AdminModuleRegistry::isAccessDenied($resolved['item']['link']), 403);

        $this->resolved = $resolved;
    }

    #[\Override]
    public function getTitle(): string
    {
        return __($this->resolved['item']['label']);
    }

    #[\Override]
    public function getBreadcrumbs(): array
    {
        return [
            Dashboard::getUrl() => __('admin.dashboard'),
            __($this->resolved['group']['label']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getViewData(): array
    {
        return [
            'message' => __('admin.empty_module'),
        ];
    }
}
