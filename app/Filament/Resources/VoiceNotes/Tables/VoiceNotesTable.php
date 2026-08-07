<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoiceNotes\Tables;

use App\Enums\VoiceNoteStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class VoiceNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('employee.user.name')->label('Employee')->searchable()->sortable(),
                TextColumn::make('customerVisit.customer.company_name')->label('Visit customer')->placeholder('—'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('transcription.status')->label('Transcription')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_column(VoiceNoteStatus::cases(), 'value', 'value')),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make(),
            ]);
    }
}
