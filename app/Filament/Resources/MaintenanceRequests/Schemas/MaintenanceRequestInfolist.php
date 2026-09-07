<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Schemas;

use App\Models\MaintenanceRecord;
use App\Services\Support\MaintenanceCostService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MaintenanceRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('customer.company_name')->label('Customer'),
                        TextEntry::make('ticket.ticket_number')->label('Raised from ticket')->placeholder('Standalone'),
                        TextEntry::make('serial_number')->label('Serial number')->placeholder('—'),
                        TextEntry::make('serializedInventoryUnit.productVariant.name')->label('Equipment')->placeholder('Unlinked'),
                        TextEntry::make('is_equipment_unlinked')
                            ->label('Equipment status')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Unlinked equipment' : 'Linked or no serial entered')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                        TextEntry::make('warranty_status')->label('Warranty')->badge(),
                        TextEntry::make('warranty_expiry_date')->label('Warranty expiry')->date()->placeholder('—'),
                        TextEntry::make('billing_type')->label('Billing')->badge(),
                        TextEntry::make('description')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Job cost')
                    ->schema([
                        TextEntry::make('parts_cost')
                            ->label('Parts cost')
                            ->state(fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['parts_cost_minor'])),
                        TextEntry::make('labour_cost')
                            ->label('Labour cost')
                            ->state(fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['labour_cost_minor'])),
                        TextEntry::make('third_party_cost')
                            ->label('Third-party cost')
                            ->state(fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['third_party_cost_minor'])),
                        TextEntry::make('total_cost')
                            ->label('Total cost')
                            ->state(fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['total_cost_minor'])),
                        TextEntry::make('revenue')
                            ->label('Revenue')
                            ->state(fn (MaintenanceRecord $record): string => self::money(self::margin($record)['revenue_minor'])),
                        TextEntry::make('margin')
                            ->label('Margin')
                            ->state(fn (MaintenanceRecord $record): string => self::money(self::margin($record)['margin_minor'])),
                        TextEntry::make('coverage_percent')
                            ->label('Cost coverage')
                            ->state(fn (MaintenanceRecord $record): string => self::jobCost($record)['coverage_percent'].'%')
                            ->helperText(fn (MaintenanceRecord $record): ?string => self::jobCost($record)['coverage_percent'] < 100.0
                                ? 'One or more consumed parts has no known cost.'
                                : null),
                    ])
                    ->columns(3)
                    ->visible(fn (): bool => auth()->user()?->can('viewCost', MaintenanceRecord::class) ?? false),
            ]);
    }

    /** @return array{parts_cost_minor:int, labour_cost_minor:int, third_party_cost_minor:int, total_cost_minor:int, coverage_percent:float} */
    private static function jobCost(MaintenanceRecord $record): array
    {
        return app(MaintenanceCostService::class)->jobCost($record);
    }

    /** @return array{cost_minor:int, revenue_minor:int, margin_minor:int, billing_type:string} */
    private static function margin(MaintenanceRecord $record): array
    {
        return app(MaintenanceCostService::class)->marginFor($record);
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
