<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Schemas;

use App\Models\FiscalPeriod;
use App\Services\Accounting\PeriodCloseChecklistService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class FiscalPeriodInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('name')->label(__('admin.accounting.fields.period_name')),
                TextEntry::make('starts_at')->label(__('admin.accounting.fields.starts_at'))->date(),
                TextEntry::make('ends_at')->label(__('admin.accounting.fields.ends_at'))->date(),
                TextEntry::make('is_closed')
                    ->label(__('admin.accounting.fields.is_closed'))
                    ->badge()
                    ->formatStateUsing(static fn (bool $state): string => $state ? 'Closed' : 'Open')
                    ->color(static fn (bool $state): string => $state ? 'danger' : 'success'),
                TextEntry::make('closedBy.name')->label(__('admin.accounting.fields.closed_by'))->placeholder('—'),
                TextEntry::make('closed_at')->label(__('admin.accounting.fields.closed_at'))->dateTime()->placeholder('—'),
                TextEntry::make('closeOverrideBy.name')->label(__('admin.accounting.fields.close_override_by'))->placeholder('—'),
                TextEntry::make('close_override_reason')
                    ->label(__('admin.accounting.fields.close_override_reason'))
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]),
            Section::make(__('admin.accounting.fields.close_checklist'))
                ->schema([
                    RepeatableEntry::make('close_checklist_rows')
                        ->label('')
                        ->columns(3)
                        ->state(fn (FiscalPeriod $record): array => self::checklistRows($record))
                        ->schema([
                            TextEntry::make('label')->label(__('admin.accounting.fields.checklist_check')),
                            IconEntry::make('passed')
                                ->label(__('admin.accounting.fields.checklist_status'))
                                ->boolean()
                                ->trueIcon(Heroicon::OutlinedCheckCircle)
                                ->falseIcon(Heroicon::OutlinedXCircle),
                            TextEntry::make('measured_at')
                                ->label(__('admin.accounting.fields.checklist_measured_at'))
                                ->placeholder('Not yet run'),
                        ]),
                ]),
        ]);
    }

    /** @return list<array{label: string, passed: bool, measured_at: ?string}> */
    private static function checklistRows(FiscalPeriod $record): array
    {
        $rows = app(PeriodCloseChecklistService::class)->statusRows($record);

        return array_map(static function (array $row): array {
            $label = $row['check']->label();

            if (! $row['mandatory']) {
                $label .= ' (advisory)';
            }

            return [
                'label' => $label,
                'passed' => $row['passed'] ?? false,
                'measured_at' => $row['measured_at']?->toDateTimeString(),
            ];
        }, $rows);
    }
}
