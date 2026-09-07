<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Schemas;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
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
                    TextEntry::make('serial_number')->label('Serial'),
                    TextEntry::make('iot_number')->label('IoT')->placeholder('—'),
                    TextEntry::make('productVariant.sku')->label('SKU'),
                    TextEntry::make('productVariant.product.name')->label('Product'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('stock_condition')->label('Condition')->badge(),
                    TextEntry::make('custody_type')->label('Custody')->badge(),
                    TextEntry::make('custody_reference_type')->label('Custody reference')->placeholder('—'),
                    TextEntry::make('custody_reference_id')->label('Custody reference ID')->placeholder('—'),
                    TextEntry::make('warehouse.code')->label('Current warehouse')->placeholder('—'),
                    TextEntry::make('receipt_source')
                        ->label('Receipt source')
                        ->state(fn (SerializedInventoryUnit $record): ?string => app(SerializedInventoryTimelineService::class)->receiptSource($record))
                        ->placeholder('—'),
                ]),
                Section::make('Movement history')->schema([
                    RepeatableEntry::make('timeline')
                        ->state(fn (SerializedInventoryUnit $record): array => app(SerializedInventoryTimelineService::class)->events($record))
                        ->schema([
                            TextEntry::make('occurred_at')->label('Date')->dateTime(),
                            TextEntry::make('type')->badge(),
                            TextEntry::make('warehouse')->placeholder('—'),
                            TextEntry::make('transaction_quantity')->label('Transaction quantity')->placeholder('—'),
                            TextEntry::make('transaction_unit')->label('Unit')->placeholder('—'),
                            TextEntry::make('base_quantity_delta')->label('Base delta')->numeric(decimalPlaces: 6),
                            TextEntry::make('lot')->label('Lot')->placeholder('—'),
                            TextEntry::make('condition_from')->label('From condition')->badge()->placeholder('—'),
                            TextEntry::make('condition_to')->label('To condition')->badge()->placeholder('—'),
                            TextEntry::make('source')->placeholder('—'),
                            TextEntry::make('source_line')->label('Source line')->placeholder('—'),
                            TextEntry::make('reversal_of')->label('Reversal of movement')->placeholder('—'),
                            TextEntry::make('notes')->placeholder('—'),
                        ])
                        ->columns(3),
                ]),
                Section::make('Service history')
                    ->description("The equipment's maintenance jobs, cost, and billing outcome (WP-2.9, MT-08).")
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
                                TextEntry::make('id')->label('Job #'),
                                TextEntry::make('created_at')->label('Opened')->dateTime(),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('billing_type')->label('Billing')->badge(),
                                TextEntry::make('total_cost_minor')->label('Cost')->formatStateUsing(fn (int $state): string => number_format($state / 100, 2)),
                                TextEntry::make('coverage_percent')->label('Cost coverage')->suffix('%'),
                            ])
                            ->columns(3),
                    ]),
                Section::make('Preventive maintenance')
                    ->description('Recurring schedules for this equipment and their due/raised/completed/missed occurrences (WP-3.6, MT-07).')
                    ->schema([
                        RepeatableEntry::make('preventiveSchedules')
                            ->label('Schedules')
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
                                TextEntry::make('schedule_number')->label('Schedule #'),
                                TextEntry::make('name'),
                                TextEntry::make('is_active')->label('Active')->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')->badge(),
                                TextEntry::make('next_due_on')->label('Next due')->date(),
                                TextEntry::make('last_completed_on')->label('Last completed')->date()->placeholder('—'),
                                TextEntry::make('completed_count')->label('Completed'),
                                TextEntry::make('missed_count')->label('Missed')->badge()->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
