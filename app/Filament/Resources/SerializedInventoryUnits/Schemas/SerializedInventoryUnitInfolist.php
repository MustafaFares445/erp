<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Schemas;

use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use App\Models\WarrantyEntitlement;
use App\Services\Inventory\SerializedInventoryTimelineService;
use App\Services\Support\MaintenanceCostService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SerializedInventoryUnitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(2)->schema([
                    TextEntry::make('serial_number')->label(__('admin.inventory.serialized_unit.fields.serial')),
                    TextEntry::make('iot_number')->label(__('admin.inventory.serialized_unit.fields.iot'))->placeholder(__('—')),
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.serialized_unit.fields.sku')),
                    TextEntry::make('productVariant.product.name')->label(__('admin.inventory.serialized_unit.fields.product')),
                    TextEntry::make('status')->badge()->formatStateUsing(fn (SerializedInventoryUnitStatus $state): string => __('admin.inventory.serialized_unit.statuses.'.$state->value)),
                    TextEntry::make('stock_condition')->label(__('admin.inventory.serialized_unit.fields.condition'))->badge()->formatStateUsing(fn (StockCondition $state): string => __('admin.inventory.serialized_unit.conditions.'.$state->value)),
                    TextEntry::make('custody_type')->label(__('admin.inventory.serialized_unit.fields.custody'))->badge()->formatStateUsing(fn (SerializedCustodyType $state): string => __('admin.inventory.serialized_unit.custody.'.$state->value)),
                    TextEntry::make('custody_reference_type')->label(__('admin.inventory.serialized_unit.fields.custody_reference'))->placeholder(__('—')),
                    TextEntry::make('custody_reference_id')->label(__('admin.inventory.serialized_unit.fields.custody_reference_id'))->placeholder(__('—')),
                    TextEntry::make('warehouse.code')->label(__('admin.inventory.serialized_unit.fields.current_warehouse'))->placeholder(__('—')),
                    TextEntry::make('receipt_source')
                        ->label(__('admin.inventory.serialized_unit.fields.receipt_source'))
                        ->state(fn (SerializedInventoryUnit $record): ?string => app(SerializedInventoryTimelineService::class)->receiptSource($record))
                        ->placeholder(__('—')),
                ]),
                Section::make(__('Customer warranty'))
                    ->description(__('Current entitlement and immutable warranty history for this serialized asset.'))
                    ->schema([
                        TextEntry::make('current_warranty_policy')
                            ->label(__('Current policy'))
                            ->state(static fn (SerializedInventoryUnit $record): string => self::currentEntitlement($record)->policy_name ?? 'Legacy / none'),
                        TextEntry::make('current_warranty_state')
                            ->label(__('Current entitlement'))
                            ->state(static fn (SerializedInventoryUnit $record): string => self::currentEntitlement($record)?->state->label() ?? self::legacyWarrantyState($record))
                            ->badge(),
                        TextEntry::make('warranty_started_on')->label(__('Started'))->date()->placeholder(__('—')),
                        TextEntry::make('warranty_expires_on')->label(__('Expires'))->date()->placeholder(__('—')),
                        TextEntry::make('current_warranty_trigger')
                            ->label(__('Starts from'))
                            ->state(static fn (SerializedInventoryUnit $record): string => self::currentEntitlement($record)?->start_trigger->label() ?? 'Confirmed delivery / legacy'),
                        TextEntry::make('current_warranty_coverage')
                            ->label(__('Default coverage'))
                            ->state(static fn (SerializedInventoryUnit $record): string => self::coverageRules($record))
                            ->columnSpanFull(),
                        RepeatableEntry::make('warrantyHistory')
                            ->label(__('Entitlement history'))
                            ->state(static fn (SerializedInventoryUnit $record): array => $record->warrantyEntitlements()
                                ->with('customer')
                                ->latest('id')
                                ->get()
                                ->map(static fn (WarrantyEntitlement $entitlement): array => [
                                    'policy' => $entitlement->policy_name,
                                    'customer' => $entitlement->customer->company_name ?? 'Unknown customer',
                                    'state' => $entitlement->state->label(),
                                    'starts_on' => $entitlement->starts_on,
                                    'expires_on' => $entitlement->expires_on,
                                    'end_reason' => $entitlement->end_reason,
                                ])
                                ->all())
                            ->schema([
                                TextEntry::make('policy')->label(__('Policy')),
                                TextEntry::make('customer')->label(__('Customer')),
                                TextEntry::make('state')->badge(),
                                TextEntry::make('starts_on')->label(__('Started'))->date()->placeholder(__('—')),
                                TextEntry::make('expires_on')->label(__('Expires'))->date()->placeholder(__('—')),
                                TextEntry::make('end_reason')->label(__('End reason'))->placeholder(__('—'))->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make(__('admin.inventory.serialized_unit.sections.movement_history'))->schema([
                    RepeatableEntry::make('timeline')
                        ->state(fn (SerializedInventoryUnit $record): array => app(SerializedInventoryTimelineService::class)->events($record))
                        ->schema([
                            TextEntry::make('occurred_at')->label(__('admin.inventory.serialized_unit.fields.date'))->dateTime(),
                            TextEntry::make('type')->badge(),
                            TextEntry::make('warehouse')->placeholder(__('—')),
                            TextEntry::make('transaction_quantity')->label(__('admin.inventory.serialized_unit.fields.transaction_quantity'))->placeholder(__('—')),
                            TextEntry::make('transaction_unit')->label(__('admin.inventory.serialized_unit.fields.unit'))->placeholder(__('—')),
                            TextEntry::make('base_quantity_delta')->label(__('admin.inventory.serialized_unit.fields.base_delta'))->numeric(decimalPlaces: 6),
                            TextEntry::make('lot')->label(__('admin.inventory.serialized_unit.fields.lot'))->placeholder(__('—')),
                            TextEntry::make('condition_from')->label(__('admin.inventory.serialized_unit.fields.from_condition'))->badge()->placeholder(__('—')),
                            TextEntry::make('condition_to')->label(__('admin.inventory.serialized_unit.fields.to_condition'))->badge()->placeholder(__('—')),
                            TextEntry::make('source')->placeholder(__('—')),
                            TextEntry::make('source_line')->label(__('admin.inventory.serialized_unit.fields.source_line'))->placeholder(__('—')),
                            TextEntry::make('reversal_of')->label(__('admin.inventory.serialized_unit.fields.reversal_of'))->placeholder(__('—')),
                            TextEntry::make('notes')->placeholder(__('—')),
                        ])
                        ->columns(3),
                ]),
                Section::make(__('admin.inventory.serialized_unit.sections.service_history'))
                    ->description(__('admin.inventory.serialized_unit.descriptions.service_history'))
                    ->schema([
                        RepeatableEntry::make('serviceHistory')
                            ->state(function (SerializedInventoryUnit $record): array {
                                $costService = app(MaintenanceCostService::class);

                                return MaintenanceRecord::query()
                                    ->where('serialized_inventory_unit_id', $record->getKey())
                                    ->orderByDesc('created_at')
                                    ->get()
                                    ->map(function (MaintenanceRecord $job) use ($costService): array {
                                        $cost = $costService->jobCost($job);

                                        return [
                                            'id' => $job->getKey(),
                                            'status' => $job->status->value,
                                            'billing_type' => $job->billing_type->value,
                                            'total_cost_minor' => $cost['total_cost_minor'],
                                            'coverage_percent' => $cost['coverage_percent'],
                                            'created_at' => $job->created_at,
                                        ];
                                    })
                                    ->all();
                            })
                            ->schema([
                                TextEntry::make('id')->label(__('admin.inventory.serialized_unit.fields.job_number')),
                                TextEntry::make('created_at')->label(__('admin.inventory.serialized_unit.fields.opened'))->dateTime(),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('billing_type')->label(__('admin.inventory.serialized_unit.fields.billing'))->badge(),
                                TextEntry::make('total_cost_minor')->label(__('admin.inventory.serialized_unit.fields.cost'))->formatStateUsing(fn (int $state): string => number_format($state / 100, 2)),
                                TextEntry::make('coverage_percent')->label(__('admin.inventory.serialized_unit.fields.cost_coverage'))->suffix('%'),
                            ])
                            ->columns(3),
                    ]),
                Section::make(__('admin.inventory.serialized_unit.sections.preventive_maintenance'))
                    ->description(__('admin.inventory.serialized_unit.descriptions.preventive_maintenance'))
                    ->schema([
                        RepeatableEntry::make('preventiveSchedules')
                            ->label(__('admin.inventory.serialized_unit.fields.schedules'))
                            ->state(fn (SerializedInventoryUnit $record): array => MaintenanceSchedule::query()
                                ->where('serialized_inventory_unit_id', $record->getKey())
                                ->get()
                                ->map(fn (MaintenanceSchedule $schedule): array => [
                                    'schedule_number' => $schedule->schedule_number,
                                    'name' => $schedule->name,
                                    'is_active' => $schedule->is_active,
                                    'next_due_on' => $schedule->next_due_on,
                                    'last_completed_on' => $schedule->last_completed_on,
                                    'missed_count' => $schedule->occurrences()->where('status', 'missed')->count(),
                                    'completed_count' => $schedule->occurrences()->where('status', 'completed')->count(),
                                ])
                                ->all())
                            ->schema([
                                TextEntry::make('schedule_number')->label(__('admin.inventory.serialized_unit.fields.schedule_number')),
                                TextEntry::make('name'),
                                TextEntry::make('is_active')->label(__('admin.inventory.serialized_unit.fields.active'))->formatStateUsing(fn (bool $state): string => $state
                                    ? __('admin.inventory.serialized_unit.values.active')
                                    : __('admin.inventory.serialized_unit.values.inactive'))->badge(),
                                TextEntry::make('next_due_on')->label(__('admin.inventory.serialized_unit.fields.next_due'))->date(),
                                TextEntry::make('last_completed_on')->label(__('admin.inventory.serialized_unit.fields.last_completed'))->date()->placeholder(__('—')),
                                TextEntry::make('completed_count')->label(__('admin.inventory.serialized_unit.fields.completed')),
                                TextEntry::make('missed_count')->label(__('admin.inventory.serialized_unit.fields.missed'))->badge()->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }

    private static function currentEntitlement(SerializedInventoryUnit $record): ?WarrantyEntitlement
    {
        $entitlement = $record->warrantyEntitlements()->first();

        return $entitlement instanceof WarrantyEntitlement ? $entitlement : null;
    }

    private static function legacyWarrantyState(SerializedInventoryUnit $record): string
    {
        if ($record->warranty_expires_on === null) {
            return 'No entitlement / needs verification';
        }

        return today()->lte($record->warranty_expires_on) ? 'Active (legacy)' : 'Expired (legacy)';
    }

    private static function coverageRules(SerializedInventoryUnit $record): string
    {
        $entitlement = self::currentEntitlement($record);

        if (! $entitlement instanceof WarrantyEntitlement) {
            return $record->warranty_expires_on !== null
                ? 'Legacy warranty: parts and labour require claim assessment.'
                : 'No warranty coverage rules are available.';
        }

        $covered = collect([
            'Parts' => $entitlement->covers_parts,
            'Labour' => $entitlement->covers_labour,
            'Travel' => $entitlement->covers_travel,
            'Consumables' => $entitlement->covers_consumables,
            'Third-party services' => $entitlement->covers_third_party,
        ])->filter()->keys()->implode(', ');

        return $covered !== '' ? $covered : 'No default charge categories are covered.';
    }
}
