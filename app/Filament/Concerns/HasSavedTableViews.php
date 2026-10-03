<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\DashboardRole;
use App\Enums\SystemPermission;
use App\Models\SavedTableView;
use App\Models\TableViewPreference;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Livewire\Attributes\Url;

trait HasSavedTableViews
{
    public const SAVED_TABLE_VIEW_STATE_VERSION = 1;

    #[Url(as: 'view')]
    public ?int $activeSavedTableView = null;

    public bool $savedTableViewInitialized = false;

    abstract protected function savedTableViewPageKey(): string;

    public function bootedInteractsWithTable(): void
    {
        parent::bootedInteractsWithTable();

        if ($this->savedTableViewInitialized) {
            return;
        }

        $viewId = $this->activeSavedTableView ?? $this->defaultSavedTableViewId();

        if ($viewId !== null) {
            $view = $this->visibleSavedTableViews()->whereKey($viewId)->first();

            if ($view instanceof SavedTableView) {
                $this->applySavedTableView($view);
            }
        }

        $this->savedTableViewInitialized = true;
    }

    protected function loadSavedTableViewAction(): Action
    {
        return Action::make('loadSavedTableView')
            ->label(__('Saved views'))
            ->icon('heroicon-o-bookmark-square')
            ->schema([
                Select::make('view_id')
                    ->label(__('View'))
                    ->options(fn (): array => $this->visibleSavedTableViews()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $view = $this->visibleSavedTableViews()->whereKey($this->savedTableViewId($data))->firstOrFail();
                $this->activeSavedTableView = $view->id;
                $this->applySavedTableView($view);
            });
    }

    protected function saveSavedTableViewAction(): Action
    {
        return Action::make('saveSavedTableView')
            ->label(__('Save view'))
            ->icon('heroicon-o-bookmark')
            ->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(120),
                Toggle::make('is_public')
                    ->label(__('Share with other dashboard users'))
                    ->visible(fn (): bool => $this->canShareSavedTableViews()),
            ])
            ->action(function (array $data): void {
                $user = $this->savedTableViewUser();

                $view = SavedTableView::query()->create([
                    'user_id' => $user->getKey(),
                    'page_key' => $this->savedTableViewPageKey(),
                    'name' => $this->savedTableViewName($data),
                    'is_public' => $this->canShareSavedTableViews() && (($data['is_public'] ?? false) === true),
                    'state' => $this->captureSavedTableViewState(),
                    'state_version' => self::SAVED_TABLE_VIEW_STATE_VERSION,
                ]);

                $this->activeSavedTableView = $view->id;

                Notification::make()->title(__('View saved'))->success()->send();
            });
    }

    protected function replaceSavedTableViewAction(): Action
    {
        return Action::make('replaceSavedTableView')
            ->label(__('Update saved view'))
            ->icon('heroicon-o-arrow-path')
            ->schema([
                Select::make('view_id')
                    ->label(__('Owned view'))
                    ->options(fn (): array => $this->ownedSavedTableViews()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $view = $this->ownedSavedTableViews()->whereKey($this->savedTableViewId($data))->firstOrFail();
                $view->update([
                    'state' => $this->captureSavedTableViewState(),
                    'state_version' => self::SAVED_TABLE_VIEW_STATE_VERSION,
                ]);
                $this->activeSavedTableView = $view->id;
                Notification::make()->title(__('Saved view updated'))->success()->send();
            });
    }

    protected function deleteSavedTableViewAction(): Action
    {
        return Action::make('deleteSavedTableView')
            ->label(__('Delete saved view'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->schema([
                Select::make('view_id')
                    ->label(__('Owned view'))
                    ->options(fn (): array => $this->ownedSavedTableViews()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $view = $this->ownedSavedTableViews()->whereKey($this->savedTableViewId($data))->firstOrFail();

                TableViewPreference::query()
                    ->where('page_key', $this->savedTableViewPageKey())
                    ->where('view_type', 'saved')
                    ->where('view_key', (string) $view->id)
                    ->delete();

                if ($this->activeSavedTableView === $view->id) {
                    $this->activeSavedTableView = null;
                }

                $view->delete();

                Notification::make()->title(__('Saved view deleted'))->success()->send();
            });
    }

    protected function setDefaultSavedTableViewAction(): Action
    {
        return Action::make('setDefaultSavedTableView')
            ->label(__('Set default view'))
            ->icon('heroicon-o-star')
            ->schema([
                Select::make('view_id')
                    ->label(__('View'))
                    ->options(fn (): array => $this->visibleSavedTableViews()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $user = $this->savedTableViewUser();
                $view = $this->visibleSavedTableViews()->whereKey($this->savedTableViewId($data))->firstOrFail();

                TableViewPreference::query()
                    ->where('user_id', $user->getKey())
                    ->where('page_key', $this->savedTableViewPageKey())
                    ->update(['is_default' => false]);

                TableViewPreference::query()->updateOrCreate(
                    [
                        'user_id' => $user->getKey(),
                        'page_key' => $this->savedTableViewPageKey(),
                        'view_type' => 'saved',
                        'view_key' => (string) $view->id,
                    ],
                    ['is_default' => true, 'is_favorite' => true],
                );

                Notification::make()->title(__('Default view updated'))->success()->send();
            });
    }

    /** @return Builder<SavedTableView> */
    private function visibleSavedTableViews(): Builder
    {
        $user = $this->savedTableViewUser();

        return SavedTableView::query()
            ->where('page_key', $this->savedTableViewPageKey())
            ->where(static function (Builder $query) use ($user): void {
                $query->where('user_id', $user->getKey())->orWhere('is_public', true);
            });
    }

    /** @return Builder<SavedTableView> */
    private function ownedSavedTableViews(): Builder
    {
        $user = $this->savedTableViewUser();

        return SavedTableView::query()
            ->where('page_key', $this->savedTableViewPageKey())
            ->where('user_id', $user->getKey());
    }

    private function defaultSavedTableViewId(): ?int
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $viewKey = TableViewPreference::query()
            ->where('user_id', $user->getKey())
            ->where('page_key', $this->savedTableViewPageKey())
            ->where('view_type', 'saved')
            ->where('is_default', true)
            ->value('view_key');

        return is_numeric($viewKey) ? (int) $viewKey : null;
    }

    /** @return array<string, mixed> */
    private function captureSavedTableViewState(): array
    {
        return [
            'preset_tab' => $this->activeTab,
            'tableFilters' => $this->tableFilters,
            'tableGrouping' => $this->tableGrouping,
            'tableSearch' => $this->tableSearch,
            'tableColumnSearches' => $this->tableColumnSearches,
            'tableSort' => $this->tableSort,
            'tableRecordsPerPage' => $this->tableRecordsPerPage,
        ];
    }

    private function applySavedTableView(SavedTableView $view): void
    {
        if ($view->state_version !== self::SAVED_TABLE_VIEW_STATE_VERSION) {
            Notification::make()
                ->title(__('This saved view was created by an unsupported version.'))
                ->warning()
                ->send();

            return;
        }

        $state = $view->state;

        $tabs = $this->getTabs();
        $presetTab = $state['preset_tab'] ?? null;
        $defaultActiveTab = $this->getDefaultActiveTab();
        $this->activeTab = is_string($presetTab) && array_key_exists($presetTab, $tabs)
            ? $presetTab
            : ($defaultActiveTab === null ? null : (string) $defaultActiveTab);

        $allowedFilterKeys = array_keys($this->getTable()->getFilters());
        $filterState = $state['tableFilters'] ?? null;
        /** @var array<string, mixed> $filters */
        $filters = is_array($filterState)
            ? Arr::only($filterState, $allowedFilterKeys)
            : [];

        // Filling the form first lets filters missing from older saved views
        // (e.g. a query builder added later) start from their defaults.
        $this->getTableFiltersForm()->fill($filters);

        if ($this->getTable()->hasDeferredFilters()) {
            $this->tableFilters = $this->tableDeferredFilters;
        }

        $this->tableGrouping = is_string($state['tableGrouping'] ?? null)
            ? $state['tableGrouping']
            : null;

        $this->tableSearch = is_string($state['tableSearch'] ?? null)
            ? mb_substr($state['tableSearch'], 0, 250)
            : '';

        $columnSearches = $state['tableColumnSearches'] ?? [];
        /** @var array<string, array<string, string|null>|string|null> $normalizedColumnSearches */
        $normalizedColumnSearches = is_array($columnSearches) ? $columnSearches : [];
        $this->tableColumnSearches = $normalizedColumnSearches;

        $this->tableSort = is_string($state['tableSort'] ?? null)
            ? mb_substr($state['tableSort'], 0, 255)
            : null;

        $perPage = $state['tableRecordsPerPage'] ?? null;
        if (is_numeric($perPage) && (int) $perPage >= 5 && (int) $perPage <= 200) {
            $this->tableRecordsPerPage = (int) $perPage;
        }

        $this->resetPage();
    }

    /** @param array<array-key, mixed> $data */
    private function savedTableViewId(array $data): int
    {
        /** @var int|numeric-string $viewId */
        $viewId = $data['view_id'];

        return (int) $viewId;
    }

    /** @param array<array-key, mixed> $data */
    private function savedTableViewName(array $data): string
    {
        /** @var string $name */
        $name = $data['name'];

        return mb_trim($name);
    }

    private function canShareSavedTableViews(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->can(SystemPermission::TableViewShare->value)
                || ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())));
    }

    private function savedTableViewUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
