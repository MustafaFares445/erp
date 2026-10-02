<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketMessageService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('message')
            ->columns([
                TextColumn::make('sender.name')->label(__('From')),
                TextColumn::make('message')->wrap(),
                IconColumn::make('is_internal_note')->label(__('Internal note'))->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('post')
                    ->label(__('Post message'))
                    ->schema([
                        Textarea::make('message')->required()->rows(3),
                        Toggle::make('is_internal_note')->label(__('Internal note')),
                    ])
                    ->authorize(fn (): bool => $this->currentActor()->can('message', $this->ticket()))
                    ->action(function (array $data): void {
                        $message = $data['message'] ?? null;

                        if (is_string($message) && $message !== '') {
                            $this->post($message, (bool) ($data['is_internal_note'] ?? false));
                        }
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private function post(string $message, bool $isInternalNote): void
    {
        app(TicketMessageService::class)->post(
            $this->ticket(),
            $message,
            $isInternalNote,
            $this->currentActor(),
        );
    }

    private function ticket(): Ticket
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof Ticket) {
            throw new LogicException('Expected the owner record of MessagesRelationManager to be a Ticket.');
        }

        return $record;
    }

    private function currentActor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }
}
