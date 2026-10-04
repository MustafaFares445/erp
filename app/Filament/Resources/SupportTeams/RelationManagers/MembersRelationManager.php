<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportTeams\RelationManagers;

use App\Models\EmployeeProfile;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.user.name')->label(__('Employee')),
                TextColumn::make('capacity')->placeholder(__('Team default')),
                TextColumn::make('routing_weight')->label(__('Weight')),
                IconColumn::make('accepts_remote')->boolean()->label(__('Remote')),
                IconColumn::make('accepts_onsite')->boolean()->label(__('On-site')),
                IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->schema(self::schema()),
            ])
            ->recordActions([
                EditAction::make()->schema(self::schema()),
                DeleteAction::make(),
            ]);
    }

    /** @return list<Component> */
    private static function schema(): array
    {
        return [
            Select::make('employee_id')
                ->label(__('Employee'))
                ->options(fn (): array => EmployeeProfile::query()->where('is_active', true)->with('user')
                    ->get()->mapWithKeys(static fn (EmployeeProfile $employee): array => [
                        $employee->id => (string) ($employee->user->name ?? $employee->employee_code),
                    ])->all())
                ->searchable()->required(),
            TextInput::make('capacity')->numeric()->minValue(1)->nullable(),
            TextInput::make('routing_weight')->numeric()->minValue(1)->default(100)->required(),
            Toggle::make('accepts_remote')->label(__('Accepts remote support'))->default(true),
            Toggle::make('accepts_onsite')->label(__('Accepts on-site visits'))->default(true),
            Toggle::make('is_active')->default(true),
        ];
    }
}
