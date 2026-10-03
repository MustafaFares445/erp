<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Concerns\Favoritable;
use App\Models\SavedTableView;
use App\Models\TableViewPreference;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Renders a list page's preset tabs, the "Starred" view and the user's saved
 * views as one tab bar inside the table card, with the saved-view
 * management actions behind a "⋮" menu at its end. Pages define their own
 * first ("all") preset in getTabs(), so Starred never becomes the default.
 *
 * Own saved views appear in the bar unless the user hides them; views shared
 * by other users appear once the user chooses to show them. Both choices are
 * stored as `is_favorite` on {@see TableViewPreference}.
 */
trait HasTableViewTabs
{
    use HasSavedTableViews;

    public const STARRED_TAB = 'starred';

    private ?ActionGroup $tableViewMenu = null;

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->header(fn (): View => view('filament.tables.view-tabs', ['page' => $this]));
    }

    /**
     * The tab bar is rendered inside the table card instead.
     */
    #[\Override]
    public function getTabsContentComponent(): Component
    {
        return parent::getTabsContentComponent()->hidden();
    }

    /** @return array<string|int, Tab> */
    #[\Override]
    public function getCachedTabs(): array
    {
        if (isset($this->cachedTabs)) {
            return $this->cachedTabs;
        }

        $tabs = parent::getCachedTabs();

        if (is_a($this->getModel(), Favoritable::class, true) && ! array_key_exists(self::STARRED_TAB, $tabs)) {
            $tabs[self::STARRED_TAB] = Tab::make(__('Starred'))
                ->icon(Heroicon::Star)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereHas(
                    'favorites',
                    static fn (Builder $favorite): Builder => $favorite->where('user_id', auth()->id()),
                ));
        }

        return $this->cachedTabs = $tabs;
    }

    public function selectTableView(string $type, string $key): void
    {
        if ($type === 'saved') {
            $view = $this->visibleSavedTableViews()->whereKey((int) $key)->firstOrFail();
            $this->activeSavedTableView = $view->id;
            $this->applySavedTableView($view);

            return;
        }

        abort_unless(array_key_exists($key, $this->getCachedTabs()), 404);

        if ($this->activeSavedTableView !== null) {
            $this->activeSavedTableView = null;
            $this->resetTableFiltersForm();
            $this->resetTableSearch();
            $this->resetTableColumnSearches();
            $this->tableSort = null;
        }

        $this->activeTab = $key;
        $this->updatedActiveTab();
    }

    /** @return Collection<int, SavedTableView> */
    public function getTabBarSavedTableViews(): Collection
    {
        $user = $this->savedTableViewUser();

        $preferences = TableViewPreference::query()
            ->where('user_id', $user->getKey())
            ->where('page_key', $this->savedTableViewPageKey())
            ->where('view_type', 'saved')
            ->pluck('is_favorite', 'view_key');

        return $this->visibleSavedTableViews()
            ->orderBy('name')
            ->get()
            ->filter(static function (SavedTableView $view) use ($preferences, $user): bool {
                $shown = $preferences->get((string) $view->id);

                return is_bool($shown) ? $shown : $view->user_id === $user->getKey();
            })
            ->values();
    }

    public function getTableViewMenu(): ActionGroup
    {
        if ($this->tableViewMenu instanceof ActionGroup) {
            return $this->tableViewMenu;
        }

        $menu = ActionGroup::make([
            $this->saveSavedTableViewAction(),
            $this->replaceSavedTableViewAction(),
            $this->setDefaultSavedTableViewAction(),
            $this->manageTableViewTabsAction(),
            $this->deleteSavedTableViewAction(),
        ])
            ->label(__('View options'))
            ->icon(Heroicon::EllipsisVertical)
            ->iconButton()
            ->color('gray')
            ->dropdownPlacement('bottom-end')
            ->livewire($this);

        /** @var array<string, Action> $actions */
        $actions = $menu->getFlatActions();
        $this->mergeCachedActions($actions);

        return $this->tableViewMenu = $menu;
    }

    protected function manageTableViewTabsAction(): Action
    {
        return Action::make('manageTableViewTabs')
            ->label(__('Show views in tab bar'))
            ->icon(Heroicon::OutlinedEye)
            ->fillForm(fn (): array => ['view_ids' => $this->getTabBarSavedTableViews()->pluck('id')->all()])
            ->schema([
                CheckboxList::make('view_ids')
                    ->label(__('Saved views'))
                    ->options(fn (): array => $this->visibleSavedTableViews()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->action(function (array $data): void {
                $user = $this->savedTableViewUser();
                $selected = collect(is_array($data['view_ids'] ?? null) ? $data['view_ids'] : [])
                    ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0);

                foreach ($this->visibleSavedTableViews()->get(['id']) as $view) {
                    TableViewPreference::query()->updateOrCreate(
                        [
                            'user_id' => $user->getKey(),
                            'page_key' => $this->savedTableViewPageKey(),
                            'view_type' => 'saved',
                            'view_key' => (string) $view->id,
                        ],
                        ['is_favorite' => $selected->contains($view->id)],
                    );
                }

                Notification::make()->title(__('Tab bar updated'))->success()->send();
            });
    }
}
