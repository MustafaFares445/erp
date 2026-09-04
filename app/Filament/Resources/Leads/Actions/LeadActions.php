<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Actions;

use App\Data\Crm\InteractionData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Enums\LeadDisqualificationReason;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\InteractionService;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\LeadService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

final class LeadActions
{
    public static function logInteraction(): Action
    {
        return Action::make('log_interaction')
            ->label('Log interaction')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->visible(fn (Lead $record): bool => ! $record->status->isTerminal())
            ->schema([
                Select::make('type')->options(self::enumOptions(InteractionType::cases()))->required(),
                Select::make('direction')->options(self::enumOptions(InteractionDirection::cases()))->required()->default(InteractionDirection::Outbound->value),
                Select::make('outcome')->options(self::enumOptions(InteractionOutcome::cases())),
                DateTimePicker::make('occurred_at')->default(now())->required(),
                TextInput::make('summary')->required()->maxLength(255),
                Textarea::make('notes')->rows(3),
                Select::make('next_status')
                    ->label('Advance stage after logging')
                    ->options([
                        LeadStatus::Contacted->value => 'Contacted',
                        LeadStatus::Qualified->value => 'Qualified',
                    ])
                    ->placeholder('Keep current stage'),
            ])
            ->action(function (Lead $record, array $data): void {
                $actor = self::actor();

                try {
                    $interaction = app(InteractionService::class)->log(new InteractionData(
                        subject: $record,
                        type: InteractionType::from((string) $data['type']),
                        direction: InteractionDirection::from((string) $data['direction']),
                        occurredAt: Carbon::parse((string) $data['occurred_at']),
                        summary: (string) $data['summary'],
                        outcome: filled($data['outcome'] ?? null) ? InteractionOutcome::from((string) $data['outcome']) : null,
                        notes: is_string($data['notes'] ?? null) ? $data['notes'] : null,
                    ), $actor);

                    if (filled($data['next_status'] ?? null)) {
                        app(LeadService::class)->transition($record->refresh(), LeadStatus::from((string) $data['next_status']), $interaction, $actor);
                    }
                } catch (Throwable $throwable) {
                    self::error($throwable);

                    return;
                }

                Notification::make()->success()->title('Interaction recorded')->send();
            });
    }

    public static function assign(): Action
    {
        return Action::make('assign')
            ->icon('heroicon-o-user')
            ->visible(fn (Lead $record): bool => ! $record->status->isTerminal() && (auth()->user()?->can('assign', $record) ?? false))
            ->schema([
                Select::make('assigned_to')->label('Assigned user')->relationship('assignee', 'name')->searchable()->preload(),
            ])
            ->action(function (Lead $record, array $data): void {
                $assignee = filled($data['assigned_to'] ?? null) ? User::query()->find((int) $data['assigned_to']) : null;
                app(LeadService::class)->assign($record, $assignee, self::actor());
                Notification::make()->success()->title('Lead assignment updated')->send();
            });
    }

    public static function disqualify(): Action
    {
        return Action::make('disqualify')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (Lead $record): bool => ! $record->status->isTerminal())
            ->schema([
                Select::make('reason')->options(self::enumOptions(LeadDisqualificationReason::cases()))->required(),
                Textarea::make('note')->rows(3),
            ])
            ->action(function (Lead $record, array $data): void {
                $latest = $record->interactions()->first();
                if ($latest === null) {
                    Notification::make()->danger()->title('Record an interaction before disqualifying the lead.')->send();

                    return;
                }

                try {
                    app(LeadService::class)->disqualify(
                        $record,
                        LeadDisqualificationReason::from((string) $data['reason']),
                        $latest,
                        self::actor(),
                        is_string($data['note'] ?? null) ? $data['note'] : null,
                    );
                } catch (Throwable $throwable) {
                    self::error($throwable);

                    return;
                }

                Notification::make()->success()->title('Lead disqualified')->send();
            });
    }

    public static function convert(): Action
    {
        return Action::make('convert')
            ->color('success')
            ->icon('heroicon-o-arrow-right-circle')
            ->visible(fn (Lead $record): bool => $record->status === LeadStatus::Qualified && (auth()->user()?->can('convert', $record) ?? false))
            ->schema([
                TextInput::make('name')->label('Login name')->required()->default(fn (Lead $record): string => $record->displayName()),
                TextInput::make('username')->required(),
                TextInput::make('email')->email()->required()->default(fn (Lead $record): ?string => $record->email),
                TextInput::make('password')->password()->required()->minLength(8),
                TextInput::make('company_name')->required()->default(fn (Lead $record): ?string => $record->company_name),
                TextInput::make('company_email')->email()->required()->default(fn (Lead $record): ?string => $record->email),
                TextInput::make('company_phone')->required()->default(fn (Lead $record): ?string => $record->phone),
                TextInput::make('country')->required(),
                TextInput::make('city')->required(),
                TextInput::make('address'),
                TextInput::make('latitude')->numeric()->required(),
                TextInput::make('longitude')->numeric()->required(),
                Toggle::make('contact_is_self')->default(true),
                TextInput::make('contact_name'),
                TextInput::make('contact_phone'),
                TextInput::make('contact_email')->email(),
            ])
            ->action(function (Lead $record, array $data): void {
                try {
                    $customer = app(LeadConversionService::class)->convert($record, $data, self::actor());
                } catch (Throwable $throwable) {
                    self::error($throwable);

                    return;
                }

                Notification::make()->success()->title('Lead converted')->body('Customer '.$customer->customer_code.' created.')->send();
            });
    }

    /** @param array<int, \BackedEnum> $cases @return array<string, string> */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn (\BackedEnum $case): array => [(string) $case->value => str((string) $case->value)->replace('_', ' ')->headline()->toString()])->all();
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated CRM user is required.');
        }

        return $actor;
    }

    private static function error(Throwable $throwable): void
    {
        Notification::make()->danger()->title('CRM action failed')->body($throwable->getMessage())->send();
    }
}
