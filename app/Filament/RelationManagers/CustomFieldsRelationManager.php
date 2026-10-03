<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Enums\CustomFieldDataType;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Services\CustomFields\CustomFieldService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final class CustomFieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'customFieldValues';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('definition'))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('definition.name')->label(__('Custom field'))->sortable(),
                TextColumn::make('definition.data_type')->label(__('Data type'))->badge(),
                TextColumn::make('value')
                    ->label(__('Value'))
                    ->state(fn (CustomFieldValue $record): string => $record->displayValue())
                    ->wrap(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                Action::make('editCustomFields')
                    ->label(__('Edit custom fields'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn (): bool => $this->canUpdateOwner() && $this->definitions()->isNotEmpty())
                    ->fillForm(fn (): array => $this->currentState())
                    ->schema(fn (): array => $this->fieldSchema())
                    ->action(function (array $data): void {
                        $actor = $this->actor();
                        $values = [];
                        foreach ($data as $key => $value) {
                            if (is_string($key) && preg_match('/^field_(\d+)$/', $key, $matches) === 1) {
                                $values[(int) $matches[1]] = $value;
                            }
                        }

                        app(CustomFieldService::class)->sync($actor, $this->getOwnerRecord(), $values);
                        Notification::make()->success()->title(__('Custom fields updated'))->send();
                    }),
            ]);
    }

    /** @return array<string, mixed> */
    private function currentState(): array
    {
        $state = [];
        foreach (app(CustomFieldService::class)->valuesFor($this->getOwnerRecord()) as $value) {
            $definition = $value->definition;
            if (! $definition instanceof CustomFieldDefinition) {
                continue;
            }

            $state['field_'.$definition->id] = match ($definition->data_type) {
                CustomFieldDataType::Number => $value->value_number,
                CustomFieldDataType::Date => $value->value_date?->toDateString(),
                CustomFieldDataType::Boolean => $value->value_boolean,
                default => $value->value_text,
            };
        }

        return $state;
    }

    /** @return list<Field> */
    private function fieldSchema(): array
    {
        $fields = [];

        foreach ($this->definitions() as $definition) {
            $name = 'field_'.$definition->id;
            $field = match ($definition->data_type) {
                CustomFieldDataType::Text => TextInput::make($name)->maxLength(10_000),
                CustomFieldDataType::LongText => Textarea::make($name)->rows(4)->maxLength(50_000),
                CustomFieldDataType::Number => TextInput::make($name)->numeric(),
                CustomFieldDataType::Date => DatePicker::make($name),
                CustomFieldDataType::Boolean => Toggle::make($name),
                CustomFieldDataType::Select => Select::make($name)->options($this->selectOptions($definition)),
            };

            $field->label($definition->name)
                ->required($definition->is_required)
                ->helperText($definition->description);

            $fields[] = $field;
        }

        return $fields;
    }

    /** @return array<string, string> */
    private function selectOptions(CustomFieldDefinition $definition): array
    {
        $options = $definition->optionValues();

        return array_combine($options, $options);
    }

    /** @return Collection<int, CustomFieldDefinition> */
    private function definitions(): Collection
    {
        return app(CustomFieldService::class)->definitionsFor($this->getOwnerRecord());
    }

    private function canUpdateOwner(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('update', $this->getOwnerRecord());
    }

    private function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated user is required to edit custom fields.');
        }

        return $actor;
    }
}
