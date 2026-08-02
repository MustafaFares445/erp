<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('action')->searchable(),
                TextColumn::make('entity_type')->label(__('admin.crm.fields.entity_type'))->toggleable(),
                TextColumn::make('entity_id')->label(__('admin.crm.fields.entity_id'))->sortable(),
                TextColumn::make('actor.name')->label(__('admin.crm.fields.actor'))->placeholder(__('admin.crm.placeholders.system'))->searchable(),
                TextColumn::make('source_channel')->label(__('admin.crm.fields.channel'))->badge(),
            ])
            ->filters([
                SelectFilter::make('actor_user_id')->label(__('admin.crm.fields.actor'))->relationship('actor', 'name')->searchable(),
                SelectFilter::make('entity_type')
                    ->label(__('admin.crm.fields.entity_type'))
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('entity_type')->pluck('entity_type', 'entity_type')->all())
                    ->searchable(),
                SelectFilter::make('action')
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all())
                    ->searchable(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.crm.fields.from')),
                        DatePicker::make('until')->label(__('admin.crm.fields.until')),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        if (is_string($data['from'] ?? null)) {
                            $query->whereDate('created_at', '>=', $data['from']);
                        }

                        if (is_string($data['until'] ?? null)) {
                            $query->whereDate('created_at', '<=', $data['until']);
                        }

                        return $query;
                    }),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
