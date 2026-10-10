<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\UiPreferences\UserUiPreferenceService;

trait PersistsTablePresentation
{
    abstract protected function savedTableViewPageKey(): string;

    /** @return array<int, array<string, mixed>> */
    protected function loadTableColumnsFromSession(): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $stored = app(UserUiPreferenceService::class)->get($user, 'table', $this->tablePresentationPageKey());

            if (is_array($stored['columns'] ?? null)) {
                /** @var array<int, array<string, mixed>> $columns */
                $columns = $stored['columns'];

                return $this->applyChangedColumnDefaults($columns);
            }
        }

        return parent::loadTableColumnsFromSession();
    }

    /**
     * Filament stores the layout on every visit together with each toggleable column's default at that time, and
     * a stored toggle always wins over the column's current default. When the application later changes a default
     * (for example a secondary column becoming hidden by default so the list fits), a toggle stored against the
     * old default falls back to the new one; toggles stored against the current default are the user's choice
     * and stay as they are. The application defines no column groups, so only top-level columns are compared.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array<string, mixed>>
     */
    private function applyChangedColumnDefaults(array $columns): array
    {
        /** @var array<string, bool> $currentDefaults */
        $currentDefaults = collect($this->getDefaultTableColumnState())
            ->filter(static fn (array $column): bool => is_bool($column['isToggledHiddenByDefault'] ?? null))
            ->mapWithKeys(static fn (array $column): array => [$column['name'] => $column['isToggledHiddenByDefault']])
            ->all();

        return array_map(static function (array $column) use ($currentDefaults): array {
            $name = $column['name'] ?? null;

            if (! is_string($name) || ! array_key_exists($name, $currentDefaults) || ($column['isToggledHiddenByDefault'] ?? null) === $currentDefaults[$name]) {
                return $column;
            }

            $column['isToggled'] = ! $currentDefaults[$name];
            $column['isToggledHiddenByDefault'] = $currentDefaults[$name];

            return $column;
        }, $columns);
    }

    protected function persistTableColumns(): void
    {
        parent::persistTableColumns();

        $user = auth()->user();

        if (! $user instanceof User || blank($this->tableColumns)) {
            return;
        }

        app(UserUiPreferenceService::class)->put(
            $user,
            'table',
            $this->tablePresentationPageKey(),
            ['columns' => $this->tableColumns],
        );
    }

    protected function tablePresentationPageKey(): string
    {
        return $this->savedTableViewPageKey();
    }
}
