<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryExports\Pages;

use App\Filament\Resources\InventoryExports\InventoryExportResource;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Component;

final class ManageInventoryExports extends ManageRecords
{
    protected static string $resource = InventoryExportResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->requestAction('stock_levels', 'Request stock-level export', [
                Select::make('warehouse_id')->options(Warehouse::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
            ]),
            $this->requestAction('movements', 'Request movement export', [
                Select::make('warehouse_id')->options(Warehouse::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                Select::make('movement_type')->options([
                    'sale' => 'Sale', 'return' => 'Return', 'adjustment' => 'Adjustment', 'transfer' => 'Transfer', 'reservation' => 'Reservation', 'receipt' => 'Receipt',
                ]),
                DatePicker::make('from'),
                DatePicker::make('until'),
            ]),
        ];
    }

    /** @param array<int, Component> $schema */
    private function requestAction(string $type, string $label, array $schema): Action
    {
        return Action::make('request_'.$type)
            ->label($label)
            ->form($schema)
            ->action(
                /** @param array<string, mixed> $data */
                function (array $data) use ($type): void {
                    $actor = auth()->user();

                    if ($actor instanceof User) {
                        $filters = [];

                        foreach ($data as $key => $value) {
                            if (is_string($key)) {
                                $filters[$key] = $value;
                            }
                        }

                        app(InventoryExportService::class)->request($type, $filters, $actor);
                    }
                },
            );
    }
}
