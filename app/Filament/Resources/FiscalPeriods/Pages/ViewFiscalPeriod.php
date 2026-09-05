<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Pages;

use App\Filament\Resources\FiscalPeriods\Actions\FiscalPeriodActions;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Models\FiscalPeriod;
use App\Services\Accounting\PeriodCloseChecklistService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ViewFiscalPeriod extends ViewRecord
{
    protected static string $resource = FiscalPeriodResource::class;

    /** @return array<int, mixed> */
    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (FiscalPeriod $record): bool => ! $record->is_closed),
            FiscalPeriodActions::runChecklist(),
            FiscalPeriodActions::close(),
            FiscalPeriodActions::reopen(),
            Action::make('export_checklist')
                ->label(__('admin.accounting.actions.export_checklist'))
                ->action(fn (FiscalPeriod $record): StreamedResponse => $this->exportChecklistCsv($record)),
        ];
    }

    private function exportChecklistCsv(FiscalPeriod $record): StreamedResponse
    {
        $rows = app(PeriodCloseChecklistService::class)->statusRows($record);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['check', 'mandatory', 'passed', 'measured_at', 'detail'], escape: '\\');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['check']->label(),
                    $row['mandatory'] ? 'yes' : 'no',
                    match ($row['passed']) {
                        true => 'yes',
                        false => 'no',
                        null => 'not run',
                    },
                    $row['measured_at']?->toDateTimeString() ?? '',
                    $row['detail'] !== null ? json_encode($row['detail']) : '',
                ], escape: '\\');
            }

            fclose($handle);
        }, sprintf('fiscal-period-%d-close-checklist.csv', (int) $record->getKey()), ['Content-Type' => 'text/csv']);
    }
}
