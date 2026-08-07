<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityDrafts\Tables;

use App\Enums\OpportunityDraftStatus;
use App\Models\SalesOpportunityDraft;
use App\Services\Employees\OpportunityReviewService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class OpportunityDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('summary')->limit(60)->searchable(),
                TextColumn::make('keywordRule.keyword')->label('Keyword')->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_column(OpportunityDraftStatus::cases(), 'value', 'value')),
            ])
            ->recordActions([
                ViewAction::make(),
                self::decisionAction('approve', 'Approve', 'success', Heroicon::OutlinedCheckCircle),
                self::decisionAction('reject', 'Reject', 'danger', Heroicon::OutlinedXCircle),
            ]);
    }

    private static function decisionAction(string $name, string $label, string $color, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->icon($icon)
            ->requiresConfirmation()
            ->authorize('review')
            ->visible(static fn (SalesOpportunityDraft $record): bool => $record->status === OpportunityDraftStatus::Draft)
            ->schema([
                Textarea::make('review_notes')->label('Notes')->rows(3),
            ])
            ->action(static function (SalesOpportunityDraft $record, array $data) use ($name): void {
                $notes = $data['review_notes'] ?? null;
                $notes = is_string($notes) ? $notes : null;

                $service = app(OpportunityReviewService::class);

                $name === 'approve' ? $service->approve($record, $notes) : $service->reject($record, $notes);
            });
    }
}
