<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments\Pages;

use App\Enums\BillStatus;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\Bill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use LogicException;

final class ManageSupplierPayments extends ManageRecords
{
    protected static string $resource = SupplierPaymentResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()
            ->fillForm(fn (): array => self::billDefaults())
            ->using(function (array $data, AccountingDocumentService $documents): SupplierPayment {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                return $documents->recordSupplierPayment($actor, self::normalizeData($data));
            })];
    }

    /** @return array<string, mixed> */
    private static function billDefaults(): array
    {
        $billId = request()->query('bill_id');

        if (! is_numeric($billId)) {
            return [];
        }

        $bill = Bill::query()
            ->whereKey((int) $billId)
            ->whereIn('status', [BillStatus::Approved->value, BillStatus::PartiallyPaid->value])
            ->first();

        if (! $bill instanceof Bill || $bill->outstandingAmount() <= 0.0) {
            return [];
        }

        return [
            'supplier_id' => $bill->resolved_supplier_id,
            'amount' => $bill->outstandingAmount(),
            'payment_date' => today()->toDateString(),
            'reference' => 'Bill '.$bill->bill_number,
        ];
    }

    /** @return array<string, mixed> */
    private static function normalizeData(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
