<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\RelationManagers;

use App\Enums\CalibrationResult;
use App\Enums\MaintenanceKind;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentCalibrationMeasurement;
use App\Models\MaintenanceRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Support\CalibrationProgressResolver;
use App\Services\Support\EquipmentCalibrationEvidenceService;
use App\Services\Support\EquipmentCalibrationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Fluent;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * One workspace for calibration measurements, result, certificate and next
 * due date, shown only on Calibration-kind maintenance requests.
 */
final class CalibrationRelationManager extends RelationManager
{
    protected static string $relationship = 'calibration';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof MaintenanceRecord
            && $ownerRecord->maintenance_kind === MaintenanceKind::Calibration
            && (bool) config('support.calibration_enabled', true);
    }

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Calibration');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): Htmlable => $this->progressDescription())
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['performedBy.user', 'externalProvider', 'measurements', 'media']))
            ->columns([
                TextColumn::make('result')
                    ->label(__('Result'))
                    ->badge()
                    ->placeholder(__('In progress'))
                    ->description(static fn (EquipmentCalibration $record): ?string => $record->result === CalibrationResult::Failed
                        ? $record->failure_reason
                        : $record->calibrated_at?->toDayDateTimeString()),
                TextColumn::make('measurements_summary')
                    ->label(__('Measurements'))
                    ->state(static fn (EquipmentCalibration $record): string => $record->measurements->filter(
                        static fn (EquipmentCalibrationMeasurement $measurement): bool => $measurement->isRecorded(),
                    )->count().' / '.$record->measurements->count()),
                TextColumn::make('certificate_number')
                    ->label(__('Certificate'))
                    ->placeholder(__('Not issued'))
                    ->description(static fn (EquipmentCalibration $record): ?string => $record->certificate_expires_on instanceof Carbon
                        ? self::t('Expires :date', ['date' => $record->certificate_expires_on->toFormattedDateString()])
                        : null),
                TextColumn::make('next_calibration_due_on')->label(__('Next calibration due'))->date()->placeholder(__('—')),
                TextColumn::make('performedBy.user.name')->label(__('Technician'))->placeholder(__('—')),
                TextColumn::make('externalProvider.name')->label(__('External provider'))->placeholder(__('—')),
                TextColumn::make('standard_reference')->label(__('Standard reference'))->placeholder(__('—'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('instrument_reference')->label(__('Instrument reference'))->placeholder(__('—'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')->label(__('Notes'))->placeholder(__('—'))->limit(60),
                TextColumn::make('evidence_count')
                    ->label(__('Evidence'))
                    ->state(static fn (EquipmentCalibration $record): int => $record->media->count()),
            ])
            ->headerActions([
                Action::make('startCalibration')
                    ->label(__('Start Calibration'))
                    ->fillForm(static fn (): array => ['measurements' => [['label' => '', 'is_required' => true]]])
                    ->schema([
                        Repeater::make('measurements')
                            ->label(__('Measurements'))
                            ->minItems(1)
                            ->schema([
                                TextInput::make('label')->label(__('Measurement'))->required()->maxLength(255),
                                TextInput::make('expected_value')->label(__('Expected'))->numeric(),
                                TextInput::make('minimum_value')->label(__('Minimum'))->numeric(),
                                TextInput::make('maximum_value')->label(__('Maximum'))->numeric(),
                                TextInput::make('unit')->label(__('Unit'))->maxLength(30),
                                Toggle::make('is_required')->label(__('Mandatory'))->default(true),
                            ])
                            ->columns(6),
                        Select::make('external_provider_id')
                            ->label(__('External provider'))
                            ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        TextInput::make('standard_reference')->label(__('Standard reference'))->maxLength(255),
                        TextInput::make('instrument_reference')->label(__('Instrument reference'))->maxLength(255),
                        Textarea::make('notes')->label(__('Notes')),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('create', EquipmentCalibration::class))
                    ->visible(fn (): bool => ! EquipmentCalibration::query()->where('maintenance_record_id', $this->maintenanceRecord()->getKey())->exists())
                    ->action(fn (array $data) => $this->run(fn () => app(EquipmentCalibrationService::class)->start(
                        $this->maintenanceRecord(),
                        self::currentActor(),
                        [
                            'measurements' => is_array($data['measurements'] ?? null) ? array_values(array_filter($data['measurements'], is_array(...))) : [],
                            'external_provider_id' => is_numeric($data['external_provider_id'] ?? null) ? (int) $data['external_provider_id'] : null,
                            'standard_reference' => is_string($data['standard_reference'] ?? null) ? $data['standard_reference'] : null,
                            'instrument_reference' => is_string($data['instrument_reference'] ?? null) ? $data['instrument_reference'] : null,
                            'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : null,
                        ],
                    ))),
            ])
            ->recordActions([
                Action::make('recordMeasurements')
                    ->label(__('Record Measurements'))
                    ->fillForm(static fn (EquipmentCalibration $record): array => [
                        'measurements' => $record->measurements->map(static fn (EquipmentCalibrationMeasurement $measurement): array => [
                            'measurement_key' => $measurement->measurement_key,
                            'label' => $measurement->label,
                            'limits' => self::limitsLabel($measurement),
                            'actual_value' => $measurement->actual_value,
                            'notes' => $measurement->notes,
                        ])->all(),
                    ])
                    ->schema([
                        Repeater::make('measurements')
                            ->label(__('Measurements'))
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                Hidden::make('measurement_key'),
                                TextInput::make('label')->label(__('Measurement'))->disabled()->dehydrated(),
                                TextInput::make('limits')->label(__('Limits'))->disabled()->dehydrated(false),
                                TextInput::make('actual_value')->label(__('Actual'))->numeric(),
                                TextInput::make('notes')->label(__('Notes')),
                            ])
                            ->columns(4),
                    ])
                    ->authorize(fn (EquipmentCalibration $record): bool => self::currentActor()->can('update', $record))
                    ->visible(static fn (EquipmentCalibration $record): bool => ! $record->isFinished())
                    ->action(fn (EquipmentCalibration $record, array $data) => $this->run(function () use ($record, $data): void {
                        collect(is_array($data['measurements'] ?? null) ? $data['measurements'] : [])->each(function (mixed $row) use ($record): void {
                            $input = new Fluent((array) $row);

                            if (! is_numeric($input->get('actual_value'))) {
                                return;
                            }

                            app(EquipmentCalibrationService::class)->recordMeasurement(
                                $record,
                                $input->string('measurement_key')->toString(),
                                $input->string('actual_value')->toString(),
                                self::currentActor(),
                                $input->string('notes')->toString() ?: null,
                            );
                        });
                    })),
                Action::make('completeCalibration')
                    ->label(__('Complete Calibration'))
                    ->schema([
                        Select::make('result')
                            ->label(__('Result'))
                            ->options([
                                CalibrationResult::Passed->value => CalibrationResult::Passed->label(),
                                CalibrationResult::PassedWithAdjustment->value => CalibrationResult::PassedWithAdjustment->label(),
                            ])
                            ->default(CalibrationResult::Passed->value)
                            ->required(),
                        DatePicker::make('next_calibration_due_on')
                            ->label(__('Next calibration due'))
                            ->helperText(__('Leave empty to follow the equipment calibration schedule.')),
                    ])
                    ->authorize(fn (EquipmentCalibration $record): bool => self::currentActor()->can('complete', $record))
                    ->visible(static fn (EquipmentCalibration $record): bool => ! $record->isFinished())
                    ->action(fn (EquipmentCalibration $record, array $data) => $this->run(
                        fn () => app(EquipmentCalibrationService::class)->complete(
                            $record,
                            self::currentActor(),
                            CalibrationResult::tryFrom(is_string($data['result'] ?? null) ? $data['result'] : '') ?? CalibrationResult::Passed,
                            null,
                            is_string($data['next_calibration_due_on'] ?? null) && $data['next_calibration_due_on'] !== '' ? Carbon::parse($data['next_calibration_due_on']) : null,
                        ),
                    )),
                Action::make('failCalibration')
                    ->label(__('Fail Calibration'))
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')->label(__('Reason'))->required(),
                        Toggle::make('raise_follow_up')
                            ->label(__('Raise a follow-up repair request'))
                            ->default(true)
                            ->visible(static fn (): bool => self::currentActor()->can('create', MaintenanceRecord::class)),
                    ])
                    ->authorize(fn (EquipmentCalibration $record): bool => self::currentActor()->can('complete', $record))
                    ->visible(static fn (EquipmentCalibration $record): bool => ! $record->isFinished())
                    ->action(fn (EquipmentCalibration $record, array $data) => $this->run(
                        fn () => app(EquipmentCalibrationService::class)->fail(
                            $record,
                            self::currentActor(),
                            is_string($data['reason'] ?? null) ? $data['reason'] : '',
                            (bool) ($data['raise_follow_up'] ?? false),
                        ),
                    )),
                Action::make('issueCertificate')
                    ->label(__('Issue Certificate'))
                    ->schema([
                        TextInput::make('certificate_number')->label(__('Certificate number'))->required()->maxLength(100),
                        DatePicker::make('certificate_expires_on')->label(__('Certificate expires on')),
                    ])
                    ->authorize(fn (EquipmentCalibration $record): bool => self::currentActor()->can('complete', $record))
                    ->visible(static fn (EquipmentCalibration $record): bool => $record->result instanceof CalibrationResult && $record->result->isSuccessful() && ! $record->hasCertificate())
                    ->action(fn (EquipmentCalibration $record, array $data) => $this->run(
                        fn () => app(EquipmentCalibrationService::class)->issueCertificate(
                            $record,
                            self::currentActor(),
                            is_string($data['certificate_number'] ?? null) ? $data['certificate_number'] : '',
                            is_string($data['certificate_expires_on'] ?? null) && $data['certificate_expires_on'] !== '' ? Carbon::parse($data['certificate_expires_on']) : null,
                        ),
                    )),
                $this->evidenceAction('uploadCertificate', __('Upload Certificate'), EquipmentCalibration::MEDIA_CERTIFICATES),
                $this->evidenceAction('uploadCalibrationEvidence', __('Upload Evidence'), EquipmentCalibration::MEDIA_EVIDENCE),
                Action::make('viewEvidence')
                    ->label(__('View Evidence'))
                    ->modalHeading(__('Calibration evidence'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->authorize(fn (EquipmentCalibration $record): bool => self::currentActor()->can('view', $record))
                    ->modalContent(static fn (EquipmentCalibration $record): Htmlable => new HtmlString(view('filament.support.calibration-evidence', [
                        'calibration' => $record->loadMissing('media'),
                        'collections' => EquipmentCalibrationEvidenceService::Collections,
                    ])->render())),
            ])
            ->toolbarActions([]);
    }

    /** @param array<string, scalar> $replace */
    private static function t(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }

    private static function limitsLabel(EquipmentCalibrationMeasurement $measurement): string
    {
        $format = static fn (?string $value): string => $value === null ? '…' : mb_rtrim(mb_rtrim($value, '0'), '.');

        return mb_trim($format($measurement->minimum_value).' – '.$format($measurement->maximum_value).' '.($measurement->unit ?? ''));
    }

    private function evidenceAction(string $name, string $label, string $collection): Action
    {
        return Action::make($name)
            ->label($label)
            ->schema([
                FileUpload::make('files')
                    ->label(__('Files'))
                    ->disk(EquipmentCalibrationEvidenceService::Disk)
                    ->directory(mb_rtrim(EquipmentCalibrationEvidenceService::UploadDirectory, '/'))
                    ->visibility('private')
                    ->multiple()
                    ->appendFiles()
                    ->required()
                    ->acceptedFileTypes(EquipmentCalibrationEvidenceService::AcceptedMimeTypes)
                    ->maxSize(EquipmentCalibrationEvidenceService::MaximumFileSizeInBytes / 1024),
            ])
            ->authorize(fn (EquipmentCalibration $record): bool => self::currentActor()->can('update', $record))
            ->action(fn (EquipmentCalibration $record, array $data) => $this->run(function () use ($record, $collection, $data): void {
                app(EquipmentCalibrationEvidenceService::class)->attach(
                    $record,
                    $collection,
                    is_array($data['files'] ?? null) ? $data['files'] : [],
                    self::currentActor(),
                );
            }));
    }

    private function progressDescription(): Htmlable
    {
        $record = $this->maintenanceRecord()->load('calibration.measurements', 'calibration.serializedInventoryUnit');
        $resolver = app(CalibrationProgressResolver::class);

        return new HtmlString(
            view('filament.support.calibration-progress', [
                'progress' => $resolver->resolve($record),
                'previous' => $resolver->previous($record),
            ])->render()
            .view('filament.support.calibration-measurements', ['calibration' => $record->calibration])->render(),
        );
    }

    /** Runs a service call, turning a domain validation failure into a notification. */
    private function run(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $validationException) {
            Notification::make()->danger()->title(__('Unable to update the calibration'))
                ->body(collect($validationException->errors())->flatten()->implode(' '))
                ->send();
        }
    }

    private function maintenanceRecord(): MaintenanceRecord
    {
        $record = $this->getOwnerRecord();

        return $record instanceof MaintenanceRecord ? $record : throw new LogicException('Expected the owner record to be a MaintenanceRecord.');
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : throw new LogicException('An authenticated User is required.');
    }
}
