<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Models\PurchaseAgreement;
use App\Models\User;
use App\Services\Purchasing\PurchaseAgreementService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreatePurchaseAgreement extends CreateRecord
{
    use InteractsWithPurchasingServices;

    protected static string $resource = PurchaseAgreementResource::class;

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
                'unit_price' => self::stringFrom($line['unit_price'] ?? null),
                'minimum_order_quantity' => self::nullableStringFrom($line['minimum_order_quantity'] ?? null),
                'lead_time_days' => is_numeric($line['lead_time_days'] ?? null) ? (int) $line['lead_time_days'] : null,
            ];
        }

        return self::runPurchasingOperation(fn (): PurchaseAgreement => app(PurchaseAgreementService::class)->create(
            $actor,
            [
                'supplier_id' => self::integerFrom($data['supplier_id'] ?? null),
                'currency_code' => self::stringFrom($data['currency_code'] ?? 'AED'),
                'starts_on' => self::stringFrom($data['starts_on'] ?? null),
                'ends_on' => self::nullableStringFrom($data['ends_on'] ?? null),
                'notes' => self::nullableStringFrom($data['notes'] ?? null),
            ],
            $lines,
        ));
    }
}
