<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Actions;

use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\WarrantyClaimService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use LogicException;

final class WarrantyClaimActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        return [
            self::recordDiagnosis(),
            self::determineCoverage(),
        ];
    }

    private static function recordDiagnosis(): Action
    {
        return Action::make('recordDiagnosis')
            ->label(__('Record Diagnosis'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('primary')
            ->authorize('diagnose')
            ->visible(static fn (MaintenanceRecord $record): bool => self::canAssess($record))
            ->fillForm(static fn (MaintenanceRecord $record): array => [
                'diagnosis_summary' => $record->diagnosis_summary,
                'root_cause' => $record->root_cause,
                'failure_category' => $record->failure_category?->value,
            ])
            ->schema([
                Section::make(__('Technical diagnosis'))
                    ->description(__('Record what the technician found before making any warranty or commercial decision.'))
                    ->schema([
                        Textarea::make('diagnosis_summary')
                            ->label(__('Technician findings'))
                            ->rows(4)
                            ->required(),
                        Textarea::make('root_cause')
                            ->label(__('Root cause'))
                            ->rows(3)
                            ->required(),
                        Select::make('failure_category')
                            ->label(__('Failure category'))
                            ->options(collect(WarrantyFailureCategory::cases())
                                ->mapWithKeys(static fn (WarrantyFailureCategory $category): array => [$category->value => $category->label()]))
                            ->required()
                            ->native(false),
                    ]),
            ])
            ->action(static function (MaintenanceRecord $record, array $data): void {
                try {
                    $data = self::stringKeyedData($data);
                    app(WarrantyClaimService::class)->recordDiagnosis($record, $data, self::currentActor());
                    Notification::make()
                        ->success()
                        ->title(__('Diagnosis recorded'))
                        ->body(__('Warranty eligibility was not changed. Determine repair coverage as the next step.'))
                        ->send();
                } catch (ValidationException $validationException) {
                    Notification::make()->danger()->title(__('Unable to record diagnosis'))->body($validationException->getMessage())->send();
                }
            });
    }

    private static function determineCoverage(): Action
    {
        return Action::make('determineCoverage')
            ->label(__('Determine Coverage'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('primary')
            ->authorize('decideCoverage')
            ->visible(static fn (MaintenanceRecord $record): bool => self::canAssess($record) && $record->diagnosed_at !== null)
            ->fillForm(static function (MaintenanceRecord $record): array {
                $lines = collect(app(WarrantyClaimService::class)->suggestedCoverageLines($record))
                    ->map(static fn (array $line): array => [
                        ...$line,
                        'amount' => round($line['amount_minor'] / 100, 2),
                    ])
                    ->all();

                return [
                    'coverage_decision' => $record->coverage_decision === WarrantyClaimDecision::PendingDiagnosis
                        ? null
                        : $record->coverage_decision->value,
                    'coverage_source' => $record->coverage_source?->value,
                    'coverage_reason' => $record->coverage_reason,
                    'customer_coverage_explanation' => $record->customer_coverage_explanation,
                    'coverage_lines' => $lines,
                ];
            })
            ->schema([
                Section::make(__('Eligibility & diagnosis'))
                    ->columns(2)
                    ->schema([
                        Placeholder::make('eligibility')
                            ->label(__('Warranty eligibility'))
                            ->content(static fn (MaintenanceRecord $record): string => self::eligibilityText($record)),
                        Placeholder::make('diagnosis')
                            ->label(__('Diagnosis'))
                            ->content(static fn (MaintenanceRecord $record): string => $record->diagnosis_summary ?? 'Not recorded'),
                    ]),
                Section::make(__('Coverage decision'))
                    ->description(__('Decide whether this diagnosed failure is actually covered. Warranty eligibility by itself does not make the repair free.'))
                    ->columns(2)
                    ->schema([
                        Select::make('coverage_decision')
                            ->label(__('Decision'))
                            ->options(collect(WarrantyClaimDecision::cases())
                                ->reject(static fn (WarrantyClaimDecision $decision): bool => $decision === WarrantyClaimDecision::PendingDiagnosis)
                                ->mapWithKeys(static fn (WarrantyClaimDecision $decision): array => [$decision->value => $decision->label()]))
                            ->required()
                            ->live()
                            ->native(false),
                        Select::make('coverage_source')
                            ->label(__('Third-party coverage source'))
                            ->options([
                                WarrantyCoverageSource::ManufacturerWarranty->value => WarrantyCoverageSource::ManufacturerWarranty->label(),
                                WarrantyCoverageSource::SupplierWarranty->value => WarrantyCoverageSource::SupplierWarranty->label(),
                            ])
                            ->required(static fn (Get $get): bool => $get('coverage_decision') === WarrantyClaimDecision::ThirdPartyWarranty->value)
                            ->visible(static fn (Get $get): bool => $get('coverage_decision') === WarrantyClaimDecision::ThirdPartyWarranty->value)
                            ->native(false),
                        Textarea::make('coverage_reason')
                            ->label(__('Internal decision reason'))
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('customer_coverage_explanation')
                            ->label(__('Explanation shown to customer'))
                            ->rows(3)
                            ->required(static fn (Get $get): bool => in_array($get('coverage_decision'), [
                                WarrantyClaimDecision::PartiallyCovered->value,
                                WarrantyClaimDecision::Rejected->value,
                            ], true))
                            ->helperText(__('Required when the customer must pay any part of the repair.'))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Partial coverage breakdown'))
                    ->description(__('Review what warranty pays and what the customer pays. Add travel or other service lines if they are not already represented by recorded job costs.'))
                    ->visible(static fn (Get $get): bool => $get('coverage_decision') === WarrantyClaimDecision::PartiallyCovered->value)
                    ->schema([
                        Repeater::make('coverage_lines')
                            ->label(__('Coverage lines'))
                            ->addActionLabel(__('Add cost / service line'))
                            ->defaultItems(0)
                            ->schema([
                                Hidden::make('source_type'),
                                Hidden::make('source_id'),
                                Select::make('category')
                                    ->options(collect(WarrantyLineCategory::cases())
                                        ->mapWithKeys(static fn (WarrantyLineCategory $category): array => [$category->value => $category->label()]))
                                    ->required()
                                    ->native(false),
                                TextInput::make('description')->required(),
                                TextInput::make('amount')
                                    ->label(__('Charge basis'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->required()
                                    ->helperText(__('Amount before warranty coverage and tax.')),
                                TextInput::make('coverage_percent')
                                    ->label(__('Covered %'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.01)
                                    ->required()
                                    ->live(),
                            ])
                            ->columns(4),
                        Placeholder::make('coverage_summary')
                            ->label(__('Coverage summary'))
                            ->content(static fn (Get $get): string => self::coverageLineSummary($get('coverage_lines'))),
                    ]),
            ])
            ->action(static function (MaintenanceRecord $record, array $data): void {
                try {
                    $data = self::stringKeyedData($data);

                    if (($data['coverage_decision'] ?? null) === WarrantyClaimDecision::PartiallyCovered->value) {
                        $coverageLines = [];
                        $rawLines = $data['coverage_lines'] ?? [];

                        if (is_array($rawLines)) {
                            foreach ($rawLines as $rawLine) {
                                if (! is_array($rawLine)) {
                                    continue;
                                }

                                $line = self::stringKeyedData($rawLine);
                                $amount = $line['amount'] ?? 0;
                                $coverageLines[] = [
                                    ...$line,
                                    'amount_minor' => is_numeric($amount) ? (int) round((float) $amount * 100) : 0,
                                    'coverage_source' => WarrantyCoverageSource::SellerWarranty->value,
                                ];
                            }
                        }

                        $data['coverage_lines'] = $coverageLines;
                    } else {
                        unset($data['coverage_lines']);
                    }

                    app(WarrantyClaimService::class)->decideCoverage($record, $data, self::currentActor());
                    Notification::make()
                        ->success()
                        ->title(__('Coverage decision saved'))
                        ->body(__('Customer responsibility and billing options have been recalculated from this decision.'))
                        ->send();
                } catch (ValidationException $validationException) {
                    Notification::make()->danger()->title(__('Unable to save coverage decision'))->body($validationException->getMessage())->send();
                }
            });
    }

    private static function eligibilityText(MaintenanceRecord $record): string
    {
        $expiry = $record->warranty_expiry_date?->toDateString();

        return match ($record->warranty_status) {
            WarrantyStatus::Covered => 'Active'.($expiry !== null ? ' until '.$expiry : ''),
            WarrantyStatus::Expired => 'Expired'.($expiry !== null ? ' on '.$expiry : ''),
            WarrantyStatus::NotCovered => 'No seller warranty',
            WarrantyStatus::NotApplicable => 'Seller warranty not applicable',
            WarrantyStatus::Unknown => 'Needs verification',
        };
    }

    private static function coverageLineSummary(mixed $rawLines): string
    {
        if (! is_array($rawLines) || $rawLines === []) {
            return 'No coverage lines yet.';
        }

        $total = 0.0;
        $covered = 0.0;

        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $amount = is_numeric($line['amount'] ?? null) ? (float) $line['amount'] : 0.0;
            $percent = is_numeric($line['coverage_percent'] ?? null) ? (float) $line['coverage_percent'] : 0.0;
            $total += $amount;
            $covered += $amount * max(0, min(100, $percent)) / 100;
        }

        return sprintf(
            'Repair amount %s · Coverage %s · Customer responsibility %s',
            number_format($total, 2),
            number_format($covered, 2),
            number_format(max(0, $total - $covered), 2),
        );
    }

    private static function canAssess(MaintenanceRecord $record): bool
    {
        return ! $record->isLockedForChanges();
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyedData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        return $actor;
    }
}
