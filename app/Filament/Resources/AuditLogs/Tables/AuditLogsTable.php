<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Support\AuditActionLabel;
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
                TextColumn::make('description')
                    ->label(__('admin.crm.fields.action'))
                    ->formatStateUsing(static fn (?string $state): string => AuditActionLabel::for($state))
                    ->searchable(query: static fn (Builder $query, string $search): Builder => AuditActionLabel::search($query, $search)),
                TextColumn::make('causer.name')->label(__('admin.crm.fields.actor'))->placeholder(__('admin.crm.placeholders.system'))->searchable(),
                TextColumn::make('source_channel')->label(__('admin.crm.fields.channel'))->badge(),
            ])
            ->filters([
                SelectFilter::make('causer_id')->label(__('admin.crm.fields.actor'))->relationship('causer', 'name')->searchable(),
                SelectFilter::make('description')
                    ->label(__('admin.crm.fields.action'))
                    ->options(static fn (): array => AuditActionLabel::options())
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
