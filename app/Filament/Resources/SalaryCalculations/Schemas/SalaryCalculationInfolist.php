<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalaryCalculations\Schemas;

use App\Enums\SalaryCalculationStatus;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Models\EmployeeSalaryCalculation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SalaryCalculationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('pending_confirmation_banner')
                ->label('')
                ->hiddenLabel()
                ->state(__('This calculation is pending confirmation and does not yet take effect.'))
                ->color('warning')
                ->visible(static fn (EmployeeSalaryCalculation $record): bool => $record->status === SalaryCalculationStatus::PendingConfirmation),
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('employee.user.name')->label(__('Employee')),
                    TextEntry::make('salesPlan.name')->label(__('Plan')),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('payable_base')->money(),
                    TextEntry::make('employee.use_base_salary')
                        ->label(__('Base source'))
                        ->formatStateUsing(static fn (bool $state): string => $state ? 'Base salary' : 'Commission/target amount'),
                    TextEntry::make('performance_percent')->suffix('%'),
                    TextEntry::make('bonus_amount')->money(),
                    TextEntry::make('final_salary')->money(),
                ]),
            Section::make(__('Confirmation'))
                ->columns(2)
                ->schema([
                    TextEntry::make('confirmedBy.name')->label(__('Confirmed by'))->placeholder(__('Not yet confirmed')),
                    TextEntry::make('confirmed_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('superseded_at')->dateTime()->placeholder(__('—'))
                        ->visible(static fn (EmployeeSalaryCalculation $record): bool => $record->superseded_at !== null),
                    TextEntry::make('superseded_by_id')
                        ->label(__('Superseded by'))
                        ->formatStateUsing(static fn (): string => 'View replacement calculation')
                        ->url(static fn (EmployeeSalaryCalculation $record): ?string => $record->superseded_by_id !== null
                            ? SalaryCalculationResource::getUrl('view', ['record' => $record->superseded_by_id])
                            : null)
                        ->visible(static fn (EmployeeSalaryCalculation $record): bool => $record->superseded_by_id !== null),
                ]),
        ]);
    }
}
