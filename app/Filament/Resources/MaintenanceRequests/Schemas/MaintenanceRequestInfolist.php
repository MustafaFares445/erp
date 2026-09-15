<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Schemas;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Quotations\QuotationResource;
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
                Section::make('Overview')
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('source')
                            ->label('Source')
                            ->state(static fn (MaintenanceRecord $record): string => match (true) {
                                $record->ticket_id !== null => 'Ticket',
                                $record->scheduleOccurrence !== null => 'Preventive Schedule',
                                default => 'Manual',
                            })
                            ->badge(),
                        TextEntry::make('customer.company_name')->label('Customer'),
                        TextEntry::make('ticket.ticket_number')->label('Raised from ticket')->placeholder('Standalone'),
                        TextEntry::make('description')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Equipment & Warranty')
                    ->schema([
                        TextEntry::make('serializedInventoryUnit.productVariant.name')->label('Equipment')->placeholder('External / unlinked'),
                        TextEntry::make('serial_number')->label('Serial number')->placeholder('—'),
                        TextEntry::make('is_equipment_unlinked')
                            ->label('Equipment status')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'External / unlinked equipment' : 'Known equipment')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'warning' : 'success'),
                        TextEntry::make('warranty_status')->label('Warranty')->badge(),
                        TextEntry::make('warranty_expiry_date')->label('Warranty expiry')->date()->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Cost')
                    ->schema([
                        TextEntry::make('parts_cost')
                            ->label('Parts cost')
                            ->state(fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['parts_cost_minor'])),
                        TextEntry::make('labour_cost')
                            ->label('Labour cost')
                            ->state(fn (MaintenanceRecord $record): string => self::money(self::jobCost($record)['labour_cost_minor'])),
                        TextEntry::make('third_party_cost')
                            ->label('External service cost')
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
                Section::make('Billing')
                    ->schema([
                        TextEntry::make('billing_type')->label('Billing status')->badge(),
                        TextEntry::make('billed_at')->label('Billed at')->dateTime()->placeholder('—'),
                        TextEntry::make('quotation.id')
                            ->label('Quotation')
                            ->placeholder('—')
                            ->formatStateUsing(static fn (int|string|null $state): string => $state === null ? '—' : 'Quotation #'.$state)
                            ->url(static fn (MaintenanceRecord $record): ?string => $record->quotation_id === null
                                ? null
                                : QuotationResource::getUrl('view', ['record' => $record->quotation_id])),
                        TextEntry::make('invoice.id')
                            ->label('Invoice')
                            ->placeholder('—')
                            ->formatStateUsing(static fn (int|string|null $state): string => $state === null ? '—' : 'Invoice #'.$state)
                            ->url(static fn (MaintenanceRecord $record): ?string => $record->invoice_id === null
                                ? null
                                : InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
                        TextEntry::make('ticket.paymentLink.status')
                            ->label('Ticket payment')
                            ->badge()
                            ->placeholder('Not applicable'),
                    ])
                    ->columns(2),
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
