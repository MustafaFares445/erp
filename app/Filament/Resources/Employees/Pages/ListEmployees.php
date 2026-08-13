<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\EmployeeProfile;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'default' => Tab::make('Default'),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('is_active', true)),
            'inactive' => Tab::make('Inactive')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('is_active', false)),
            'newly_hired' => Tab::make('Newly Hired')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('created_at', '>=', now()->subDays(30))),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(self::scopeArchived(...)),
        ];
    }

    /**
     * The Archived tab owns the soft-delete scope. Override the default
     * TrashedFilter state there so its blank state cannot add
     * `withoutTrashed()` after the tab's `onlyTrashed()` query.
     *
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function getTableFilterState(string $name): ?array
    {
        if ($this->activeTab === 'archived' && $name === 'trashed') {
            return ['value' => false];
        }

        $state = $this->tableFilters[$this->parseTableFilterName($name)] ?? null;

        if (! is_array($state)) {
            return null;
        }

        $normalizedState = [];

        foreach ($state as $key => $value) {
            if (is_string($key)) {
                $normalizedState[$key] = $value;
            }
        }

        return $normalizedState;
    }

    /**
     * @param  Builder<EmployeeProfile>  $query
     * @return Builder<EmployeeProfile>
     */
    private static function scopeArchived(Builder $query): Builder
    {
        return $query->onlyTrashed();
    }
}
