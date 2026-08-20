<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChartOfAccounts\Schemas;

use App\Models\AccountType;
use App\Models\ChartAccount;
use App\Services\Accounting\ChartOfAccountService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class ChartAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.resources.chart_of_accounts'))
                    ->schema([
                        TextInput::make('code')
                            ->label(__('admin.accounting.fields.code'))
                            ->required()
                            ->maxLength(50)
                            // Trashed rows count, which is Filament's default here
                            // because it never adds `withoutTrashed()`: reusing a
                            // soft-deleted account's code would make historical
                            // lines ambiguous if that row were ever restored
                            // (data-model.md C-1).
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label(__('admin.accounting.fields.account_name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('account_type_id')
                            ->label(__('admin.accounting.fields.account_type'))
                            ->options(self::accountTypeOptions(...))
                            ->required()
                            ->searchable(),
                        Select::make('parent_id')
                            ->label(__('admin.accounting.fields.parent'))
                            ->options(fn (?ChartAccount $record): array => self::parentOptions($record))
                            ->searchable()
                            ->placeholder('—'),
                        Toggle::make('is_postable')
                            ->label(__('admin.accounting.fields.is_postable'))
                            ->default(true)
                            ->helperText(__('admin.accounting.hints.is_postable'))
                            ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.accounting.hints.is_postable'))
                            ->disabled(fn (?ChartAccount $record): bool => $record?->children()->exists() ?? false),
                        Toggle::make('is_active')
                            ->label(__('admin.accounting.fields.is_active'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * The five seeded elements. Never empty in a working install, so no
     * placeholder is offered.
     *
     * @return array<int, string>
     */
    private static function accountTypeOptions(): array
    {
        $options = [];

        foreach (AccountType::query()->orderBy('id')->get() as $type) {
            $options[$type->id] = $type->name->label();
        }

        return $options;
    }

    /**
     * Every account except the one being edited and its own descendants.
     *
     * That is the same set {@see ChartOfAccountService}
     * refuses as a parent (FR-006) — offering them here would only produce a
     * validation error the user could have been spared.
     *
     * @return array<int, string>
     */
    private static function parentOptions(?ChartAccount $record): array
    {
        $query = ChartAccount::query()->orderBy('code');

        if ($record instanceof ChartAccount) {
            $query->whereNotIn('id', $record->selfAndDescendantIds());
        }

        $options = [];

        foreach ($query->get() as $account) {
            $options[$account->id] = $account->code.' — '.$account->name;
        }

        return $options;
    }
}
