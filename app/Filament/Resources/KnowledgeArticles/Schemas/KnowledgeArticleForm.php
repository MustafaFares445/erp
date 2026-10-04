<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticles\Schemas;

use App\Enums\KnowledgeArticleVisibility;
use App\Enums\TicketType;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class KnowledgeArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Article'))
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Select::make('category_id')
                        ->relationship('category', 'name', modifyQueryUsing: static fn (Builder $query): Builder => $query->where('is_active', true))
                        ->searchable()
                        ->preload(),
                    Select::make('visibility')
                        ->options(collect(KnowledgeArticleVisibility::cases())
                            ->mapWithKeys(static fn (KnowledgeArticleVisibility $case): array => [$case->value => $case->label()])
                            ->all())
                        ->default(KnowledgeArticleVisibility::Internal->value)
                        ->required(),
                    Select::make('locale')
                        ->label(__('Language'))
                        ->options(['en' => __('English'), 'ar' => __('Arabic')])
                        ->default('en')
                        ->required(),
                    Textarea::make('summary')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                    RichEditor::make('body')
                        ->required()
                        ->columnSpanFull(),
                ])
                ->columns(3),
            Section::make(__('Targeting'))
                ->description(__('Use product and ticket-type targeting to improve deterministic article suggestions.'))
                ->schema([
                    Select::make('productVariants')
                        ->relationship('productVariants', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->label(__('Products / variants')),
                    CheckboxList::make('ticket_types')
                        ->label(__('Ticket types'))
                        ->options(collect(TicketType::cases())
                            ->mapWithKeys(static fn (TicketType $case): array => [$case->value => $case->getLabel()])
                            ->all())
                        ->columns(2),
                ])
                ->columns(2),
        ]);
    }
}
