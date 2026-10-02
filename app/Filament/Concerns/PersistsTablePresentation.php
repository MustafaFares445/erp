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

                return $columns;
            }
        }

        return parent::loadTableColumnsFromSession();
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
