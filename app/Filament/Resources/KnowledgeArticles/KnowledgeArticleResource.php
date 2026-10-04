<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticles;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\KnowledgeArticles\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticles\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticles\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticles\Schemas\KnowledgeArticleForm;
use App\Filament\Resources\KnowledgeArticles\Tables\KnowledgeArticlesTable;
use App\Models\KnowledgeArticle;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class KnowledgeArticleResource extends Resource
{
    use RequiresSupportFeature;

    protected static function supportFeatureFlag(): string
    {
        return 'support.knowledge_base_enabled';
    }

    protected static ?string $model = KnowledgeArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 712;

    protected static ?string $recordTitleAttribute = 'title';

    #[\Override]
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('support.knowledge_base_enabled', false)
            && parent::shouldRegisterNavigation();
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.knowledge_articles');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.knowledge_article');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.knowledge_articles');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return KnowledgeArticleForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return KnowledgeArticlesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeArticles::route('/'),
            'create' => CreateKnowledgeArticle::route('/create'),
            'edit' => EditKnowledgeArticle::route('/{record}/edit'),
        ];
    }
}
