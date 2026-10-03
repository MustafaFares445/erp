<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs\Pages;

use App\Filament\Resources\PurchaseRfqs\Actions\PurchaseRfqActions;
use App\Filament\Resources\PurchaseRfqs\PurchaseRfqResource;
use App\Models\PurchaseRfq;
use Filament\Resources\Pages\ViewRecord;

final class ViewPurchaseRfq extends ViewRecord
{
    protected static string $resource = PurchaseRfqResource::class;

    #[\Override]
    public function getTitle(): string
    {
        return 'RFQ '.$this->rfq()->rfq_number;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            PurchaseRfqActions::send()->after(fn () => $this->refreshFormData(['status', 'sent_at'])),
            PurchaseRfqActions::recordResponse()->after(fn () => $this->refreshFormData(['status'])),
            PurchaseRfqActions::award(),
            PurchaseRfqActions::openPurchaseOrder(),
            PurchaseRfqActions::close()->after(fn () => $this->refreshFormData(['status', 'closed_at'])),
            PurchaseRfqActions::expire()->after(fn () => $this->refreshFormData(['status', 'expired_at'])),
            PurchaseRfqActions::cancel()->after(fn () => $this->refreshFormData(['status', 'cancelled_at'])),
        ];
    }

    private function rfq(): PurchaseRfq
    {
        $record = $this->getRecord();

        if (! $record instanceof PurchaseRfq) {
            throw new \LogicException('Expected a PurchaseRfq record.');
        }

        return $record;
    }
}
