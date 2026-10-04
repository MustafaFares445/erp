<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\RelationManagers;

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\InstallationCheckResult;
use App\Enums\MaintenanceKind;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentInstallationCheck;
use App\Models\MaintenanceRecord;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Support\EquipmentInstallationEvidenceService;
use App\Services\Support\EquipmentInstallationService;
use App\Services\Support\InstallationProgressResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
 * One workspace for installation, commissioning, customer acceptance and
 * evidence, shown only on Installation-kind maintenance requests.
 */
final class InstallationRelationManager extends RelationManager
{
    protected static string $relationship = 'installation';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof MaintenanceRecord
            && $ownerRecord->maintenance_kind === MaintenanceKind::Installation
            && (bool) config('support.equipment_installation_enabled', true);
    }

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Installation & Commissioning');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): Htmlable => $this->progressDescription())
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['installedBy.user', 'checks', 'shipment', 'media']))
            ->columns([
                TextColumn::make('shipment.tracking_number')->label(__('Shipment'))->placeholder(__('—')),
                TextColumn::make('installation_location')->label(__('Location'))->placeholder(__('—')),
                TextColumn::make('installedBy.user.name')->label(__('Technician'))->placeholder(__('—')),
                TextColumn::make('installed_at')->label(__('Installed'))->dateTime()->placeholder(__('Not installed')),
                TextColumn::make('checks_summary')
                    ->label(__('Checklist'))
                    ->state(static fn (EquipmentInstallation $record): string => $record->checks->filter(
                        static fn (EquipmentInstallationCheck $check): bool => $check->result->satisfiesCommissioning(),
                    )->count().' / '.$record->checks->count()),
                TextColumn::make('commissioning_status')
                    ->label(__('Commissioning'))
                    ->badge()
                    ->description(static fn (EquipmentInstallation $record): ?string => $record->commissioning_status === CommissioningStatus::Failed
                        ? $record->commissioning_failure_reason
                        : $record->commissioned_at?->toDayDateTimeString()),
                TextColumn::make('customer_acceptance_status')
                    ->label(__('Customer acceptance'))
                    ->badge()
                    ->description(static fn (EquipmentInstallation $record): ?string => match ($record->customer_acceptance_status) {
                        CustomerAcceptanceStatus::Accepted => mb_trim(($record->customer_signatory_name ?? '').' · '.($record->customer_accepted_at?->toDayDateTimeString() ?? ''), ' ·'),
                        CustomerAcceptanceStatus::Rejected => mb_trim(($record->customer_rejection_reason ?? '').' · '.($record->customer_rejected_at?->toDayDateTimeString() ?? ''), ' ·'),
                        CustomerAcceptanceStatus::Pending => null,
                    }),
                TextColumn::make('evidence_count')
                    ->label(__('Evidence'))
                    ->state(static fn (EquipmentInstallation $record): int => $record->media->count()),
            ])
            ->headerActions([
                Action::make('startInstallation')
                    ->label(__('Start Installation'))
                    ->fillForm(function (): array {
                        $eligible = app(EquipmentInstallationService::class)->eligibleShipments($this->maintenanceRecord());

                        return ['shipment_id' => $eligible->count() === 1 ? $eligible->first()?->getKey() : null];
                    })
                    ->schema(fn (): array => [
                        Select::make('shipment_id')
                            ->label(__('Delivery shipment'))
                            ->options(fn (): array => $this->shipmentOptions())
                            ->required(fn (): bool => $this->shipmentOptions() !== [])
                            ->helperText(fn (): ?string => $this->shipmentOptions() === [] ? (string) __('No confirmed delivery shipment was found for this equipment and customer.') : null),
                        TextInput::make('installation_location')->label(__('Installation location'))->maxLength(255),
                        Textarea::make('notes')->label(__('Notes')),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('create', EquipmentInstallation::class))
                    ->visible(fn (): bool => ! EquipmentInstallation::query()->where('maintenance_record_id', $this->maintenanceRecord()->getKey())->exists())
                    ->action(fn (array $data) => $this->run(fn () => app(EquipmentInstallationService::class)->createForDeliveredEquipment(
                        $this->maintenanceRecord(),
                        self::currentActor(),
                        [
                            'shipment_id' => is_numeric($data['shipment_id'] ?? null) ? (int) $data['shipment_id'] : null,
                            'installation_location' => is_string($data['installation_location'] ?? null) ? $data['installation_location'] : null,
                            'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : null,
                        ],
                    ))),
            ])
            ->recordActions([
                Action::make('recordChecks')
                    ->label(__('Record Checks'))
                    ->fillForm(static fn (EquipmentInstallation $record): array => [
                        'checks' => $record->checks->map(static fn (EquipmentInstallationCheck $check): array => [
                            'check_key' => $check->check_key,
                            'label' => $check->label,
                            'result' => $check->result->value,
                            'measured_value' => $check->measured_value,
                            'unit' => $check->unit,
                            'notes' => $check->notes,
                        ])->all(),
                    ])
                    ->schema([
                        Repeater::make('checks')
                            ->label(__('Checks'))
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                Hidden::make('check_key'),
                                TextInput::make('label')->disabled()->dehydrated(),
                                Select::make('result')->label(__('Result'))->options(InstallationCheckResult::class)->required(),
                                TextInput::make('measured_value')->label(__('Measured value'))->numeric(),
                                TextInput::make('unit')->maxLength(30),
                                TextInput::make('notes'),
                            ])
                            ->columns(5),
                    ])
                    ->authorize(fn (EquipmentInstallation $record): bool => self::currentActor()->can('update', $record))
                    ->visible(fn (): bool => ! $this->maintenanceRecord()->isFinalised())
                    ->action(fn (EquipmentInstallation $record, array $data) => $this->run(function () use ($record, $data): void {
                        collect(is_array($data['checks'] ?? null) ? $data['checks'] : [])->each(function (mixed $row) use ($record): void {
                            $input = new Fluent((array) $row);
                            $row = (array) $row;

                            app(EquipmentInstallationService::class)->recordCheck(
                                $record,
                                $input->string('check_key')->toString(),
                                InstallationCheckResult::fromState($input->get('result')),
                                self::currentActor(),
                                is_numeric($row['measured_value'] ?? null) ? (string) $row['measured_value'] : null,
                                is_string($row['unit'] ?? null) && $row['unit'] !== '' ? $row['unit'] : null,
                                is_string($row['notes'] ?? null) && $row['notes'] !== '' ? $row['notes'] : null,
                            );
                        });
                    })),
                Action::make('completeInstallation')
                    ->label(__('Complete Installation'))
                    ->requiresConfirmation()
                    ->authorize(fn (EquipmentInstallation $record): bool => self::currentActor()->can('complete', $record))
                    ->visible(static fn (EquipmentInstallation $record): bool => ! $record->isInstalled())
                    ->action(fn (EquipmentInstallation $record) => $this->run(
                        fn () => app(EquipmentInstallationService::class)->completeInstallation($record, self::currentActor()),
                    )),
                Action::make('passCommissioning')
                    ->label(__('Pass Commissioning'))
                    ->requiresConfirmation()
                    ->authorize(fn (EquipmentInstallation $record): bool => self::currentActor()->can('complete', $record))
                    ->visible(static fn (EquipmentInstallation $record): bool => $record->isInstalled() && $record->commissioning_status !== CommissioningStatus::Passed)
                    ->action(fn (EquipmentInstallation $record) => $this->run(
                        fn () => app(EquipmentInstallationService::class)->completeCommissioning($record, self::currentActor()),
                    )),
                Action::make('failCommissioning')
                    ->label(__('Fail Commissioning'))
                    ->color('danger')
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()])
                    ->authorize(fn (EquipmentInstallation $record): bool => self::currentActor()->can('complete', $record))
                    ->visible(static fn (EquipmentInstallation $record): bool => $record->isInstalled() && $record->commissioning_status !== CommissioningStatus::Passed)
                    ->action(fn (EquipmentInstallation $record, array $data) => $this->run(
                        fn () => app(EquipmentInstallationService::class)->failCommissioning($record, self::currentActor(), is_string($data['reason'] ?? null) ? $data['reason'] : ''),
                    )),
                Action::make('customerAccepts')
                    ->label(__('Customer Accepts'))
                    ->schema([TextInput::make('signatory')->label(__('Signatory name'))->required()->maxLength(255)])
                    ->authorize(fn (EquipmentInstallation $record): bool => self::currentActor()->can('complete', $record))
                    ->visible(static fn (EquipmentInstallation $record): bool => $record->commissioning_status === CommissioningStatus::Passed
                        && $record->customer_acceptance_status === CustomerAcceptanceStatus::Pending)
                    ->action(fn (EquipmentInstallation $record, array $data) => $this->run(
                        fn () => app(EquipmentInstallationService::class)->acceptByCustomer($record, is_string($data['signatory'] ?? null) ? $data['signatory'] : '', self::currentActor()),
                    )),
                Action::make('customerRejects')
                    ->label(__('Customer Rejects'))
                    ->color('danger')
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()])
                    ->authorize(fn (EquipmentInstallation $record): bool => self::currentActor()->can('complete', $record))
                    ->visible(static fn (EquipmentInstallation $record): bool => $record->commissioning_status === CommissioningStatus::Passed
                        && $record->customer_acceptance_status === CustomerAcceptanceStatus::Pending)
                    ->action(fn (EquipmentInstallation $record, array $data) => $this->run(
                        fn () => app(EquipmentInstallationService::class)->rejectByCustomer($record, is_string($data['reason'] ?? null) ? $data['reason'] : '', self::currentActor()),
                    )),
                $this->evidenceAction('uploadInstallationEvidence', __('Installation Evidence'), EquipmentInstallation::MEDIA_PHOTOS),
                $this->evidenceAction('uploadCommissioningEvidence', __('Commissioning Evidence'), EquipmentInstallation::MEDIA_COMMISSIONING),
                $this->evidenceAction('uploadAcceptanceEvidence', __('Acceptance Evidence'), EquipmentInstallation::MEDIA_ACCEPTANCE),
                Action::make('viewEvidence')
                    ->label(__('View Evidence'))
                    ->modalHeading(__('Installation evidence'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->authorize(fn (EquipmentInstallation $record): bool => self::currentActor()->can('view', $record))
                    ->modalContent(static fn (EquipmentInstallation $record): Htmlable => new HtmlString(view('filament.support.installation-evidence', [
                        'installation' => $record->loadMissing('media'),
                        'collections' => EquipmentInstallationEvidenceService::Collections,
                    ])->render())),
            ])
            ->toolbarActions([]);
    }

    private function evidenceAction(string $name, string $label, string $collection): Action
    {
        return Action::make($name)
            ->label($label)
            ->schema([
                FileUpload::make('files')
                    ->label(__('Files'))
                    ->disk(EquipmentInstallationEvidenceService::Disk)
                    ->directory(mb_rtrim(EquipmentInstallationEvidenceService::UploadDirectory, '/'))
                    ->visibility('private')
                    ->multiple()
                    ->appendFiles()
                    ->required()
                    ->acceptedFileTypes(EquipmentInstallationEvidenceService::AcceptedMimeTypes)
                    ->maxSize(EquipmentInstallationEvidenceService::MaximumFileSizeInBytes / 1024),
            ])
            ->authorize(fn (EquipmentInstallation $record): bool => self::currentActor()->can('update', $record))
            ->action(fn (EquipmentInstallation $record, array $data) => $this->run(function () use ($record, $collection, $data): void {
                app(EquipmentInstallationEvidenceService::class)->attach(
                    $record,
                    $collection,
                    is_array($data['files'] ?? null) ? $data['files'] : [],
                    self::currentActor(),
                );
            }));
    }

    /** @return array<int, string> */
    private function shipmentOptions(): array
    {
        return app(EquipmentInstallationService::class)->eligibleShipments($this->maintenanceRecord())
            ->mapWithKeys(fn (Shipment $shipment): array => [$shipment->id => $this->shipmentLabel($shipment)])
            ->all();
    }

    private function shipmentLabel(Shipment $shipment): string
    {
        $record = $this->maintenanceRecord()->loadMissing(['customer', 'productVariant.product', 'serializedInventoryUnit']);

        return collect([
            $shipment->tracking_number,
            $shipment->confirmed_at?->toDateString(),
            $record->customer->company_name ?? null,
            $record->productVariant?->product?->name,
            $record->serializedInventoryUnit?->serial_number,
        ])->filter()->implode(' · ');
    }

    private function progressDescription(): Htmlable
    {
        $record = $this->maintenanceRecord()->load('installation.checks', 'installation.shipment');

        return new HtmlString(view('filament.support.installation-progress', [
            'progress' => app(InstallationProgressResolver::class)->resolve($record),
        ])->render());
    }

    /** Runs a service call, turning a domain validation failure into a notification. */
    private function run(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $validationException) {
            Notification::make()->danger()->title(__('Unable to update the installation'))
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
