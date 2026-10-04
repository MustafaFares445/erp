<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportTeams\Schemas;

use App\Enums\SupportAssignmentStrategy;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SupportTeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Team'))
                ->schema([
                    TextInput::make('code')->required()->maxLength(60)->unique(ignoreRecord: true),
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('assignment_strategy')
                        ->options(collect(SupportAssignmentStrategy::cases())->mapWithKeys(
                            static fn (SupportAssignmentStrategy $strategy): array => [$strategy->value => $strategy->label()],
                        )->all())
                        ->required()
                        ->default(SupportAssignmentStrategy::Manual->value),
                    TextInput::make('default_capacity')->numeric()->minValue(1)->nullable(),
                    Select::make('manager_user_id')
                        ->label(__('Team manager'))
                        ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->nullable(),
                    Toggle::make('is_active')->default(true),
                    Textarea::make('description')->rows(3)->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
