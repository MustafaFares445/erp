<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Data\Crm\InteractionData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Models\User;
use App\Services\Crm\InteractionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use LogicException;

final class CustomerInteractionsRelationManager extends RelationManager
{
    protected static string $relationship = 'interactions';
    protected static ?string $title = 'Interactions';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('direction')->badge(),
            TextColumn::make('summary')->wrap(),
            TextColumn::make('employee.name')->label('Employee'),
            TextColumn::make('outcome')->badge()->placeholder('—'),
        ])->headerActions([
            Action::make('log_interaction')
                ->label('Log interaction')
                ->schema([
                    Select::make('type')->options(collect(InteractionType::cases())->mapWithKeys(fn (InteractionType $v): array => [$v->value => str($v->value)->replace('_', ' ')->headline()->toString()])->all())->required(),
                    Select::make('direction')->options(collect(InteractionDirection::cases())->mapWithKeys(fn (InteractionDirection $v): array => [$v->value => str($v->value)->headline()->toString()])->all())->default('outbound')->required(),
                    Select::make('outcome')->options(collect(InteractionOutcome::cases())->mapWithKeys(fn (InteractionOutcome $v): array => [$v->value => str($v->value)->replace('_', ' ')->headline()->toString()])->all()),
                    DateTimePicker::make('occurred_at')->default(now())->required(),
                    TextInput::make('summary')->required()->maxLength(255),
                    Textarea::make('notes')->rows(3),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) { throw new LogicException('An authenticated CRM user is required.'); }
                    app(InteractionService::class)->log(new InteractionData(
                        subject: $this->getOwnerRecord(),
                        type: InteractionType::from((string) $data['type']),
                        direction: InteractionDirection::from((string) $data['direction']),
                        occurredAt: Carbon::parse((string) $data['occurred_at']),
                        summary: (string) $data['summary'],
                        outcome: filled($data['outcome'] ?? null) ? InteractionOutcome::from((string) $data['outcome']) : null,
                        notes: is_string($data['notes'] ?? null) ? $data['notes'] : null,
                    ), $actor);
                    Notification::make()->success()->title('Customer interaction recorded')->send();
                }),
        ]);
    }
}
