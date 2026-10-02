<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomFieldDefinitions;

use App\Enums\CustomFieldDataType;
use App\Enums\CustomFieldEntityType;
use App\Enums\SystemPermission;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\CustomFieldDefinitions\Pages\ManageCustomFieldDefinitions;
use App\Models\CustomFieldDefinition;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

final class CustomFieldDefinitionResource extends Resource
{
    protected static ?string $model = CustomFieldDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static bool $shouldRegisterNavigation = false;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('Custom fields');
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::CustomFieldManage->value) ?? false;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('entity_type')
                ->label(__('Record type'))
                ->options(collect(CustomFieldEntityType::cases())->mapWithKeys(fn (CustomFieldEntityType $type): array => [$type->value => $type->label()])->all())
                ->required()
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record?->values()->exists() ?? false),
            TextInput::make('name')->required()->maxLength(160),
            TextInput::make('code')
                ->required()
                ->maxLength(80)
                ->regex('/^[a-z0-9][a-z0-9_-]*$/')
                ->unique(
                    table: CustomFieldDefinition::class,
                    column: 'code',
                    ignoreRecord: true,
                    modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                        $entityType = $get('entity_type');

                        return $rule->where('entity_type', is_string($entityType) ? $entityType : '');
                    },
                )
                ->helperText(__('Stable API-style key, for example installation_zone.')),
            Select::make('data_type')
                ->label(__('Data type'))
                ->options(collect(CustomFieldDataType::cases())->mapWithKeys(fn (CustomFieldDataType $type): array => [$type->value => $type->label()])->all())
                ->required()
                ->live()
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record?->values()->exists() ?? false),
            TagsInput::make('options')
                ->label(__('Options'))
                ->visible(fn (Get $get): bool => $get('data_type') === CustomFieldDataType::Select->value)
                ->required(fn (Get $get): bool => $get('data_type') === CustomFieldDataType::Select->value)
                ->helperText(__('Allowed values for Select fields. Values already in use cannot be removed.')),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->minValue(0)->default(0),
            Toggle::make('is_required')->default(false),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('entity_type')->label(__('Record type'))->badge()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->copyable(),
                TextColumn::make('data_type')->label(__('Data type'))->badge(),
                IconColumn::make('is_required')->label(__('Required'))->boolean(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('values_count')->counts('values')->label(__('Values'))->badge(),
                TextColumn::make('sort_order')->label(__('Order'))->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('entity_type')->options(collect(CustomFieldEntityType::cases())->mapWithKeys(fn (CustomFieldEntityType $type): array => [$type->value => $type->label()])->all()),
                SelectFilter::make('data_type')->options(collect(CustomFieldDataType::cases())->mapWithKeys(fn (CustomFieldDataType $type): array => [$type->value => $type->label()])->all()),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (CustomFieldDefinition $record): bool => $record->values()->doesntExist()),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageCustomFieldDefinitions::route('/')];
    }
}
