<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Concerns\ExportsSalesDocuments;
use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Widgets\PaymentsOverview;
use App\Models\Payment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ListPayments extends ListRecords
{
    use ExportsSalesDocuments;
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = PaymentResource::class;

    protected function savedTableViewPageKey(): string
    {
        return 'sales.payments';
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->salesDocumentExportAction(),
        ];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [PaymentsOverview::class];
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('admin.sales.payment_tabs.all')),
            'collected_this_month' => Tab::make(__('admin.sales.payment_tabs.collected_this_month'))
                ->badge(Payment::query()->collectedThisMonth()->count())
                ->modifyQueryUsing(self::collectedThisMonthQuery(...)),
            'posted' => Tab::make(__('admin.sales.payment_tabs.posted'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn(
                    'payments.id',
                    Payment::query()->posted()->select('payments.id'),
                )),
            'customer_deposits' => Tab::make(__('admin.sales.payment_tabs.customer_deposits'))
                ->badge(Payment::query()->customerDeposits()->count())
                ->modifyQueryUsing(self::customerDepositsQuery(...)),
            'draft' => Tab::make(__('admin.sales.payment_tabs.draft'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentStatus::Draft->value)),
            'reversed' => Tab::make(__('admin.sales.payment_tabs.reversed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentStatus::Reversed->value)),
        ];
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    private static function collectedThisMonthQuery(Builder $query): Builder
    {
        return $query->collectedThisMonth();
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    private static function customerDepositsQuery(Builder $query): Builder
    {
        return $query->customerDeposits();
    }

    /** @return list<string> */
    private function salesDocumentExportHeadings(): array
    {
        return ['payment_number', 'customer', 'payment_method', 'payment_date', 'amount', 'status', 'posted_at', 'reversed_at'];
    }

    /** @return list<bool|float|int|string|null> */
    private function salesDocumentExportRow(Model $record): array
    {
        if (! $record instanceof Payment) {
            return [];
        }

        return [
            (string) $record->payment_number,
            $record->customer?->company_name,
            $record->paymentMethod?->name,
            $record->payment_date->toDateString(),
            (string) $record->amount,
            $record->status->label(),
            $record->posted_at?->toDateTimeString(),
            $record->reversed_at?->toDateTimeString(),
        ];
    }

    private function salesDocumentExportFilename(): string
    {
        return 'payments-'.now()->format('Ymd-His').'.csv';
    }

    private function salesDocumentExportLogName(): string
    {
        return 'sales.payment.exported';
    }
}
