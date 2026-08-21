<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Tables;

use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Services\Employees\VisitReviewService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class VisitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('employee.user.name')->label('Employee')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable()->placeholder('Not linked'),
                TextColumn::make('planTask.title')->label('Plan task')->searchable()->placeholder('Not linked'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('checked_in_at')->dateTime()->sortable(),
                TextColumn::make('checked_out_at')->dateTime()->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(static fn (CustomerVisit $record): ?string => $record->durationMinutes() !== null
                        ? $record->durationMinutes().' min'
                        : null)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_column(VisitStatus::cases(), 'value', 'value')),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('review')
                    ->label('Add / update review note')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->authorize('review')
                    ->fillForm(static fn (CustomerVisit $record): array => ['review_note' => $record->review_note])
                    ->schema([
                        Textarea::make('review_note')
                            ->label('Review note')
                            ->required()
                            ->rows(4),
                    ])
                    ->action(static function (CustomerVisit $record, array $data): void {
                        $note = $data['review_note'] ?? null;

                        app(VisitReviewService::class)->updateReviewNote($record, is_string($note) ? $note : '');
                    }),
            ]);
    }
}
