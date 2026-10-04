<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEntitlements\Tables;

use App\Enums\SupportEntitlementStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SupportEntitlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['customer:id,company_name', 'serviceLevel:id,name', 'serializedInventoryUnit:id,serial_number']))
            ->columns([
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable()->sortable(),
                TextColumn::make('serviceLevel.name')->label(__('Service level'))->badge(),
                TextColumn::make('serializedInventoryUnit.serial_number')->label(__('Equipment (serial number)'))
                    ->placeholder(__('Whole customer'))->searchable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(static fn (SupportEntitlementStatus $state): string => $state->label())
                    ->color(static fn (SupportEntitlementStatus $state): string => match ($state) {
                        SupportEntitlementStatus::Active => 'success',
                        SupportEntitlementStatus::Suspended => 'warning',
                        SupportEntitlementStatus::Expired, SupportEntitlementStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('starts_on')->label(__('Starts on'))->date()->sortable(),
                TextColumn::make('ends_on')->label(__('Ends on'))->date()->placeholder(__('Open-ended'))->sortable(),
                TextColumn::make('external_reference')->label(__('External reference'))->searchable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(
                    collect(SupportEntitlementStatus::cases())->mapWithKeys(
                        static fn (SupportEntitlementStatus $status): array => [$status->value => $status->label()],
                    )->all(),
                ),
                SelectFilter::make('support_service_level_id')->label(__('Service level'))
                    ->relationship('serviceLevel', 'name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
