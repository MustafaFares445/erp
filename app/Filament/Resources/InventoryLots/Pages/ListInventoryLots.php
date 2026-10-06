<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\Pages;

use App\Enums\InventoryExportType;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Models\InventoryLot;
use App\Models\InventorySetting;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListInventoryLots extends ListRecords
{
    use RequestsInventoryExports;

    protected static string $resource = InventoryLotResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.lot.list_notice');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [$this->inventoryExportAction(InventoryExportType::ExpiryLots)];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        $windows = InventorySetting::expiryWindows();

        return [
            'all' => Tab::make(__('All')),
            'expired' => Tab::make(__('Expired'))
                ->badge(self::expiryCount(null, -1))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::withPhysicalStock($query)
                    ->whereDate('expires_at', '<', today())),
            'critical' => Tab::make(__('0?30 Days'))
                ->badge(self::expiryCount(0, $windows['critical']))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::withPhysicalStock($query)
                    ->whereDate('expires_at', '>=', today())
                    ->whereDate('expires_at', '<=', today()->addDays($windows['critical']))),
            'warning' => Tab::make(__('31?60 Days'))
                ->badge(self::expiryCount($windows['critical'] + 1, $windows['warning']))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::withPhysicalStock($query)
                    ->whereDate('expires_at', '>', today()->addDays($windows['critical']))
                    ->whereDate('expires_at', '<=', today()->addDays($windows['warning']))),
            'notice' => Tab::make(__('61?90 Days'))
                ->badge(self::expiryCount($windows['warning'] + 1, $windows['notice']))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::withPhysicalStock($query)
                    ->whereDate('expires_at', '>', today()->addDays($windows['warning']))
                    ->whereDate('expires_at', '<=', today()->addDays($windows['notice']))),
        ];
    }

    private static function withPhysicalStock(Builder $query): Builder
    {
        return $query->whereHas(
            'conditionBalances',
            static fn (Builder $balance): Builder => $balance->where('on_hand_base_quantity', '>', 0),
        );
    }

    private static function expiryCount(?int $fromDays, int $toDays): int
    {
        $query = self::withPhysicalStock(InventoryLot::query())->whereNotNull('expires_at');

        if ($toDays < 0) {
            return $query->whereDate('expires_at', '<', today())->count();
        }

        if ($fromDays !== null) {
            $query->whereDate('expires_at', $fromDays === 0 ? '>=' : '>', today()->addDays(max(0, $fromDays - 1)));
        }

        return $query->whereDate('expires_at', '<=', today()->addDays($toDays))->count();
    }
}
