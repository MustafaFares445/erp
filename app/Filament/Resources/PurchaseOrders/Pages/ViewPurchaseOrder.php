<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * The order's full record: header, approval trail, lines, receipts,
 * confirmations, and — for a user holding `purchase.audit.view` — its audit
 * trail (FR-054).
 */
final class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('print')
                ->label(__('admin.purchasing.actions.print'))
                ->icon(Heroicon::Printer)
                ->url(fn (PurchaseOrder $record): string => route('admin.purchase-orders.print', $record))
                ->openUrlInNewTab(),
            PurchaseOrderActions::submit(),
            PurchaseOrderActions::approve(),
            PurchaseOrderActions::reject(),
            PurchaseOrderActions::send(),
            PurchaseOrderActions::close(),
            PurchaseOrderActions::cancel(),
        ];
    }

    /**
     * Every logged transition on this order, newest first.
     *
     * Read from the shared activity log rather than a purchasing-specific table,
     * per ADR 0005. Gated on `viewAudit` so the audit trail follows the same
     * permission boundary as the reports.
     *
     * @return list<array{event: string, causer: string, at: string}>
     */
    public function auditTrail(): array
    {
        $record = $this->getRecord();
        $actor = auth()->user();

        if (! $record instanceof PurchaseOrder) {
            return [];
        }

        if (! $actor instanceof User || ! $actor->can('viewAudit', $record)) {
            return [];
        }

        $entries = AuditLog::query()
            ->where('subject_type', PurchaseOrder::class)
            ->where('subject_id', $record->getKey())
            ->latest('id')
            ->get();

        $trail = [];

        foreach ($entries as $entry) {
            $causer = $entry->causer;

            $trail[] = [
                'event' => (string) $entry->description,
                'causer' => $causer instanceof User ? $causer->name : '—',
                'at' => (string) $entry->created_at?->toDateTimeString(),
            ];
        }

        return $trail;
    }
}
