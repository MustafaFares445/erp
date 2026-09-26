<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Support\QuotationLinePriceFloorApprovals;
use App\Models\Quotation;
use App\Services\Sales\QuotationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Delegates to {@see QuotationService} so every line's default price and
 * tax, and the document totals, are resolved the same way regardless of
 * which surface created the quotation (FR-015, FR-017, FR-018).
 */
final class CreateQuotation extends CreateRecord
{
    use InteractsWithSalesServices;

    protected static string $resource = QuotationResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return self::runSalesOperation(function () use ($data): Quotation {
            $rawLines = is_array($data['lines'] ?? null)
                ? app(QuotationLinePriceFloorApprovals::class)->resolve(
                    $data['lines'],
                    $data['customer_id'] ?? null,
                    self::salesActor(),
                )
                : [];

            return app(QuotationService::class)->create([
                'customer_id' => self::integerFrom($data['customer_id'] ?? null),
                'employee_id' => self::nullableIntegerFrom($data['employee_id'] ?? null),
                'payment_term_id' => self::nullableIntegerFrom($data['payment_term_id'] ?? null),
                'issue_date' => self::stringFrom($data['issue_date'] ?? null),
                'expires_at' => self::nullableStringFrom($data['expires_at'] ?? null),
            ], self::normalizeLines($rawLines));
        });
    }
}
