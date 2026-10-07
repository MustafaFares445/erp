<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\InventoryPermission;
use App\Models\Brand;
use App\Models\Manufacturer;
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
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Low-frequency reference-data tables (Categories, Manufacturers, Brands, Attributes,
 * Units) merged into one navigation destination. Each was previously its own
 * top-level "Manage*" resource with an identical shape (one page, no
 * separate create/edit routes); this page reproduces each one's exact
 * form/table body under a tab, so no capability is lost — see
 * Docs/domains/catalog/README.md's Catalog setup entry.
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
            'manufacturers' => ['label' => __('Manufacturers'), 'icon' => Heroicon::OutlinedBuildingOffice2],
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
            'manufacturers' => [
                CreateAction::make()
                    ->model(Manufacturer::class)
                    ->schema(fn (Schema $schema): Schema => $this->manufacturerForm($schema)),
            ],
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
            'manufacturers' => $this->manufacturersTable($table),
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
                TextColumn::make('name_ar')->label(__('Arabic name'))->searchable(),
                TextColumn::make('parent.name')->label(__('Parent'))->searchable()->sortable(),
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
            TextInput::make('name_ar')->label(__('Arabic name'))->maxLength(255),
            Toggle::make('is_active')->default(true)
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Inactive reference data remains in history but cannot be selected for new records.'),
        ]);
    }

    private function manufacturersTable(Table $table): Table
    {
        return $table
            ->query(Manufacturer::query())
            ->emptyStateDescription('Add manufacturers so products can keep a reusable maker identity separate from commercial brands.')
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('country_code')->label(__('Country'))->searchable()->sortable(),
                TextColumn::make('website')->url(static fn (?string $state): ?string => $state)->openUrlInNewTab()->limit(40),
                TextColumn::make('brands_count')->counts('brands')->label(__('Brands')),
                ToggleColumn::make('is_active'),
            ])
            ->filters([TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([
                EditAction::make()->schema(fn (Schema $schema): Schema => $this->manufacturerForm($schema)),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    private function manufacturerForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(80)->unique('manufacturers', 'code', ignoreRecord: true),
            TextInput::make('country_code')->label(__('Country code'))->maxLength(2),
            TextInput::make('website')->url()->maxLength(500),
            Toggle::make('is_active')->default(true),
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
                TextColumn::make('name_ar')->label(__('Arabic name'))->searchable(),
                TextColumn::make('manufacturer.name')->label(__('Manufacturer'))->searchable()->sortable(),
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
            Select::make('manufacturer_id')
                ->relationship('manufacturer', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                ->searchable()
                ->preload(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label(__('Arabic name'))->maxLength(255),
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
            TextInput::make('name_ar')->label(__('Arabic name'))->maxLength(255),
            TextInput::make('code')->required()->maxLength(100)->unique('product_attributes', 'code', ignoreRecord: true),
            Select::make('data_type')->options(['select' => 'Select', 'text' => 'Text'])->default('select')->required()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Use Select when users should choose from predefined values; use Text for a free-form value.'),
            Toggle::make('is_active')->default(true),
            Repeater::make('values')
                ->relationship()
                ->schema([
                    TextInput::make('value')->required()->maxLength(255),
                    TextInput::make('value_ar')->label(__('Arabic value'))->maxLength(255),
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
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('name_ar')->label(__('Arabic name'))->searchable(),
                TextColumn::make('symbol')->searchable()->sortable(),
                TextColumn::make('family')->badge()->sortable(),
                TextColumn::make('precision')->numeric()->sortable(),
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
            TextInput::make('code')->maxLength(50)->unique('units', 'code', ignoreRecord: true)
                ->helperText('Optional stable code for integrations and imports. Existing unit creation remains valid without one.'),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label(__('Arabic name'))->maxLength(255),
            TextInput::make('symbol')->required()->maxLength(20)->unique('units', 'symbol', ignoreRecord: true),
            TextInput::make('family')->required()->maxLength(50)->default('count'),
            TextInput::make('precision')->numeric()->integer()->minValue(0)->maxValue(6)->default(0)->required(),
            Toggle::make('allows_decimal')
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Enable this only when quantities in this unit may include fractions, such as 0.5.'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    private function emptyStateDescription(): string
    {
        return match ($this->tab) {
            'manufacturers' => 'Add manufacturers so products and brands can share a reusable maker identity.',
            'brands' => 'Add commercial brands and optionally link each one to its manufacturer.',
            'attributes' => 'Add attributes so product variants can capture structured specifications.',
            'units' => 'Add units so inventory quantities are recorded consistently.',
            default => 'Add categories so products can be grouped for faster browsing and reporting.',
        };
    }
}
