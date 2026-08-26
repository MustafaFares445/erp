<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Resources\Quotations\Actions\QuotationActions;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Schemas\QuotationLinesRepeater;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\Sales\QuotationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class EditQuotation extends EditRecord
{
    use InteractsWithSalesServices;

    protected static string $resource = QuotationResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            QuotationActions::send(),
            QuotationActions::recordDecision(),
            QuotationActions::convert(),
            DeleteAction::make(),
        ];
    }

    /**
     * The `lines` repeater is deliberately not `->relationship()` (see
     * {@see QuotationLinesRepeater}),
     * so it must be populated by hand rather than by Filament's automatic
     * relationship fill.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Quotation $record */
        $record = $this->getRecord();

        $lines = $record->lines()->orderBy('sort_order')->get()->map(static fn (QuotationLine $line): array => [
            'product_variant_id' => $line->product_variant_id,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'tax_amount' => $line->tax_amount,
            'description' => $line->description,
        ])->all();

        return [...$data, 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Quotation) {
            throw new Halt;
        }

        $lines = self::normalizeLines($data['lines'] ?? null);

        return self::runSalesOperation(function () use ($record, $data, $lines): Quotation {
            app(QuotationService::class)->update($record, [
                'customer_id' => self::integerFrom($data['customer_id'] ?? null),
                'employee_id' => self::nullableIntegerFrom($data['employee_id'] ?? null),
                'payment_term_id' => self::nullableIntegerFrom($data['payment_term_id'] ?? null),
                'issue_date' => self::stringFrom($data['issue_date'] ?? null),
                'expires_at' => self::nullableStringFrom($data['expires_at'] ?? null),
            ]);

            return app(QuotationService::class)->updateLines($record, $lines);
        });
    }
}
