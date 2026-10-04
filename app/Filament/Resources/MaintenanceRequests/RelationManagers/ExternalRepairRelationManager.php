<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\RelationManagers;

use App\Enums\ExternalRepairStatus;
use App\Enums\InventoryPermission;
use App\Enums\SerializedCustodyType;
use App\Enums\StockCondition;
use App\Enums\WarrantyRecoveryOutcome;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\ExternalRepairEvidenceService;
use App\Services\Support\ExternalRepairService;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
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
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Supplier / manufacturer repair (RMA) as a contextual section of the
 * maintenance request, not a separate module. Shipping to and receiving back
 * from the supplier move custody through Inventory, so those two actions also
 * require the Inventory supplier-custody permission.
 */
final class ExternalRepairRelationManager extends RelationManager
{
    protected static string $relationship = 'externalRepairs';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof MaintenanceRecord && (bool) config('support.external_repair_enabled', false);
    }

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Supplier Repair (RMA)');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['supplier', 'replacementUnit', 'warrantyRecoveryClaim', 'media'])->latest('id'))
            ->columns([
                TextColumn::make('supplier.name')->label(__('Supplier')),
                TextColumn::make('rma_number')->label(__('RMA number'))->placeholder(__('—'))->description(static fn (MaintenanceExternalRepair $record): ?string => $record->supplier_reference),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('requested_at')->label(__('Requested'))->dateTime(),
                TextColumn::make('estimated_return_on')
                    ->label(__('Estimated return'))
                    ->date()
                    ->placeholder(__('—'))
                    ->color(static fn (MaintenanceExternalRepair $record): string => $record->isOverdue() ? 'danger' : 'gray')
                    ->description(static fn (MaintenanceExternalRepair $record): ?string => $record->isOverdue() ? self::t('Overdue') : null),
                TextColumn::make('supplier_diagnosis')->label(__('Supplier diagnosis'))->placeholder(__('—'))->limit(60),
                TextColumn::make('supplier_resolution')->label(__('Supplier resolution'))->placeholder(__('—'))->limit(60),
                TextColumn::make('replacementUnit.serial_number')->label(__('Replacement serial'))->placeholder(__('—')),
                TextColumn::make('warrantyRecoveryClaim.status')->label(__('Recovery claim'))->badge()->placeholder(__('—'))
                    ->description(static fn (MaintenanceExternalRepair $record): ?string => $record->warrantyRecoveryClaim?->recovery_outcome?->label()),
                TextColumn::make('evidence_count')->label(__('Evidence'))->state(static fn (MaintenanceExternalRepair $record): int => $record->media->count()),
            ])
            ->headerActions([
                Action::make('requestRepair')
                    ->label(__('Request Supplier Repair'))
                    ->schema([
                        Select::make('supplier_id')
                            ->label(__('Supplier'))
                            ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('rma_number')->label(__('RMA number'))->maxLength(100),
                        DatePicker::make('estimated_return_on')->label(__('Estimated return')),
                        Textarea::make('reason')->label(__('Reason'))->required(),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('create', MaintenanceExternalRepair::class))
                    ->visible(fn (): bool => ! $this->maintenanceRecord()->isFinalised())
                    ->action(fn (array $data) => $this->run(function () use ($data): void {
                        app(ExternalRepairService::class)->request(
                            $this->maintenanceRecord(),
                            Supplier::query()->findOrFail(is_numeric($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : 0),
                            self::currentActor(),
                            [
                                'rma_number' => is_string($data['rma_number'] ?? null) && $data['rma_number'] !== '' ? $data['rma_number'] : null,
                                'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : null,
                                'estimated_return_on' => is_string($data['estimated_return_on'] ?? null) && $data['estimated_return_on'] !== '' ? Carbon::parse($data['estimated_return_on']) : null,
                            ],
                        );
                    })),
            ])
            ->recordActions([
                $this->stepAction('approveRepair', __('Approve'), ExternalRepairStatus::Approved, [TextInput::make('rma_number')->label(__('RMA number'))->maxLength(100)], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->approve($record, self::currentActor(), self::text($data, 'rma_number'))),
                $this->stepAction('shipToSupplier', __('Ship to Supplier'), ExternalRepairStatus::ShippedToSupplier, [TextInput::make('outbound_reference')->label(__('Outbound reference'))->maxLength(100)], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->ship($record, self::currentActor(), self::text($data, 'outbound_reference')), stock: true),
                $this->stepAction('receivedBySupplier', __('Received by Supplier'), ExternalRepairStatus::ReceivedBySupplier, [TextInput::make('supplier_reference')->label(__('Supplier reference'))->maxLength(100)], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->markReceivedBySupplier($record, self::currentActor(), self::text($data, 'supplier_reference'))),
                $this->stepAction('startRepair', __('Start Repair'), ExternalRepairStatus::Repairing, [Textarea::make('diagnosis')->label(__('Supplier diagnosis')), DatePicker::make('estimated_return_on')->label(__('Estimated return'))], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->startRepair($record, self::currentActor(), self::text($data, 'diagnosis'), is_string($data['estimated_return_on'] ?? null) && $data['estimated_return_on'] !== '' ? Carbon::parse($data['estimated_return_on']) : null)),
                $this->stepAction('markRepaired', __('Mark Repaired'), ExternalRepairStatus::Repaired, [Textarea::make('resolution')->label(__('Supplier resolution'))->required()], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->recordRepaired($record, self::currentActor(), self::text($data, 'resolution') ?? '')),
                $this->stepAction('approveReplacement', __('Approve Replacement'), ExternalRepairStatus::ReplacementApproved, [Textarea::make('resolution')->label(__('Supplier resolution'))->required()], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->approveReplacement($record, self::currentActor(), self::text($data, 'resolution') ?? '')),
                $this->stepAction('receiveReplacement', __('Receive Replacement'), ExternalRepairStatus::ReplacementReceived, [
                    Select::make('replacement_id')
                        ->label(__('Replacement unit'))
                        ->options(fn (MaintenanceExternalRepair $record): array => $this->replacementOptions($record))
                        ->required(),
                ], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->recordReplacementReceived(
                    $record,
                    SerializedInventoryUnit::query()->findOrFail(is_numeric($data['replacement_id'] ?? null) ? (int) $data['replacement_id'] : 0),
                    self::currentActor(),
                )),
                $this->stepAction('returnToCompany', __('Receive Back from Supplier'), ExternalRepairStatus::ReturnedToCompany, [
                    Select::make('warehouse_id')
                        ->label(__('Receive into warehouse'))
                        ->options(fn (): array => Warehouse::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
                    Select::make('condition')
                        ->label(__('Condition in'))
                        ->options([
                            StockCondition::Saleable->value => StockCondition::Saleable->label(),
                            StockCondition::Damaged->value => StockCondition::Damaged->label(),
                            StockCondition::Quarantine->value => StockCondition::Quarantine->label(),
                        ])
                        ->required(),
                    TextInput::make('inbound_reference')->label(__('Inbound reference'))->maxLength(100),
                ], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->returnToCompany(
                    $record,
                    is_numeric($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : 0,
                    StockCondition::tryFrom(is_string($data['condition'] ?? null) ? $data['condition'] : '') ?? StockCondition::Quarantine,
                    self::currentActor(),
                    self::text($data, 'inbound_reference'),
                ), stock: true),
                $this->stepAction('cancelRepair', __('Cancel Repair'), ExternalRepairStatus::Cancelled, [Textarea::make('reason')->label(__('Reason'))->required()], fn (MaintenanceExternalRepair $record, array $data) => app(ExternalRepairService::class)->cancel($record, self::currentActor(), self::text($data, 'reason') ?? ''), danger: true),
                Action::make('linkRecoveryClaim')
                    ->label(__('Link Recovery Claim'))
                    ->schema([
                        Select::make('claim_id')
                            ->label(__('Warranty recovery claim'))
                            ->options(fn (): array => WarrantyRecoveryClaim::query()
                                ->where('maintenance_record_id', $this->maintenanceRecord()->getKey())
                                ->get()
                                ->mapWithKeys(static fn (WarrantyRecoveryClaim $claim): array => [$claim->id => '#'.$claim->id.($claim->external_reference !== null ? ' · '.$claim->external_reference : '')])
                                ->all())
                            ->required(),
                    ])
                    ->authorize(fn (MaintenanceExternalRepair $record): bool => self::currentActor()->can('update', $record))
                    ->visible(static fn (MaintenanceExternalRepair $record): bool => $record->warranty_recovery_claim_id === null)
                    ->action(fn (MaintenanceExternalRepair $record, array $data) => $this->run(
                        fn () => app(ExternalRepairService::class)->linkRecoveryClaim($record, WarrantyRecoveryClaim::query()->findOrFail(is_numeric($data['claim_id'] ?? null) ? (int) $data['claim_id'] : 0), self::currentActor()),
                    )),
                Action::make('recoveryOutcome')
                    ->label(__('Recovery Outcome'))
                    ->schema([Select::make('outcome')->label(__('Outcome'))->options(WarrantyRecoveryOutcome::class)->required()])
                    ->authorize(fn (MaintenanceExternalRepair $record): bool => self::currentActor()->can('update', $record))
                    ->visible(static fn (MaintenanceExternalRepair $record): bool => $record->warranty_recovery_claim_id !== null)
                    ->action(fn (MaintenanceExternalRepair $record, array $data) => $this->run(
                        fn () => app(ExternalRepairService::class)->recordRecoveryOutcome($record, $data['outcome'] instanceof WarrantyRecoveryOutcome ? $data['outcome'] : (WarrantyRecoveryOutcome::tryFrom(is_string($data['outcome'] ?? null) ? $data['outcome'] : '') ?? WarrantyRecoveryOutcome::Rejected), self::currentActor()),
                    )),
                $this->evidenceAction('uploadRmaDocument', __('Upload RMA Document'), MaintenanceExternalRepair::MEDIA_RMA),
                $this->evidenceAction('uploadSupplierReport', __('Upload Supplier Report'), MaintenanceExternalRepair::MEDIA_SUPPLIER_REPORTS),
                $this->evidenceAction('uploadShippingDocument', __('Upload Shipping Document'), MaintenanceExternalRepair::MEDIA_SHIPPING),
                Action::make('viewEvidence')
                    ->label(__('View Evidence'))
                    ->modalHeading(__('Supplier repair evidence'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->authorize(fn (MaintenanceExternalRepair $record): bool => self::currentActor()->can('view', $record))
                    ->modalContent(static fn (MaintenanceExternalRepair $record): Htmlable => new HtmlString(view('filament.support.external-repair-evidence', [
                        'repair' => $record->loadMissing('media'),
                        'collections' => ExternalRepairEvidenceService::Collections,
                    ])->render())),
            ])
            ->toolbarActions([]);
    }

    /**
     * One lifecycle step: visible only while it is a legal next status, and
     * for steps that move custody only to users who also hold the Inventory
     * permission.
     *
     * @param  list<Field>  $schema
     * @param  callable(MaintenanceExternalRepair, array<array-key, mixed>): mixed  $handler
     */
    private function stepAction(string $name, string $label, ExternalRepairStatus $target, array $schema, callable $handler, bool $stock = false, bool $danger = false): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($danger ? 'danger' : 'primary')
            ->schema($schema)
            ->authorize(fn (MaintenanceExternalRepair $record): bool => self::currentActor()->can('update', $record)
                && (! $stock || self::currentActor()->can(InventoryPermission::SupplierCustodyManage->value)))
            ->visible(static fn (MaintenanceExternalRepair $record): bool => in_array($target, $record->status->nextStatuses(), true))
            ->action(fn (MaintenanceExternalRepair $record, array $data) => $this->run(fn () => $handler($record, $data)));
    }

    /** @return array<int, string> */
    private function replacementOptions(MaintenanceExternalRepair $repair): array
    {
        $original = SerializedInventoryUnit::query()->with('productVariant')->findOrFail($repair->serialized_inventory_unit_id);
        $record = $this->maintenanceRecord();

        return SerializedInventoryUnit::query()
            ->whereKeyNot($original->getKey())
            ->where('custody_type', SerializedCustodyType::Customer->value)
            ->where('custody_reference_id', $record->customer_id)
            ->whereHas('productVariant', static fn (Builder $variant): Builder => $variant->where('product_id', $original->productVariant?->product_id))
            ->orderBy('serial_number')
            ->get()
            ->mapWithKeys(static fn (SerializedInventoryUnit $unit): array => [$unit->id => $unit->serial_number])
            ->all();
    }

    private function evidenceAction(string $name, string $label, string $collection): Action
    {
        return Action::make($name)
            ->label($label)
            ->schema([
                FileUpload::make('files')
                    ->label(__('Files'))
                    ->disk(ExternalRepairEvidenceService::Disk)
                    ->directory(mb_rtrim(ExternalRepairEvidenceService::UploadDirectory, '/'))
                    ->visibility('private')
                    ->multiple()
                    ->appendFiles()
                    ->required()
                    ->acceptedFileTypes(ExternalRepairEvidenceService::AcceptedMimeTypes)
                    ->maxSize(ExternalRepairEvidenceService::MaximumFileSizeInBytes / 1024),
            ])
            ->authorize(fn (MaintenanceExternalRepair $record): bool => self::currentActor()->can('update', $record))
            ->action(fn (MaintenanceExternalRepair $record, array $data) => $this->run(function () use ($record, $collection, $data): void {
                app(ExternalRepairEvidenceService::class)->attach(
                    $record,
                    $collection,
                    is_array($data['files'] ?? null) ? $data['files'] : [],
                    self::currentActor(),
                );
            }));
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
    }

    /** @param array<string, scalar> $replace */
    private static function t(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }

    /** Runs a service call, turning a domain failure into a notification. */
    private function run(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $validationException) {
            Notification::make()->danger()->title(__('Unable to update the supplier repair'))
                ->body(collect($validationException->errors())->flatten()->implode(' '))
                ->send();
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to update the supplier repair'))->body($domainException->getMessage())->send();
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
