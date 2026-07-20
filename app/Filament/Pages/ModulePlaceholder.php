<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\AdminModuleRegistry;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class ModulePlaceholder extends Page
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

        $this->resolved = $resolved;
    }

    public function getTitle(): string|Htmlable
    {
        return __($this->resolved['item']['label']);
    }

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
    protected function getViewData(): array
    {
        return [
            'message' => __('admin.empty_module'),
        ];
    }
}
