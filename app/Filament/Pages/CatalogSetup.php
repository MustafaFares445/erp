<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\InventoryPermission;
use App\Models\Brand;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

/**
 * Four low-frequency reference-data tables (Categories, Brands, Attributes,
 * Units) merged into one navigation destination. Each was previously its own
 * top-level "Manage*" resource with an identical shape (one page, no
 * separate create/edit routes); this page reproduces each one's exact
 * form/table body under a tab, so no capability is lost — see
 * specs/012-inventory-module-consolidation/spec.md's Catalog setup entry.
 *
 * Not a Filament Resource: a Resource is bound to one Eloquent model, and
 * this page hosts four unrelated ones. Built on the same
 * `HasTable`/`InteractsWithTable` contract Filament's own `TableWidget` and
 * resource `ListRecords` pages use, so the table itself (search, sort,
 * filters, actions, pagination) stays fully native.
 */
final class CatalogSetup extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    #[Url]
    public string $tab = 'categories';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.catalog_setup');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.catalog_setup');
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(InventoryPermission::CatalogView->value) ?? false;
    }

    /** @return array<string, array{label: string, icon: Heroicon}> */
    public static function tabs(): array
    {
        return [
            'categories' => ['label' => __('admin.resources.categories'), 'icon' => Heroicon::OutlinedRectangleGroup],
            'brands' => ['label' => __('admin.resources.brands'), 'icon' => Heroicon::OutlinedBuildingStorefront],
            'attributes' => ['label' => __('admin.resources.product_attributes'), 'icon' => Heroicon::OutlinedAdjustmentsHorizontal],
            'units' => ['label' => __('admin.resources.units'), 'icon' => Heroicon::OutlinedScale],
        ];
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, self::tabs())) {
            return;
        }

        $this->tab = $tab;
        $this->resetTable();
        $this->cachedHeaderActions = [];
        $this->cacheInteractsWithHeaderActions();
    }

    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.partials.tab-bar')->viewData([
                'tabs' => self::tabs(),
                'active' => $this->tab,
            ]),
            EmbeddedTable::make(),
        ]);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return match ($this->tab) {
            'brands' => [
                CreateAction::make()
                    ->model(Brand::class)
                    ->schema(fn (Schema $schema): Schema => $this->brandForm($schema)),
            ],
            'attributes' => [
                CreateAction::make()
                    ->model(ProductAttribute::class)
                    ->schema(fn (Schema $schema): Schema => $this->attributeForm($schema)),
            ],
            'units' => [
                CreateAction::make()
                    ->model(Unit::class)
                    ->schema(fn (Schema $schema): Schema => $this->unitForm($schema)),
            ],
            default => [
                CreateAction::make()
                    ->model(ProductCategory::class)
                    ->schema(fn (Schema $schema): Schema => $this->categoryForm($schema)),
            ],
        };
    }

    public function table(Table $table): Table
    {
        return match ($this->tab) {
            'brands' => $this->brandsTable($table),
            'attributes' => $this->attributesTable($table),
            'units' => $this->unitsTable($table),
            default => $this->categoriesTable($table),
        };
    }

    private function categoriesTable(Table $table): Table
    {
        return $table
            ->query(ProductCategory::query())
            ->emptyStateDescription($this->emptyStateDescription())
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('name_ar')->label('Arabic name')->searchable(),
                TextColumn::make('parent.name')->label('Parent')->searchable()->sortable(),
                ToggleColumn::make('is_active'),
            ])
            ->filters([TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([
                EditAction::make()->schema(fn (Schema $schema): Schema => $this->categoryForm($schema)),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    private function categoryForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_id')->relationship('parent', 'name')->searchable()->preload()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Choose a parent only when this category belongs under another category.'),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            Toggle::make('is_active')->default(true)
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Inactive reference data remains in history but cannot be selected for new records.'),
        ]);
    }

    private function brandsTable(Table $table): Table
    {
        return $table
            ->query(Brand::query())
            ->emptyStateDescription($this->emptyStateDescription())
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('name_ar')->label('Arabic name')->searchable(),
                ToggleColumn::make('is_active'),
            ])
            ->filters([TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([
                EditAction::make()->schema(fn (Schema $schema): Schema => $this->brandForm($schema)),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    private function brandForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            TextInput::make('code')->required()->maxLength(50)->unique('brands', 'code', ignoreRecord: true),
            Toggle::make('is_active')->default(true),
        ]);
    }

    private function attributesTable(Table $table): Table
    {
        return $table
            ->query(ProductAttribute::query())
            ->emptyStateDescription($this->emptyStateDescription())
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('data_type')->badge(),
                TextColumn::make('values_count')->counts('values'),
                ToggleColumn::make('is_active'),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([
                EditAction::make()->schema(fn (Schema $schema): Schema => $this->attributeForm($schema)),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    private function attributeForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            TextInput::make('code')->required()->maxLength(100)->unique('product_attributes', 'code', ignoreRecord: true),
            Select::make('data_type')->options(['select' => 'Select', 'text' => 'Text'])->default('select')->required()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Use Select when users should choose from predefined values; use Text for a free-form value.'),
            Toggle::make('is_active')->default(true),
            Repeater::make('values')
                ->relationship()
                ->schema([
                    TextInput::make('value')->required()->maxLength(255),
                    TextInput::make('value_ar')->label('Arabic value')->maxLength(255),
                    Toggle::make('is_active')->default(true),
                ])
                ->columnSpanFull(),
        ]);
    }

    private function unitsTable(Table $table): Table
    {
        return $table
            ->query(Unit::query())
            ->emptyStateDescription($this->emptyStateDescription())
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('name_ar')->label('Arabic name')->searchable(),
                TextColumn::make('symbol')->searchable()->sortable(),
                IconColumn::make('allows_decimal')->boolean(),
                ToggleColumn::make('is_active'),
            ])
            ->filters([TernaryFilter::make('allows_decimal'), TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([
                EditAction::make()->schema(fn (Schema $schema): Schema => $this->unitForm($schema)),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    private function unitForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            TextInput::make('symbol')->required()->maxLength(20)->unique('units', 'symbol', ignoreRecord: true),
            Toggle::make('allows_decimal')
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Enable this only when quantities in this unit may include fractions, such as 0.5.'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    private function emptyStateDescription(): string
    {
        return match ($this->tab) {
            'brands' => 'Add brands so products can be identified by their manufacturer.',
            'attributes' => 'Add attributes so product variants can capture structured specifications.',
            'units' => 'Add units so inventory quantities are recorded consistently.',
            default => 'Add categories so products can be grouped for faster browsing and reporting.',
        };
    }
}
