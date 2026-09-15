<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            OrderActions::confirm(),
            OrderActions::release(),
            OrderActions::shortClose(),
            OrderActions::close(),
            OrderActions::cancel(),
            EditAction::make()
                ->visible(fn (): bool => $this->record instanceof Order && $this->record->status === OrderStatus::Draft),
        ];
    }
}
