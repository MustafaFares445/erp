<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportQueues\Tables;

use App\Models\SupportQueue;
use App\Services\Support\SupportQueueQueryService;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SupportQueuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('team.name')->label(__('Team'))->placeholder(__('All teams')),
                TextColumn::make('matching_count')
                    ->label(__('Current tickets'))
                    ->state(static fn (SupportQueue $record): int => app(SupportQueueQueryService::class)->query($record)->count())
                    ->badge(),
                TextColumn::make('sort_order')->sortable(),
                IconColumn::make('is_system')->boolean()->label(__('System')),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
