<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\RelationManagers;

use App\Enums\CampaignResponseType;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Services\Crm\CampaignResponseService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class CampaignRecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('recipient_type')->formatStateUsing(fn (string $state): string => class_basename($state)),
            TextColumn::make('recipient_id')->label(__('Recipient ID')),
            TextColumn::make('email')->placeholder(__('—')),
            TextColumn::make('phone')->placeholder(__('—')),
            TextColumn::make('send_status')->badge(),
            TextColumn::make('sent_at')->dateTime()->placeholder(__('—')),
            TextColumn::make('send_error')->limit(50)->placeholder(__('—')),
        ])->recordActions([
            Action::make('record_response')
                ->label(__('Response'))
                ->visible(fn (CampaignRecipient $record): bool => auth()->user()?->can('update', $record->campaign) ?? false)
                ->schema([
                    Select::make('type')->options(collect(CampaignResponseType::cases())->mapWithKeys(fn (CampaignResponseType $type): array => [$type->value => __(str($type->value)->headline()->toString())])->all())->required(),
                    Textarea::make('notes')->rows(3),
                ])
                ->action(function (CampaignRecipient $record, array $data): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated CRM user is required.');
                    }

                    $type = $data['type'] ?? null;
                    if (! is_string($type)) {
                        throw new LogicException('A campaign response type is required.');
                    }

                    $notes = $data['notes'] ?? null;
                    app(CampaignResponseService::class)->record(
                        $record,
                        CampaignResponseType::from($type),
                        ['notes' => is_string($notes) ? $notes : null],
                        $actor,
                    );
                    Notification::make()->success()->title(__('Campaign response recorded'))->send();
                }),
        ]);
    }
}
