<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Models\CollaborationEntry;
use App\Models\CollaborationFollower;
use App\Models\User;
use App\Services\Collaboration\CollaborationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CollaborationEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'collaborationEntries';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('author.name')->label(__('Author')),
                TextColumn::make('body')->wrap()->limit(120),
                TextColumn::make('assignee.name')->label(__('Assignee'))->placeholder('—'),
                TextColumn::make('due_at')->dateTime()->sortable()->placeholder('—'),
                IconColumn::make('completed_at')->label(__('Done'))->boolean(fn (?string $state): bool => filled($state)),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                $this->createEntryAction(),
                $this->followAction(),
                $this->unfollowAction(),
            ])
            ->recordActions([
                Action::make('complete')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (CollaborationEntry $record): bool => $record->type === 'activity' && $record->completed_at === null)
                    ->action(fn (CollaborationEntry $record): CollaborationEntry => app(CollaborationService::class)->complete($this->actor(), $record)),
            ]);
    }

    private function createEntryAction(): Action
    {
        return Action::make('addCollaborationEntry')
            ->label(__('Add note / activity'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->schema([
                Select::make('type')->options([
                    'comment' => __('Comment'),
                    'note' => __('Internal note'),
                    'activity' => __('Activity'),
                ])->default('comment')->required()->live(),
                Textarea::make('body')->required()->rows(4)->maxLength(5000),
                Select::make('assignee_id')
                    ->label(__('Assignee'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                DateTimePicker::make('due_at')->label(__('Due at')),
            ])
            ->action(function (array $data): void {
                $type = is_string($data['type'] ?? null) ? $data['type'] : 'comment';
                $body = is_string($data['body'] ?? null) ? $data['body'] : '';
                app(CollaborationService::class)->create($this->actor(), $this->subject(), [
                    'type' => $type,
                    'body' => $body,
                    'assignee_id' => is_numeric($data['assignee_id'] ?? null) ? (int) $data['assignee_id'] : null,
                    'due_at' => is_string($data['due_at'] ?? null) ? $data['due_at'] : null,
                    'visibility' => 'internal',
                ]);
            });
    }

    private function followAction(): Action
    {
        return Action::make('followRecord')
            ->label(__('Follow'))
            ->icon('heroicon-o-bell')
            ->visible(fn (): bool => ! $this->isFollowing())
            ->action(fn () => app(CollaborationService::class)->follow($this->actor(), $this->subject()));
    }

    private function unfollowAction(): Action
    {
        return Action::make('unfollowRecord')
            ->label(__('Unfollow'))
            ->icon('heroicon-o-bell-slash')
            ->visible(fn (): bool => $this->isFollowing())
            ->action(fn () => app(CollaborationService::class)->unfollow($this->actor(), $this->subject()));
    }

    private function isFollowing(): bool
    {
        return $this->subject()->morphMany(CollaborationFollower::class, 'subject')
            ->where('user_id', $this->actor()->getKey())
            ->exists();
    }

    private function subject(): Model
    {
        return $this->getOwnerRecord();
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required for collaboration.');
        }

        return $actor;
    }
}
