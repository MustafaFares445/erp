<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
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
                TextColumn::make('description')->label(__('admin.crm.fields.action'))->searchable(),
                TextColumn::make('subject_type')->label(__('admin.crm.fields.entity_type'))->toggleable(),
                TextColumn::make('subject_id')->label(__('admin.crm.fields.entity_id'))->sortable(),
                TextColumn::make('causer.name')->label(__('admin.crm.fields.actor'))->placeholder(__('admin.crm.placeholders.system'))->searchable(),
                TextColumn::make('source_channel')->label(__('admin.crm.fields.channel'))->badge(),
            ])
            ->filters([
                SelectFilter::make('causer_id')->label(__('admin.crm.fields.actor'))->relationship('causer', 'name')->searchable(),
                SelectFilter::make('subject_type')
                    ->label(__('admin.crm.fields.entity_type'))
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('subject_type')->pluck('subject_type', 'subject_type')->all())
                    ->searchable(),
                Filter::make('subject_id')
                    ->schema([TextInput::make('value')->label(__('admin.crm.fields.entity_id'))->numeric()])
                    ->query(static function (Builder $query, array $data): Builder {
                        if (is_numeric($data['value'] ?? null)) {
                            $query->where('subject_id', (int) $data['value']);
                        }

                        return $query;
                    }),
                SelectFilter::make('description')
                    ->label(__('admin.crm.fields.action'))
                    ->options(fn (): array => AuditLog::query()->distinct()->orderBy('description')->pluck('description', 'description')->all())
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
