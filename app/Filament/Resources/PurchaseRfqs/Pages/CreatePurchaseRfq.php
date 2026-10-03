<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs\Pages;

use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\PurchaseRfqs\PurchaseRfqResource;
use App\Models\PurchaseRfq;
use App\Models\User;
use App\Services\Purchasing\PurchaseRfqService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreatePurchaseRfq extends CreateRecord
{
    use InteractsWithPurchasingServices;

    protected static string $resource = PurchaseRfqResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = self::purchasingActor();
        if (! $actor instanceof User) {
            throw new Halt;
        }

        $rawLines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $lines = [];
        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = [
                'product_variant_id' => self::integerFrom($line['product_variant_id'] ?? null),
                'unit_id' => self::integerFrom($line['unit_id'] ?? null),
                'quantity' => self::stringFrom($line['quantity'] ?? null),
                'notes' => self::nullableStringFrom($line['notes'] ?? null),
            ];
        }

        $rawSupplierIds = is_array($data['supplier_ids'] ?? null) ? $data['supplier_ids'] : [];
        $supplierIds = array_values(array_map(static fn (mixed $id): int => (int) $id, array_filter($rawSupplierIds, is_numeric(...))));

        return self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->create(
            $actor,
            [
                'currency_code' => self::stringFrom($data['currency_code'] ?? 'AED'),
                'needed_by' => self::nullableStringFrom($data['needed_by'] ?? null),
                'closes_at' => self::nullableStringFrom($data['closes_at'] ?? null),
                'notes' => self::nullableStringFrom($data['notes'] ?? null),
            ],
            $lines,
            $supplierIds,
        ));
    }
}
