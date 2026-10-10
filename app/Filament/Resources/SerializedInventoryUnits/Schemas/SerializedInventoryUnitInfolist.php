<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Schemas;

use App\Enums\MaintenanceStatus;
use App\Enums\MovementType;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Enums\WarrantyEntitlementState;
use App\Filament\Components\InventoryViewDiagnostics;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Models\WarrantyEntitlement;
use App\Services\Inventory\Diagnostics\SerializedInventoryUnitDiagnoser;
use App\Services\Inventory\SerializedInventoryTimelineService;
use App\Services\Support\MaintenanceCostService;
use App\Support\QuantityFormatter;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use WeakMap;

/**
 * Serialized unit View page: Operational health, key figures, identity, current custody,
 * then the Support-owned warranty and maintenance sections (each shown only to users who may
 * see that data) and the movement history last.
 */
final class SerializedInventoryUnitInfolist
{
    /** @var WeakMap<SerializedInventoryUnit, WarrantyEntitlement|false>|null */
    private static ?WeakMap $entitlements = null;

    /** @var array{default: int, md: int, xl: int} */
    private const array Columns = ['default' => 1, 'md' => 2, 'xl' => 3];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                InventoryViewDiagnostics::section(),
                InventoryViewDiagnostics::metrics(static function (Model $record): array {
                    assert($record instanceof SerializedInventoryUnit);

                    return self::metrics($record);
                }),
                Section::make(__('inventory_view_ux.ui.serial_identity'))
                    ->columns(self::Columns)
                    ->schema([
                        TextEntry::make('serial_number')->label(__('admin.inventory.serialized_unit.fields.serial')),
                        TextEntry::make('iot_number')->label(__('admin.inventory.serialized_unit.fields.iot'))->placeholder(__('—')),
                        TextEntry::make('productVariant.sku')->label(__('admin.inventory.serialized_unit.fields.sku')),
                        TextEntry::make('productVariant.product.name')->label(__('admin.inventory.serialized_unit.fields.product')),
                        TextEntry::make('lot.lot_number')
                            ->label(__('admin.inventory.serialized_unit.fields.lot'))
                            ->url(static fn (SerializedInventoryUnit $record): ?string => $record->lot instanceof InventoryLot && InventoryLotResource::canView($record->lot)
                                ? InventoryLotResource::getUrl('view', ['record' => $record->lot])
                                : null)
                            ->placeholder(__('—')),
                        TextEntry::make('receipt_source')
                            ->label(__('admin.inventory.serialized_unit.fields.receipt_source'))
                            ->state(static fn (SerializedInventoryUnit $record): ?string => $record->receiptMovement instanceof InventoryMovement
                                ? StockMovementsTable::sourceReference($record->receiptMovement)
                                : null)
                            ->placeholder(__('—')),
                    ]),
                Section::make(__('inventory_view_ux.ui.serial_location'))
                    ->description(__('inventory_view_ux.ui.serial_location_description'))
                    ->columns(self::Columns)
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('inventory_view_ux.ui.serial_status'))
                            ->badge()
                            ->formatStateUsing(static fn (SerializedInventoryUnitStatus $state): string => __('admin.inventory.serialized_unit.statuses.'.$state->value)),
                        TextEntry::make('stock_condition')
                            ->label(__('admin.inventory.serialized_unit.fields.condition'))
                            ->badge()
                            ->formatStateUsing(static fn (StockCondition $state): string => __('admin.inventory.serialized_unit.conditions.'.$state->value)),
                        TextEntry::make('custody_type')
                            ->label(__('admin.inventory.serialized_unit.fields.custody'))
                            ->badge()
                            ->formatStateUsing(static fn (SerializedCustodyType $state): string => __('admin.inventory.serialized_unit.custody.'.$state->value)),
                        TextEntry::make('custody_reference')
                            ->label(__('admin.inventory.serialized_unit.fields.custody_reference'))
                            ->state(static fn (SerializedInventoryUnit $record): ?string => self::custodyReference($record))
                            ->placeholder(__('—')),
                        TextEntry::make('current_warehouse')
                            ->label(__('admin.inventory.serialized_unit.fields.current_warehouse'))
                            ->state(static fn (SerializedInventoryUnit $record): ?string => $record->warehouse instanceof Warehouse
                                ? $record->warehouse->code.' — '.$record->warehouse->name
                                : null)
                            ->placeholder(__('—')),
                    ]),
                self::warrantySection(),
                self::serviceHistorySection(),
                self::preventiveMaintenanceSection(),
                Section::make(__('admin.inventory.serialized_unit.sections.movement_history'))->schema([
                    RepeatableEntry::make('timeline')
                        ->hiddenLabel()
                        ->state(static fn (SerializedInventoryUnit $record): array => self::movementHistory($record))
                        ->schema([
                            TextEntry::make('occurred_at')->label(__('admin.inventory.serialized_unit.fields.date'))->dateTime(),
                            TextEntry::make('type')
                                ->label(__('inventory-other.lot.fields.movement'))
                                ->formatStateUsing(static fn (?string $state): ?string => self::enumLabel(MovementType::class, $state))
                                ->badge(),
                            TextEntry::make('warehouse')->label(__('inventory-other.lot.fields.warehouse'))->placeholder(__('—')),
                            TextEntry::make('transaction_quantity')->label(__('admin.inventory.serialized_unit.fields.transaction_quantity'))->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state))->placeholder(__('—')),
                            TextEntry::make('transaction_unit')->label(__('admin.inventory.serialized_unit.fields.unit'))->placeholder(__('—')),
                            TextEntry::make('base_quantity_delta')->label(__('admin.inventory.serialized_unit.fields.base_delta'))->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                            TextEntry::make('lot')->label(__('admin.inventory.serialized_unit.fields.lot'))->placeholder(__('—')),
                            TextEntry::make('condition_from')
                                ->label(__('admin.inventory.serialized_unit.fields.from_condition'))
                                ->formatStateUsing(static fn (?string $state): ?string => self::enumLabel(StockCondition::class, $state))
                                ->badge()
                                ->placeholder(__('—')),
                            TextEntry::make('condition_to')
                                ->label(__('admin.inventory.serialized_unit.fields.to_condition'))
                                ->formatStateUsing(static fn (?string $state): ?string => self::enumLabel(StockCondition::class, $state))
                                ->badge()
                                ->placeholder(__('—')),
                            TextEntry::make('source')->label(__('inventory-other.lot.fields.source'))->placeholder(__('—')),
                            TextEntry::make('source_line')->label(__('admin.inventory.serialized_unit.fields.source_line'))->placeholder(__('—')),
                            TextEntry::make('reversal_of')->label(__('admin.inventory.serialized_unit.fields.reversal_of'))->placeholder(__('—')),
                            TextEntry::make('notes')->label(__('inventory-other.lot.fields.notes'))->placeholder(__('—'))->columnSpanFull(),
                        ])
                        ->columns(self::Columns),
                ]),
            ]);
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(SerializedInventoryUnit $record): array
    {
        $metrics = [
            [
                'label' => __('inventory_view_ux.ui.serial_status'),
                'value' => __('admin.inventory.serialized_unit.statuses.'.$record->status->value),
                'tone' => match ($record->status) {
                    SerializedInventoryUnitStatus::Unknown => 'warning',
                    SerializedInventoryUnitStatus::Damaged => 'danger',
                    default => null,
                },
            ],
            [
                'label' => __('admin.inventory.serialized_unit.fields.condition'),
                'value' => __('admin.inventory.serialized_unit.conditions.'.$record->stock_condition->value),
                'tone' => match ($record->stock_condition) {
                    StockCondition::Quarantine => 'warning',
                    StockCondition::Damaged => 'danger',
                    default => null,
                },
            ],
            [
                'label' => __('admin.inventory.serialized_unit.fields.custody'),
                'value' => __('admin.inventory.serialized_unit.custody.'.$record->custody_type->value),
                'helper' => self::custodyReference($record),
                'tone' => $record->custody_type === SerializedCustodyType::Unknown ? 'warning' : null,
            ],
        ];

        if (SerializedInventoryUnitDiagnoser::canViewWarranty()) {
            $metrics[] = self::warrantyMetric($record);
        }

        if (SerializedInventoryUnitDiagnoser::canViewMaintenanceRecords()) {
            $open = $record->maintenanceRecords()
                ->whereNotIn('status', [MaintenanceStatus::Closed->value, MaintenanceStatus::Cancelled->value])
                ->count();
            $metrics[] = [
                'label' => __('inventory_view_ux.ui.serial_open_jobs'),
                'value' => (string) $open,
                'tone' => $open > 0 ? 'warning' : null,
            ];
        }

        return $metrics;
    }

    /**
     * @return array{label: string, value: string, helper: string|null, tone: string|null}
     */
    private static function warrantyMetric(SerializedInventoryUnit $record): array
    {
        $entitlement = self::currentEntitlement($record);
        $expiresOn = $entitlement instanceof WarrantyEntitlement ? $entitlement->expires_on : $record->warranty_expires_on;
        $today = today();
        $expiringSoon = $expiresOn instanceof CarbonInterface
            && $expiresOn->gte($today)
            && $expiresOn->lte($today->copy()->addDays(SerializedInventoryUnitDiagnoser::WarrantyExpiringDays));

        return [
            'label' => __('inventory_view_ux.ui.serial_warranty'),
            'value' => self::warrantyState($record),
            'helper' => $expiresOn instanceof CarbonInterface
                ? __('inventory_view_ux.ui.serial_warranty_expires_on', ['date' => $expiresOn->toDateString()])
                : null,
            'tone' => match (true) {
                $expiringSoon => 'warning',
                $entitlement instanceof WarrantyEntitlement && $entitlement->isActiveAt($today) => 'primary',
                default => null,
            },
        ];
    }

    private static function warrantySection(): Section
    {
        return Section::make(__('Customer warranty'))
            ->description(__('Current entitlement and immutable warranty history for this serialized asset.'))
            ->visible(static fn (): bool => SerializedInventoryUnitDiagnoser::canViewWarranty())
            ->columns(self::Columns)
            ->schema([
                TextEntry::make('current_warranty_policy')
                    ->label(__('Current policy'))
                    ->state(static fn (SerializedInventoryUnit $record): string => self::currentEntitlement($record)->policy_name ?? __('inventory_view_ux.ui.serial_warranty_policy_legacy')),
                TextEntry::make('current_warranty_state')
                    ->label(__('Current entitlement'))
                    ->state(static fn (SerializedInventoryUnit $record): string => self::warrantyState($record))
                    ->color(static fn (SerializedInventoryUnit $record): string => self::currentEntitlement($record)?->state->color() ?? 'gray')
                    ->badge(),
                TextEntry::make('warranty_started_on')->label(__('Started'))->date()->placeholder(__('—')),
                TextEntry::make('warranty_expires_on')->label(__('Expires'))->date()->placeholder(__('—')),
                TextEntry::make('current_warranty_trigger')
                    ->label(__('Starts from'))
                    ->state(static fn (SerializedInventoryUnit $record): string => self::currentEntitlement($record)?->start_trigger->label() ?? __('inventory_view_ux.ui.serial_warranty_trigger_legacy')),
                TextEntry::make('current_warranty_coverage')
                    ->label(__('Default coverage'))
                    ->helperText(__('inventory_view_ux.ui.serial_coverage_helper'))
                    ->state(static fn (SerializedInventoryUnit $record): string => self::coverageRules($record))
                    ->columnSpanFull(),
                RepeatableEntry::make('warrantyHistory')
                    ->label(__('Entitlement history'))
                    ->state(static fn (SerializedInventoryUnit $record): array => $record->warrantyEntitlements()
                        ->with('customer:id,company_name')
                        ->get()
                        ->map(static fn (WarrantyEntitlement $entitlement): array => [
                            'policy' => $entitlement->policy_name,
                            'customer' => $entitlement->customer->company_name ?? __('inventory_view_ux.ui.serial_unknown_customer'),
                            'state' => self::entitlementStateLabel($entitlement->state),
                            'starts_on' => $entitlement->starts_on,
                            'expires_on' => $entitlement->expires_on,
                            'end_reason' => $entitlement->end_reason,
                        ])
                        ->all())
                    ->schema([
                        TextEntry::make('policy')->label(__('Policy')),
                        TextEntry::make('customer')->label(__('Customer')),
                        TextEntry::make('state')->label(__('inventory_view_ux.ui.serial_status'))->badge(),
                        TextEntry::make('starts_on')->label(__('Started'))->date()->placeholder(__('—')),
                        TextEntry::make('expires_on')->label(__('Expires'))->date()->placeholder(__('—')),
                        TextEntry::make('end_reason')->label(__('End reason'))->placeholder(__('—'))->columnSpanFull(),
                    ])
                    ->columns(self::Columns)
                    ->columnSpanFull(),
            ]);
    }

    private static function serviceHistorySection(): Section
    {
        return Section::make(__('admin.inventory.serialized_unit.sections.service_history'))
            ->description(__('admin.inventory.serialized_unit.descriptions.service_history'))
            ->visible(static fn (): bool => SerializedInventoryUnitDiagnoser::canViewMaintenanceRecords())
            ->schema([
                RepeatableEntry::make('serviceHistory')
                    ->hiddenLabel()
                    ->state(static fn (SerializedInventoryUnit $record): array => self::serviceHistory($record))
                    ->schema([
                        TextEntry::make('id')->label(__('admin.inventory.serialized_unit.fields.job_number'))->prefix('#'),
                        TextEntry::make('created_at')->label(__('admin.inventory.serialized_unit.fields.opened'))->dateTime(),
                        TextEntry::make('status')->label(__('inventory_view_ux.ui.serial_status'))->badge(),
                        TextEntry::make('billing_type')->label(__('admin.inventory.serialized_unit.fields.billing'))->badge(),
                        TextEntry::make('total_cost_minor')
                            ->label(__('admin.inventory.serialized_unit.fields.cost'))
                            ->visible(static fn (): bool => SerializedInventoryUnitDiagnoser::canViewMaintenanceCost())
                            ->formatStateUsing(static fn (?int $state): string => $state === null ? '—' : number_format($state / 100, 2)),
                        TextEntry::make('coverage_percent')
                            ->label(__('admin.inventory.serialized_unit.fields.cost_coverage'))
                            ->visible(static fn (): bool => SerializedInventoryUnitDiagnoser::canViewMaintenanceCost())
                            ->suffix('%'),
                    ])
                    ->columns(self::Columns),
            ]);
    }

    private static function preventiveMaintenanceSection(): Section
    {
        return Section::make(__('admin.inventory.serialized_unit.sections.preventive_maintenance'))
            ->description(__('admin.inventory.serialized_unit.descriptions.preventive_maintenance'))
            ->visible(static fn (): bool => SerializedInventoryUnitDiagnoser::canViewMaintenanceSchedules())
            ->schema([
                RepeatableEntry::make('preventiveSchedules')
                    ->label(__('admin.inventory.serialized_unit.fields.schedules'))
                    ->state(static fn (SerializedInventoryUnit $record): array => $record->maintenanceSchedules()
                        ->withCount([
                            'occurrences as missed_count' => static fn (Builder $query): Builder => $query->where('status', OccurrenceStatus::Missed->value),
                            'occurrences as completed_count' => static fn (Builder $query): Builder => $query->where('status', OccurrenceStatus::Completed->value),
                        ])
                        ->get()
                        ->map(static fn (MaintenanceSchedule $schedule): array => [
                            'schedule_number' => $schedule->schedule_number,
                            'name' => $schedule->name,
                            'is_active' => $schedule->is_active,
                            'next_due_on' => $schedule->next_due_on,
                            'last_completed_on' => $schedule->last_completed_on,
                            'missed_count' => self::countAttribute($schedule, 'missed_count'),
                            'completed_count' => self::countAttribute($schedule, 'completed_count'),
                        ])
                        ->all())
                    ->schema([
                        TextEntry::make('schedule_number')->label(__('admin.inventory.serialized_unit.fields.schedule_number')),
                        TextEntry::make('name')->label(__('inventory_view_ux.ui.serial_schedule_name')),
                        TextEntry::make('is_active')->label(__('admin.inventory.serialized_unit.fields.active'))->formatStateUsing(static fn (bool $state): string => $state
                            ? __('admin.inventory.serialized_unit.values.active')
                            : __('admin.inventory.serialized_unit.values.inactive'))->badge(),
                        TextEntry::make('next_due_on')->label(__('admin.inventory.serialized_unit.fields.next_due'))->date(),
                        TextEntry::make('last_completed_on')->label(__('admin.inventory.serialized_unit.fields.last_completed'))->date()->placeholder(__('—')),
                        TextEntry::make('completed_count')->label(__('admin.inventory.serialized_unit.fields.completed')),
                        TextEntry::make('missed_count')->label(__('admin.inventory.serialized_unit.fields.missed'))->badge()->color(static fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4]),
            ]);
    }

    /**
     * Job cost is computed only for users who may see it, with its inputs eager-loaded.
     *
     * @return list<array<string, mixed>>
     */
    private static function serviceHistory(SerializedInventoryUnit $record): array
    {
        $withCost = SerializedInventoryUnitDiagnoser::canViewMaintenanceCost();
        $costService = app(MaintenanceCostService::class);

        return array_values(MaintenanceRecord::query()
            ->where('serialized_inventory_unit_id', $record->getKey())
            ->when($withCost, static fn (Builder $query): Builder => $query->with(['serviceRecords.parts', 'labourEntries', 'thirdPartyCosts']))
            ->orderByDesc('created_at')
            ->get()
            ->map(static function (MaintenanceRecord $job) use ($costService, $withCost): array {
                $cost = $withCost ? $costService->jobCost($job) : null;

                return [
                    'id' => $job->getKey(),
                    'status' => $job->status->label(),
                    'billing_type' => $job->billing_type->getLabel(),
                    'total_cost_minor' => $cost['total_cost_minor'] ?? null,
                    'coverage_percent' => $cost['coverage_percent'] ?? null,
                    'created_at' => $job->created_at,
                ];
            })
            ->all());
    }

    /**
     * The unit's movement timeline with each source named like the stock movement ledger names it.
     *
     * @return list<array<string, mixed>>
     */
    private static function movementHistory(SerializedInventoryUnit $record): array
    {
        $movements = $record->movements()->get()->keyBy('id');

        return array_map(static function (array $event) use ($movements): array {
            $movement = $movements->get($event['sequence']);

            if ($movement instanceof InventoryMovement) {
                $event['source'] = StockMovementsTable::sourceReference($movement);
            }

            return $event;
        }, app(SerializedInventoryTimelineService::class)->events($record));
    }

    /**
     * The record holding the unit in custody, by name rather than its stored type and key.
     */
    private static function custodyReference(SerializedInventoryUnit $record): ?string
    {
        $type = $record->custody_reference_type;
        $id = $record->custody_reference_id;

        if ($type === null || $id === null) {
            return null;
        }

        $name = match ($type) {
            'customer' => CustomerProfile::query()->whereKey($id)->value('company_name'),
            'warehouse' => Warehouse::query()->whereKey($id)->value('name'),
            'inventory_operation' => InventoryOperation::query()->whereKey($id)->value('operation_number'),
            default => null,
        };

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $key = 'inventory_view_ux.ui.serial_reference_types.'.$type;
        $label = __($key);

        return sprintf('%s #%s', $label === $key ? Str::headline($type) : $label, $id);
    }

    private static function currentEntitlement(SerializedInventoryUnit $record): ?WarrantyEntitlement
    {
        self::$entitlements ??= new WeakMap;

        if (! isset(self::$entitlements[$record])) {
            $entitlement = $record->warrantyEntitlements()->first();
            self::$entitlements[$record] = $entitlement instanceof WarrantyEntitlement ? $entitlement : false;
        }

        $cached = self::$entitlements[$record];

        return $cached instanceof WarrantyEntitlement ? $cached : null;
    }

    private static function warrantyState(SerializedInventoryUnit $record): string
    {
        $entitlement = self::currentEntitlement($record);

        if ($entitlement instanceof WarrantyEntitlement) {
            return self::entitlementStateLabel($entitlement->state);
        }

        if ($record->warranty_expires_on === null) {
            return __('inventory_view_ux.ui.serial_warranty_none');
        }

        return today()->lte($record->warranty_expires_on)
            ? __('inventory_view_ux.ui.serial_warranty_legacy_active')
            : __('inventory_view_ux.ui.serial_warranty_legacy_expired');
    }

    private static function entitlementStateLabel(WarrantyEntitlementState $state): string
    {
        return __('inventory_view_ux.ui.serial_warranty_states.'.$state->value);
    }

    /**
     * The entitlement's default charge categories; actual coverage is decided per repair.
     */
    private static function coverageRules(SerializedInventoryUnit $record): string
    {
        $entitlement = self::currentEntitlement($record);

        if (! $entitlement instanceof WarrantyEntitlement) {
            return $record->warranty_expires_on !== null
                ? __('inventory_view_ux.ui.serial_coverage_legacy')
                : __('inventory_view_ux.ui.serial_coverage_unavailable');
        }

        $covered = collect([
            'parts' => $entitlement->covers_parts,
            'labour' => $entitlement->covers_labour,
            'travel' => $entitlement->covers_travel,
            'consumables' => $entitlement->covers_consumables,
            'third_party' => $entitlement->covers_third_party,
        ])
            ->filter()
            ->keys()
            ->map(static fn (string $category): string => __('inventory_view_ux.ui.serial_coverage_categories.'.$category))
            ->implode(__('inventory_view_ux.ui.serial_list_separator'));

        return $covered !== '' ? $covered : __('inventory_view_ux.ui.serial_coverage_no_categories');
    }

    /**
     * @param  class-string<MovementType|StockCondition>  $enum
     * @return ($state is null ? null : string)
     */
    private static function enumLabel(string $enum, ?string $state): ?string
    {
        if ($state === null || $state === '') {
            return $state;
        }

        return $enum::tryFrom($state)?->getLabel() ?? $state;
    }

    private static function countAttribute(Model $model, string $attribute): int
    {
        $count = $model->getAttribute($attribute);

        return is_numeric($count) ? (int) $count : 0;
    }
}
