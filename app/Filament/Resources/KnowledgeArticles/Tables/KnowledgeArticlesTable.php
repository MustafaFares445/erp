<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticles\Tables;

use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class KnowledgeArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('category.name')->label(__('Category'))->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (KnowledgeArticleStatus $state): string => $state->label())
                    ->color(static fn (KnowledgeArticleStatus $state): string => $state->color()),
                TextColumn::make('visibility')
                    ->badge()
                    ->formatStateUsing(static fn (KnowledgeArticleVisibility $state): string => $state->label()),
                TextColumn::make('locale')->label(__('Language'))->badge(),
                TextColumn::make('published_at')->dateTime()->placeholder(__('Not published'))->sortable(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(KnowledgeArticleStatus::cases())
                        ->mapWithKeys(static fn (KnowledgeArticleStatus $case): array => [$case->value => $case->label()])
                        ->all()),
                SelectFilter::make('visibility')
                    ->options(collect(KnowledgeArticleVisibility::cases())
                        ->mapWithKeys(static fn (KnowledgeArticleVisibility $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->recordActions([EditAction::make()]);
    }
}
