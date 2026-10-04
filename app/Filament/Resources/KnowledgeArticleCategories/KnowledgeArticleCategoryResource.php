<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleCategories;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\KnowledgeArticleCategories\Pages\CreateKnowledgeArticleCategory;
use App\Filament\Resources\KnowledgeArticleCategories\Pages\EditKnowledgeArticleCategory;
use App\Filament\Resources\KnowledgeArticleCategories\Pages\ListKnowledgeArticleCategories;
use App\Models\KnowledgeArticleCategory;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class KnowledgeArticleCategoryResource extends Resource
{
    use RequiresSupportFeature;

    protected static function supportFeatureFlag(): string
    {
        return 'support.knowledge_base_enabled';
    }

    protected static ?string $model = KnowledgeArticleCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 713;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('support.knowledge_base_enabled', false)
            && parent::shouldRegisterNavigation();
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.knowledge_categories');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.knowledge_category');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.knowledge_categories');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Category'))->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
                Toggle::make('is_active')->default(true),
            ])->columns(2),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('slug')->searchable(),
            TextColumn::make('articles_count')->counts('articles')->label(__('Articles')),
            IconColumn::make('is_active')->boolean(),
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeArticleCategories::route('/'),
            'create' => CreateKnowledgeArticleCategory::route('/create'),
            'edit' => EditKnowledgeArticleCategory::route('/{record}/edit'),
        ];
    }
}
