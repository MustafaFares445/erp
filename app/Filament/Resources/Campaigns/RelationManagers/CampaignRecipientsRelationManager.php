<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\RelationManagers;

use App\Enums\CampaignResponseType;
use App\Filament\Tables\TableFit;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use LogicException;

final class CampaignRecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Recipients');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return TableFit::apply($table)->columns([
            TextColumn::make('recipient_type')
                ->label(__('Recipient type'))
                ->formatStateUsing(static fn (string $state): string => match (class_basename($state)) {
                    'Lead' => __('Lead'),
                    'CustomerProfile' => __('Customer'),
                    default => class_basename($state),
                }),
            TextColumn::make('recipient_id')->toggleable(isToggledHiddenByDefault: true)->label(__('Recipient ID')),
            TextColumn::make('email')->placeholder(__('—')),
            TextColumn::make('phone')
                ->formatStateUsing(static fn (string $state): HtmlString => new HtmlString('<bdi dir="ltr">'.e($state).'</bdi>'))
                ->placeholder(__('—')),
            TextColumn::make('send_status')->badge(),
            TextColumn::make('sent_at')->dateTime()->placeholder(__('—')),
            TextColumn::make('send_error')
                ->wrap()
                ->formatStateUsing(static function (string $state): string {
                    // Known delivery failures are stored as English sentences; translate them when a
                    // JSON key exists and leave provider/exception text untouched otherwise.
                    $translated = __($state);

                    return is_string($translated) ? $translated : $state;
                })
                ->placeholder(__('—')),
        ])->recordActions(TableFit::rowActions([
            Action::make('record_response')
                ->label(__('Response'))
                ->visible(fn (CampaignRecipient $record): bool => auth()->user()?->can('update', $record->campaign) ?? false)
                ->schema([
                    Select::make('type')->options(collect(CampaignResponseType::cases())->mapWithKeys(fn (CampaignResponseType $type): array => [$type->value => $type->label()])->all())->required(),
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
        ]));
    }
}
